<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Retainer;

use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Models\Retainer;
use App\Service\Retainer\AllocationCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(AllocationCalculator::class)]
class AllocationCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private AllocationCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new AllocationCalculator;
    }

    /**
     * A couple of DST regression tests below temporarily change the PHP default
     * timezone (see their docblocks for why); always restore it so it can't leak into
     * other tests.
     */
    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
        parent::tearDown();
    }

    public function test_returns_zero_before_retainer_starts(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
            'ends_at' => null,
        ]);

        $this->assertSame(0, $this->calculator->computeAllocated($retainer, Carbon::parse('2026-05-15')));
    }

    public function test_calendar_monthly_first_day_of_first_period(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
        ]);

        $this->assertSame(0, $this->calculator->computeAllocated($retainer, Carbon::parse('2026-06-01')));
    }

    public function test_calendar_monthly_half_through_first_period(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-06-15'));
        $this->assertEqualsWithDelta(20 * 3600, $result, 3600);
    }

    public function test_calendar_monthly_completed_period_plus_partial(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-07-15'));
        $this->assertEqualsWithDelta(60 * 3600, $result, 3600);
    }

    public function test_calendar_monthly_retainer_starting_mid_period_only_counts_from_start(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-15'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-06-30'));
        $this->assertEqualsWithDelta(20 * 3600, $result, 3600);
    }

    public function test_calendar_weekly_one_full_week(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Weekly,
            'seconds_per_period' => 10 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-06-08'));
        $this->assertSame(10 * 3600, $result);
    }

    public function test_calendar_quarterly_completed_quarter(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Quarterly,
            'seconds_per_period' => 120 * 3600,
            'starts_at' => Carbon::parse('2026-01-01'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-04-01'));
        $this->assertSame(120 * 3600, $result);
    }

    public function test_anchor_monthly_anchored_to_mid_month(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Anchor,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'anchor_date' => Carbon::parse('2026-06-15'),
            'starts_at' => Carbon::parse('2026-06-15'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-07-15'));
        $this->assertSame(40 * 3600, $result);
    }

    public function test_explicit_mode_sums_completed_plus_partial(): void
    {
        $retainer = Retainer::factory()->create([
            'period_mode' => RetainerPeriodMode::Explicit,
            'period_unit' => null,
            'seconds_per_period' => null,
            'starts_at' => Carbon::parse('2026-01-01'),
        ]);
        $retainer->periods()->create([
            'starts_at' => '2026-01-01', 'ends_at' => '2026-01-31', 'seconds_allocated' => 40 * 3600,
        ]);
        $retainer->periods()->create([
            'starts_at' => '2026-02-01', 'ends_at' => '2026-02-28', 'seconds_allocated' => 60 * 3600,
        ]);
        $retainer->load('periods');

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-02-14'));
        $this->assertEqualsWithDelta(70 * 3600, $result, 3600);
    }

    /**
     * The DST regression tests below run with the PHP default timezone temporarily set
     * to a DST-observing zone. This app's own `config('app.timezone')` is hardcoded to
     * UTC (see config/app.php) and Eloquent's `date` cast round-trips through a plain
     * `Y-m-d` string, so under this app's *current* configuration a `Retainer`'s
     * `starts_at` always ends up UTC regardless of what timezone it was constructed
     * with — the DST bug the source implementation fixed cannot actually be reached
     * via that attribute today. It genuinely is reachable, however, via `$asOf`, which
     * every caller passes in directly (`RetainerController::status()` uses
     * `Carbon::now()`, `CapEnforcer` uses a time entry's `end`) — `Carbon::now()`
     * resolves against the PHP default timezone, which is a distinct, independently
     * configurable setting from `config('app.timezone')`. These tests simulate that by
     * setting the default timezone directly, restoring it in tearDown() so it can't
     * leak into other tests.
     */

    /**
     * US DST 2026 spring-forward is March 8 (clocks lose 1 hour at 02:00 local). A
     * calendar-monthly retainer that starts on the 1st and is queried fully-elapsed at
     * the 1st of the next month must get exactly the full period allocation, with zero
     * delta — not "close enough". This holds even though `daysBetween()` itself
     * under-counts the days in a DST-affected month (30 instead of 31, since one of
     * March's 31 days is only 23 hours long): the same under-count applies identically
     * to both the numerator and denominator of the proration ratio (both pair the
     * period's start with its end), so the ratio is still exactly 1.0.
     */
    public function test_calendar_monthly_fully_elapsed_period_spanning_us_dst_spring_forward_is_exact(): void
    {
        date_default_timezone_set('America/New_York');

        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-03-01'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-04-01'));
        $this->assertSame(40 * 3600, $result);
    }

    /**
     * The actual "phantom day" bug: querying a *partial* period with `asOf` captured
     * at a real wall-clock time (not midnight) — exactly what `Carbon::now()` gives in
     * production — on a day after a DST transition inside the period. Flooring `asOf`
     * to local midnight *before* converting to UTC (what `daysBetween()` does) discards
     * the wall-clock time-of-day before it can interact with the DST offset change.
     * Skipping that step (diffing the raw, un-floored instants directly) corrupts the
     * day count: for this exact scenario it silently adds a whole extra day, over-
     * allocating by 4800 seconds (80 minutes) — proven by direct calculation, not
     * asserted here since exercising the fixed algorithm's actual output is the point.
     */
    public function test_calendar_monthly_partial_period_with_wall_clock_as_of_after_us_dst_transition(): void
    {
        date_default_timezone_set('America/New_York');

        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-03-01'),
        ]);

        // 2026-03-08 02:00 local is the spring-forward instant; 2026-03-15 14:32 is a
        // realistic "now" a week later, well after the transition, with a non-midnight
        // wall-clock time.
        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-03-15 14:32:00'));
        $this->assertSame(67200, $result);
    }

    /**
     * Same scenario for the EU transition (2026-03-29, clocks spring forward at 02:00
     * CET), on a retainer queried a day after the transition with a wall-clock `asOf`.
     */
    public function test_calendar_monthly_partial_period_with_wall_clock_as_of_after_eu_dst_transition(): void
    {
        date_default_timezone_set('Europe/Berlin');

        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-03-01'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-03-30 10:15:00'));
        $this->assertSame(139200, $result);
    }

    public function test_clamps_to_ends_at(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
            'ends_at' => Carbon::parse('2026-07-31'),
        ]);

        $result = $this->calculator->computeAllocated($retainer, Carbon::parse('2026-12-31'));
        $this->assertEqualsWithDelta(80 * 3600, $result, 3600);
    }

    // ── currentPeriodBounds ──────────────────────────────────────────────────

    public function test_current_period_bounds_calendar_weekly(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Weekly,
            'seconds_per_period' => 10 * 3600,
            'starts_at' => Carbon::parse('2026-05-18'), // Monday
        ]);

        // Tuesday 2026-05-26 -> period should be Mon May 25 to Sun May 31
        $bounds = $this->calculator->currentPeriodBounds($retainer, Carbon::parse('2026-05-26'));
        $this->assertNotNull($bounds);
        $this->assertSame('2026-05-25', $bounds['starts_at']->toDateString());
        $this->assertSame('2026-05-31', $bounds['ends_at']->toDateString());
        $this->assertSame(10 * 3600, $bounds['seconds_allocated']);
    }

    public function test_current_period_bounds_calendar_monthly(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => Carbon::parse('2026-05-01'),
        ]);

        $bounds = $this->calculator->currentPeriodBounds($retainer, Carbon::parse('2026-05-26'));
        $this->assertNotNull($bounds);
        $this->assertSame('2026-05-01', $bounds['starts_at']->toDateString());
        $this->assertSame('2026-05-31', $bounds['ends_at']->toDateString());
        $this->assertSame(40 * 3600, $bounds['seconds_allocated']);
    }

    public function test_current_period_bounds_full_allocation_even_if_retainer_started_mid_period(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Weekly,
            'seconds_per_period' => 10 * 3600,
            'starts_at' => Carbon::parse('2026-05-20'), // Wednesday
        ]);

        // Same calendar week (May 18-24), but retainer started Wed. Per spec: full 10h
        // shown for "this week", proration only applies to the cumulative view.
        $bounds = $this->calculator->currentPeriodBounds($retainer, Carbon::parse('2026-05-22'));
        $this->assertNotNull($bounds);
        $this->assertSame(10 * 3600, $bounds['seconds_allocated']);
        $this->assertSame('2026-05-18', $bounds['starts_at']->toDateString());
        $this->assertSame('2026-05-24', $bounds['ends_at']->toDateString());
    }

    public function test_current_period_bounds_returns_null_before_starts_at(): void
    {
        $retainer = Retainer::factory()->make([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Weekly,
            'seconds_per_period' => 10 * 3600,
            'starts_at' => Carbon::parse('2026-06-01'),
        ]);
        $this->assertNull($this->calculator->currentPeriodBounds($retainer, Carbon::parse('2026-05-15')));
    }

    public function test_current_period_bounds_explicit_mode_returns_matching_row(): void
    {
        $retainer = Retainer::factory()->create([
            'period_mode' => RetainerPeriodMode::Explicit,
            'period_unit' => null,
            'seconds_per_period' => null,
            'starts_at' => Carbon::parse('2026-01-01'),
        ]);
        $retainer->periods()->create([
            'starts_at' => '2026-01-01', 'ends_at' => '2026-01-31', 'seconds_allocated' => 40 * 3600,
        ]);
        $retainer->periods()->create([
            'starts_at' => '2026-02-01', 'ends_at' => '2026-02-28', 'seconds_allocated' => 60 * 3600,
        ]);

        $bounds = $this->calculator->currentPeriodBounds($retainer, Carbon::parse('2026-02-14'));
        $this->assertNotNull($bounds);
        $this->assertSame('2026-02-01', $bounds['starts_at']->toDateString());
        $this->assertSame('2026-02-28', $bounds['ends_at']->toDateString());
        $this->assertSame(60 * 3600, $bounds['seconds_allocated']);
    }
}
