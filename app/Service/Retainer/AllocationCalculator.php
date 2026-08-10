<?php

declare(strict_types=1);

namespace App\Service\Retainer;

use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Models\Retainer;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Computes how many seconds of a retainer's budget have been allocated (become available
 * to spend) as of a given point in time, prorating the current in-progress period.
 *
 * All day-counting in this class goes through {@see self::daysBetween()}, which floors
 * both operands to local midnight *before* converting to UTC and diffing. Doing
 * `startOfDay()` before `->utc()` matters: fixing the wall-clock calendar-day boundary
 * before the timezone conversion prevents a DST transition inside the interval from
 * introducing a fractional "phantom" day into the ratio used for proration. Calling
 * `diffInDays()` directly on timezone-aware, wall-clock `Carbon` instances instead would
 * be off by a fractional day for any period spanning a DST boundary.
 */
class AllocationCalculator
{
    /**
     * Cumulative allocated seconds from the retainer's start up to (and including) $asOf.
     *
     * @throws InvalidArgumentException if a recurring retainer is missing period_unit or seconds_per_period
     */
    public function computeAllocated(Retainer $retainer, Carbon $asOf): int
    {
        $start = $retainer->starts_at->copy()->startOfDay();
        $asOf = $asOf->copy()->startOfDay();

        if ($asOf->lte($start)) {
            return 0;
        }

        if ($retainer->ends_at !== null && $asOf->gt($retainer->ends_at->copy()->startOfDay())) {
            $asOf = $retainer->ends_at->copy()->startOfDay();
        }

        return match ($retainer->period_mode) {
            RetainerPeriodMode::Calendar => $this->computeRecurring($retainer, $asOf, anchored: false),
            RetainerPeriodMode::Anchor => $this->computeRecurring($retainer, $asOf, anchored: true),
            RetainerPeriodMode::Explicit => $this->computeExplicit($retainer, $asOf),
        };
    }

    /**
     * The un-prorated bounds and full allocation of the period containing $asOf. Used
     * only for the "this period" display view, not for cap math. For calendar/anchor
     * modes the full period is returned even if the retainer started mid-period —
     * proration is only ever applied to the cumulative {@see self::computeAllocated()}.
     *
     * @return array{starts_at: Carbon, ends_at: Carbon, seconds_allocated: int}|null
     *
     * @throws InvalidArgumentException if a recurring retainer is missing period_unit or seconds_per_period
     */
    public function currentPeriodBounds(Retainer $retainer, Carbon $asOf): ?array
    {
        $asOfDay = $asOf->copy()->startOfDay();

        if ($asOfDay->lt($retainer->starts_at->copy()->startOfDay())) {
            return null;
        }

        if ($retainer->ends_at !== null && $asOfDay->gt($retainer->ends_at->copy()->startOfDay())) {
            return null;
        }

        return match ($retainer->period_mode) {
            RetainerPeriodMode::Calendar => $this->currentPeriodBoundsRecurring($retainer, $asOfDay, anchored: false),
            RetainerPeriodMode::Anchor => $this->currentPeriodBoundsRecurring($retainer, $asOfDay, anchored: true),
            RetainerPeriodMode::Explicit => $this->currentPeriodBoundsExplicit($retainer, $asOfDay),
        };
    }

    private function computeRecurring(Retainer $retainer, Carbon $asOf, bool $anchored): int
    {
        if ($retainer->period_unit === null || $retainer->seconds_per_period === null) {
            throw new InvalidArgumentException('Recurring retainer requires period_unit and seconds_per_period.');
        }

        $rate = $retainer->seconds_per_period;
        $cursor = $anchored
            ? $retainer->anchor_date->copy()->startOfDay()
            : $this->periodStartFor($retainer->starts_at, $retainer->period_unit);

        $effectiveStart = $retainer->starts_at->copy()->startOfDay();
        $allocated = 0;

        while (true) {
            $nextPeriodStart = $this->nextPeriodStartFor($cursor, $retainer->period_unit, $anchored);
            $effPeriodStart = $cursor->lt($effectiveStart) ? $effectiveStart->copy() : $cursor->copy();

            if ($nextPeriodStart->lte($asOf)) {
                // This period is fully complete — count every effective day in it.
                $fullDays = $this->daysBetween($cursor, $nextPeriodStart);
                $effDays = $this->daysBetween($effPeriodStart, $nextPeriodStart);
                if ($fullDays > 0) {
                    $allocated += (int) round(($effDays / $fullDays) * $rate);
                }
                $cursor = $nextPeriodStart->copy();

                continue;
            }

            // The final, partial (current) period.
            $fullDays = $this->daysBetween($cursor, $nextPeriodStart);
            $usedDays = $this->usedDays($effPeriodStart, $cursor, $asOf);
            if ($fullDays > 0) {
                $allocated += (int) round((max(0, $usedDays) / $fullDays) * $rate);
            }
            break;
        }

        return $allocated;
    }

    /**
     * Used days elapsed in the current (partial) period.
     *
     * When the retainer started exactly on this period's boundary (effPeriodStart ==
     * cursor), the asOf day itself is counted as a full elapsed day (+1, inclusive),
     * unless asOf is the boundary itself (no time has elapsed yet). When the retainer
     * started mid-period, only complete days from the retainer's start to asOf are
     * counted (exclusive), which keeps the delta accurate without double counting.
     */
    private function usedDays(Carbon $effPeriodStart, Carbon $cursor, Carbon $asOf): int
    {
        $days = $this->daysBetween($effPeriodStart, $asOf);

        if ($effPeriodStart->isSameDay($cursor) && $asOf->gt($effPeriodStart)) {
            $days += 1;
        }

        return $days;
    }

