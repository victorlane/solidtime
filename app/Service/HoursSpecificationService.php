<?php

declare(strict_types=1);

namespace App\Service;

use App\Models\TimeEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Builds the data behind the hours specification PDF: the document that is attached to a client
 * invoice so the client can check what they are paying for.
 *
 * The grouping is project, then day. A client reconciles an invoice against their own calendar and
 * against the invoice lines, and those two are "which engagement" and "which day" — never "which
 * timer run". Grouping per day also keeps start/stop noise (five timer runs on one afternoon)
 * out of a document that leaves the building, without losing anything that can be verified.
 */
class HoursSpecificationService
{
    /**
     * @param  Builder<TimeEntry>  $timeEntriesQuery  Already filtered and scoped by the caller.
     * @param  bool  $showAmounts  Whether the caller is allowed to see money at all.
     * @return array{
     *     seconds: int,
     *     cost: int|null,
     *     day_count: int,
     *     clients: array<int, string>,
     *     projects: array<int, array{
     *         name: string|null,
     *         client_name: string|null,
     *         color: string|null,
     *         seconds: int,
     *         cost: int|null,
     *         days: array<int, array{
     *             date: Carbon,
     *             seconds: int,
     *             cost: int|null,
     *             items: array<int, array{task: string|null, description: string|null}>
     *         }>
     *     }>
     * }
     */
    public function build(Builder $timeEntriesQuery, string $timezone, bool $showAmounts): array
    {
        $timeEntries = $timeEntriesQuery
            ->with(['project', 'task', 'client'])
            ->get();

        /**
         * @var array<string, array{
         *     name: string|null,
         *     client_name: string|null,
         *     color: string|null,
         *     seconds: int,
         *     cost: float,
         *     days: array<string, array{date: Carbon, seconds: int, cost: float, items: array<string, array{task: string|null, description: string|null}>}>
         * }> $projects
         */
        $projects = [];
        $totalSeconds = 0;
        $totalCost = 0.0;
        $dates = [];

        foreach ($timeEntries as $timeEntry) {
            if ($timeEntry->end === null) {
                continue;
            }
            $seconds = (int) round((float) $timeEntry->start->diffInSeconds($timeEntry->end));
            // The rate is stored in cents per hour, so the cost of a slice of time is cents too.
            $cost = $seconds * ($timeEntry->billable_rate ?? 0) / 3600;
            $projectKey = $timeEntry->project_id ?? '';
            $date = $timeEntry->start->toImmutable()->timezone($timezone)->startOfDay();
            $dateKey = $date->toDateString();

            if (! array_key_exists($projectKey, $projects)) {
                $projects[$projectKey] = [
                    'name' => $timeEntry->project?->name,
                    'client_name' => $timeEntry->client?->name,
                    'color' => $timeEntry->project?->color,
                    'seconds' => 0,
                    'cost' => 0.0,
                    'days' => [],
                ];
            }
            if (! array_key_exists($dateKey, $projects[$projectKey]['days'])) {
                $projects[$projectKey]['days'][$dateKey] = [
                    'date' => Carbon::parse($date),
                    'seconds' => 0,
                    'cost' => 0.0,
                    'items' => [],
                ];
            }

            $description = trim($timeEntry->description) === '' ? null : trim($timeEntry->description);
            $task = $timeEntry->task?->name;
            if ($description !== null || $task !== null) {
                // Repeating "Development — fix the importer" for every timer run of the day would
                // pad the document without adding information, so identical work collapses.
                $projects[$projectKey]['days'][$dateKey]['items'][($task ?? '').'|'.($description ?? '')] = [
                    'task' => $task,
                    'description' => $description,
                ];
            }

            $projects[$projectKey]['days'][$dateKey]['seconds'] += $seconds;
            $projects[$projectKey]['days'][$dateKey]['cost'] += $cost;
            $projects[$projectKey]['seconds'] += $seconds;
            $projects[$projectKey]['cost'] += $cost;
            $totalSeconds += $seconds;
            $totalCost += $cost;
            $dates[$dateKey] = true;
        }

        return [
            'seconds' => $totalSeconds,
            'cost' => $showAmounts ? (int) round($totalCost) : null,
            'day_count' => count($dates),
            'clients' => $this->clientNames($projects),
            'projects' => $this->sortAndFlatten($projects, $showAmounts),
        ];
    }

    /**
     * @param  array<string, array{name: string|null, client_name: string|null, color: string|null, seconds: int, cost: float, days: array<string, array{date: Carbon, seconds: int, cost: float, items: array<string, array{task: string|null, description: string|null}>}>}>  $projects
     * @return array<int, string>
     */
    private function clientNames(array $projects): array
    {
        $clients = [];
        foreach ($projects as $project) {
            if ($project['client_name'] !== null) {
                $clients[$project['client_name']] = true;
            }
        }
        $names = array_keys($clients);
        sort($names);

        return $names;
    }

    /**
     * @param  array<string, array{name: string|null, client_name: string|null, color: string|null, seconds: int, cost: float, days: array<string, array{date: Carbon, seconds: int, cost: float, items: array<string, array{task: string|null, description: string|null}>}>}>  $projects
     * @return array<int, array{
     *     name: string|null,
     *     client_name: string|null,
     *     color: string|null,
     *     seconds: int,
     *     cost: int|null,
     *     days: array<int, array{date: Carbon, seconds: int, cost: int|null, items: array<int, array{task: string|null, description: string|null}>}>
     * }>
     */
    private function sortAndFlatten(array $projects, bool $showAmounts): array
    {
        // Projects alphabetically, work without a project last: it is the leftover bucket and
        // reads as an afterthought, which is exactly what it is.
        uasort($projects, function (array $a, array $b): int {
            if ($a['name'] === null || $b['name'] === null) {
                return ($a['name'] === null ? 1 : 0) <=> ($b['name'] === null ? 1 : 0);
            }

            return strcasecmp($a['name'], $b['name']);
        });

        $result = [];
        foreach ($projects as $project) {
            ksort($project['days']);
            $days = [];
            foreach ($project['days'] as $day) {
                $days[] = [
                    'date' => $day['date'],
                    'seconds' => $day['seconds'],
                    'cost' => $showAmounts ? (int) round($day['cost']) : null,
                    'items' => array_values($day['items']),
                ];
            }
            $result[] = [
                'name' => $project['name'],
                'client_name' => $project['client_name'],
                'color' => $project['color'],
                'seconds' => $project['seconds'],
                'cost' => $showAmounts ? (int) round($project['cost']) : null,
                'days' => $days,
            ];
        }

        return $result;
    }
}
