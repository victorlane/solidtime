<?php

declare(strict_types=1);

namespace App\Service\Retainer;

use App\Enums\RetainerHardCapEnforcement;
use App\Enums\RetainerHardCapScope;
use App\Enums\RetainerSubCapMode;
use App\Exceptions\Retainer\RetainerCapExceededException;
use App\Models\Project;
use App\Models\Retainer;
use Illuminate\Support\Carbon;

/**
 * Enforces a retainer's hard cap (and, in strict sub-cap mode, its per-project
 * sub-caps) against the net new seconds a time entry save would add.
 *
 * Deliberately queries {@see ConsumptionQuery} uncached, live: the write path always
 * needs the freshest tracked total to avoid blocking (or, worse, silently allowing)
 * a save based on a stale number.
 */
class CapEnforcer
{
    public function __construct(
        private readonly RetainerLookup $lookup,
        private readonly AllocationCalculator $allocation,
        private readonly ConsumptionQuery $consumption,
    ) {}

    /**
     * @param  string|null  $projectId  The project the time entry would be saved under.
     * @param  Carbon  $start  The time entry's (resulting) start.
     * @param  Carbon  $end  The time entry's (resulting) end.
     * @param  int  $previousDurationSeconds  The duration this same entry already contributed
     *                                        to tracked totals before this save (0 for a new entry).
     *
     * @throws RetainerCapExceededException
     */
    public function enforce(?string $projectId, Carbon $start, Carbon $end, int $previousDurationSeconds = 0): void
    {
        if ($projectId === null) {
            return;
        }

        // Filtering in SQL (rather than checking $project->client_id === null in PHP)
        // sidesteps Project's client_id being typed non-nullable in its PHPDoc even
        // though the column itself is nullable (internal/client-less projects).
        $project = Project::query()->whereKey($projectId)->whereNotNull('client_id')->first();
        if ($project === null) {
            return;
        }

        $retainer = $this->lookup->findActiveForClient($project->client_id, $end);
        if ($retainer === null || ! $retainer->hard_cap_enabled) {
            return;
        }

        if ($retainer->hard_cap_enforcement !== RetainerHardCapEnforcement::Block) {
            // Flag/approval enforcement is reserved for a future iteration; no-op for now.
            return;
        }

        $newDuration = (int) $end->diffInSeconds($start, absolute: true);
        $delta = $newDuration - $previousDurationSeconds;
        if ($delta <= 0) {
            return;
        }

        $this->checkParentCap($retainer, $end, $delta);

        if ($retainer->sub_cap_mode === RetainerSubCapMode::Strict) {
            $this->checkSubCap($retainer, $projectId, $end, $delta);
        }
    }

    /**
     * @throws RetainerCapExceededException
     */
    private function checkParentCap(Retainer $retainer, Carbon $asOf, int $delta): void
    {
        $tracked = $this->consumption->computeTracked($retainer, $asOf);
        $cap = $retainer->hard_cap_scope === RetainerHardCapScope::Cumulative
            ? (int) $retainer->hard_cap_cumulative_seconds
            : $this->allocation->computeAllocated($retainer, $asOf);

        if ($tracked + $delta > $cap) {
            throw new RetainerCapExceededException($retainer->id, $cap, $tracked, $delta);
        }
    }

    /**
     * @throws RetainerCapExceededException
     */
    private function checkSubCap(Retainer $retainer, string $projectId, Carbon $asOf, int $delta): void
    {
        $cap = $retainer->projectCaps()->where('project_id', $projectId)->first();
        if ($cap === null) {
            return;
        }

        $capSeconds = $retainer->hard_cap_scope === RetainerHardCapScope::Cumulative
            ? $cap->seconds_cumulative
            : $cap->seconds_per_period;
        if ($capSeconds === null) {
            return;
        }

        $tracked = $this->consumption->computeTracked($retainer, $asOf, $projectId);
        if ($tracked + $delta > $capSeconds) {
            throw new RetainerCapExceededException($retainer->id, $capSeconds, $tracked, $delta);
        }
    }
}
