<?php

declare(strict_types=1);

namespace App\Service\Retainer;

use App\Models\Retainer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sums already-tracked time entry seconds for a retainer's client, optionally scoped
 * to a single project. Always queried live (no caching) so it reflects the latest
 * saved time entries, both when enforcing the hard cap on write and when displaying
 * status.
 */
class ConsumptionQuery
{
    /**
     * Tracked seconds for the retainer's client between the retainer's start and $asOf.
     */
    public function computeTracked(Retainer $retainer, Carbon $asOf, ?string $projectId = null): int
    {
        return $this->computeTrackedInWindow(
            $retainer,
            $retainer->starts_at->copy()->startOfDay(),
            $asOf,
            $projectId,
        );
    }

    /**
     * Tracked seconds for the retainer's client within an explicit window. Used for the
     * per-period display, where the window is the current period's bounds rather than
     * the retainer's overall start.
     */
    public function computeTrackedInWindow(Retainer $retainer, Carbon $windowStart, Carbon $windowEnd, ?string $projectId = null): int
    {
        $row = DB::selectOne(
            <<<'SQL'
                SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (time_entries.end - time_entries.start)))::bigint, 0) AS seconds
                FROM time_entries
                INNER JOIN projects ON projects.id = time_entries.project_id
                WHERE projects.client_id = :client_id
                  AND time_entries.end IS NOT NULL
                  AND time_entries.start >= :window_start
                  AND time_entries.end <= :window_end
                  AND (:billable_only = 0 OR time_entries.billable = true)
                  AND (:project_id::uuid IS NULL OR time_entries.project_id = :project_id::uuid)
            SQL,
            [
                'client_id' => $retainer->client_id,
                'window_start' => $windowStart,
                'window_end' => $windowEnd,
                // Bound as an int rather than a PHP bool: the pgsql PDO driver binds
                // booleans ambiguously, which trips "operator does not exist: integer = boolean".
                'billable_only' => $retainer->billable_only ? 1 : 0,
                'project_id' => $projectId,
            ]
        );

        return (int) ($row->seconds ?? 0);
    }
}