    private function computeExplicit(Retainer $retainer, Carbon $asOf): int
    {
        $periods = $retainer->periods()->orderBy('starts_at')->get();

        $allocated = 0;
        foreach ($periods as $period) {
            $pStart = $period->starts_at->copy()->startOfDay();
            // ends_at is inclusive; the next day is the exclusive boundary.
            $pNextStart = $period->ends_at->copy()->startOfDay()->addDay();

            if ($pNextStart->lte($asOf)) {
                $allocated += $period->seconds_allocated;

                continue;
            }
            if ($pStart->gte($asOf)) {
                break;
            }

            $fullDays = $this->daysBetween($pStart, $pNextStart);
            // Explicit periods always start exactly at pStart, so use inclusive counting.
            $usedDays = $this->daysBetween($pStart, $asOf) + 1;
            if ($fullDays > 0) {
                $allocated += (int) round(($usedDays / $fullDays) * $period->seconds_allocated);
            }
        }

        return $allocated;
    }

    /**
     * @return array{starts_at: Carbon, ends_at: Carbon, seconds_allocated: int}
     */
    private function currentPeriodBoundsRecurring(Retainer $retainer, Carbon $asOf, bool $anchored): array
    {
        if ($retainer->period_unit === null || $retainer->seconds_per_period === null) {
            throw new InvalidArgumentException('Recurring retainer requires period_unit and seconds_per_period.');
        }

        $periodStart = $anchored
            ? $this->anchorPeriodStartFor($retainer->anchor_date, $retainer->period_unit, $asOf)
            : $this->periodStartFor($asOf, $retainer->period_unit);

        $periodEnd = $this->periodEndFor($periodStart, $retainer->period_unit, $anchored);

        return [
            'starts_at' => $periodStart->copy()->startOfDay(),
            'ends_at' => $periodEnd->copy()->startOfDay(),
            'seconds_allocated' => $retainer->seconds_per_period,
        ];
    }

    /**
     * @return array{starts_at: Carbon, ends_at: Carbon, seconds_allocated: int}|null
     */
    private function currentPeriodBoundsExplicit(Retainer $retainer, Carbon $asOf): ?array
    {
        $periods = $retainer->periods()->orderBy('starts_at')->get();

        foreach ($periods as $period) {
            $pStart = $period->starts_at->copy()->startOfDay();
            $pEnd = $period->ends_at->copy()->startOfDay();

            if ($asOf->between($pStart, $pEnd)) {
                return [
                    'starts_at' => $pStart,
                    'ends_at' => $pEnd,
                    'seconds_allocated' => (int) $period->seconds_allocated,
                ];
            }
        }

        return null;
    }

    /**
     * Walk forward from anchor_date to find the anchor-period containing $asOf.
     */
    private function anchorPeriodStartFor(Carbon $anchorDate, RetainerPeriodUnit $unit, Carbon $asOf): Carbon
    {
        $cursor = $anchorDate->copy()->startOfDay();
        while ($cursor->lte($asOf)) {
            $next = $this->nextPeriodStartFor($cursor, $unit, anchored: true);
            if ($next->gt($asOf)) {
                break;
            }
            $cursor = $next;
        }

        return $cursor;
    }

    /**
     * The last day (inclusive) of the period starting at $periodStart.
     */
    private function periodEndFor(Carbon $periodStart, RetainerPeriodUnit $unit, bool $anchored): Carbon
    {
        return $this->nextPeriodStartFor($periodStart, $unit, $anchored)->copy()->subDay()->startOfDay();
    }

    private function periodStartFor(Carbon $date, RetainerPeriodUnit $unit): Carbon
    {
        return match ($unit) {
            RetainerPeriodUnit::Weekly => $date->copy()->startOfWeek(Carbon::MONDAY),
            RetainerPeriodUnit::Monthly => $date->copy()->startOfMonth(),
            RetainerPeriodUnit::Quarterly => $date->copy()->firstOfQuarter()->startOfDay(),
        };
    }

    private function nextPeriodStartFor(Carbon $cursor, RetainerPeriodUnit $unit, bool $anchored): Carbon
    {
        if ($anchored) {
            return match ($unit) {
                RetainerPeriodUnit::Weekly => $cursor->copy()->addWeek()->startOfDay(),
                RetainerPeriodUnit::Monthly => $cursor->copy()->addMonth()->startOfDay(),
                RetainerPeriodUnit::Quarterly => $cursor->copy()->addMonths(3)->startOfDay(),
            };
        }

        return match ($unit) {
            RetainerPeriodUnit::Weekly => $cursor->copy()->addWeek()->startOfDay(),
            RetainerPeriodUnit::Monthly => $cursor->copy()->endOfMonth()->addDay()->startOfDay(),
            RetainerPeriodUnit::Quarterly => $cursor->copy()->lastOfQuarter()->addDay()->startOfDay(),
        };
    }

    /**
     * DST-safe day count between two instants: floor both to local midnight *before*
     * converting to UTC, then diff. See the class docblock for why the ordering matters.
     */
    private function daysBetween(Carbon $a, Carbon $b): int
    {
        return (int) $a->copy()->startOfDay()->utc()->diffInDays($b->copy()->startOfDay()->utc(), absolute: true);
    }
}
