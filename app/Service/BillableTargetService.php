<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\Member;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;

class BillableTargetService
{
    /**
     * Sum of billable seconds a member tracked in [start, end), optionally
     * limited to one project. Mirrors the SQL shape of
     * DashboardService::totalWeeklyBillableTime, but keyed on member_id and an
     * explicit window so the weekly-target reminder can evaluate any week.
     * Running entries count up to now().
     */
    public function billableSeconds(Member $member, ?string $projectId, Carbon $start, Carbon $end): int
    {
        $query = TimeEntry::query()
            ->where('member_id', '=', $member->getKey())
            ->where('billable', '=', true)
            ->where('start', '>=', $start->copy()->utc())
            ->where('start', '<', $end->copy()->utc());
        if ($projectId !== null) {
            $query->where('project_id', '=', $projectId);
        }

        // An aggregate without GROUP BY always yields exactly one row, but the
        // sum itself is null when no time entries matched.
        /** @var object{ aggregate: string|null } $result */
        $result = $query
            ->selectRaw('round(sum(extract(epoch from (coalesce("end", now()) - start)))) as aggregate')
            ->first();

        return (int) ($result->aggregate ?? 0);
    }
}
