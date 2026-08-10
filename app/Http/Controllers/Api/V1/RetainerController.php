<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\V1\Retainer\RetainerStoreRequest;
use App\Http\Requests\V1\Retainer\RetainerUpdateRequest;
use App\Http\Resources\V1\Retainer\RetainerCollection;
use App\Http\Resources\V1\Retainer\RetainerResource;
use App\Http\Resources\V1\Retainer\RetainerStatusResource;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Retainer;
use App\Service\Retainer\AllocationCalculator;
use App\Service\Retainer\ConsumptionQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class RetainerController extends Controller
{
    /**
     * @throws AuthorizationException
     */
    protected function checkPermission(Organization $organization, string $permission, ?Retainer $retainer = null, ?Client $client = null): void
    {
        parent::checkPermission($organization, $permission);
        if ($retainer !== null && $retainer->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('Retainer does not belong to organization');
        }
        if ($client !== null && $client->organization_id !== $organization->getKey()) {
            throw new AuthorizationException('Client does not belong to organization');
        }
    }

    /**
     * Get retainers for client
     *
     * @return RetainerCollection<RetainerResource>
     *
     * @throws AuthorizationException
     *
     * @operationId getRetainersForClient
     */
    public function index(Organization $organization, Client $client): RetainerCollection
    {
        $this->checkPermission($organization, 'retainers:view', client: $client);

        return new RetainerCollection(
            Retainer::query()
                ->whereBelongsTo($client, 'client')
                ->orderBy('starts_at', 'desc')
                ->get()
        );
    }

    /**
     * Create retainer
     *
     * @throws AuthorizationException|ValidationException
     *
     * @operationId createRetainer
     */
    public function store(Organization $organization, Client $client, RetainerStoreRequest $request): RetainerResource
    {
        $this->checkPermission($organization, 'retainers:create', client: $client);

        $this->assertNoOverlap(
            $client,
            (string) $request->input('starts_at'),
            $request->input('ends_at') !== null ? (string) $request->input('ends_at') : null,
        );

        $retainer = new Retainer($request->validated());
        $retainer->organization()->associate($organization);
        $retainer->client()->associate($client);
        $retainer->save();
        // Pick up column defaults (f.e. sub_cap_mode) not present in the payload.
        $retainer->refresh();

        return new RetainerResource($retainer);
    }

    /**
     * Get retainer
     *
     * @throws AuthorizationException
     *
     * @operationId getRetainer
     */
    public function show(Organization $organization, Retainer $retainer): RetainerResource
    {
        $this->checkPermission($organization, 'retainers:view', $retainer);

        return new RetainerResource($retainer);
    }

    /**
     * Update retainer
     *
     * @throws AuthorizationException|ValidationException
     *
     * @operationId updateRetainer
     */
    public function update(Organization $organization, Retainer $retainer, RetainerUpdateRequest $request): RetainerResource
    {
        $this->checkPermission($organization, 'retainers:update', $retainer);

        if ($request->has('starts_at') || $request->has('ends_at')) {
            $this->assertNoOverlap(
                $retainer->client,
                (string) $request->input('starts_at', $retainer->starts_at->toDateString()),
                $request->input('ends_at', $retainer->ends_at?->toDateString()) !== null
                    ? (string) $request->input('ends_at', $retainer->ends_at?->toDateString())
                    : null,
                exceptRetainerId: $retainer->getKey(),
            );
        }

        $retainer->fill($request->validated());
        $retainer->save();

        return new RetainerResource($retainer);
    }

    /**
     * Delete retainer
     *
     * @throws AuthorizationException
     *
     * @operationId deleteRetainer
     */
    public function destroy(Organization $organization, Retainer $retainer): JsonResponse
    {
        $this->checkPermission($organization, 'retainers:delete', $retainer);

        $retainer->delete();

        return response()->json(null, 204);
    }

    /**
     * Get retainer status
     *
     * Cumulative allocated/tracked seconds since the retainer started, plus an
     * un-prorated breakdown for the period containing `as_of` (defaults to now).
     *
     * @throws AuthorizationException
     *
     * @operationId getRetainerStatus
     */
    public function status(
        Organization $organization,
        Retainer $retainer,
        Request $request,
        AllocationCalculator $calculator,
        ConsumptionQuery $consumption,
    ): RetainerStatusResource {
        $this->checkPermission($organization, 'retainers:view', $retainer);

        $asOf = $request->query('as_of') !== null
            ? Carbon::parse((string) $request->query('as_of'))->endOfDay()
            : Carbon::now();

        $allocated = $calculator->computeAllocated($retainer, $asOf);
        $tracked = $consumption->computeTracked($retainer, $asOf);
        $percent = $allocated > 0 ? round($tracked / $allocated, 4) : 0.0;

        $periodBounds = $calculator->currentPeriodBounds($retainer, $asOf);
        $currentPeriod = null;
        if ($periodBounds !== null) {
            $periodTracked = $consumption->computeTrackedInWindow(
                $retainer,
                $periodBounds['starts_at']->copy()->startOfDay(),
                $periodBounds['ends_at']->copy()->endOfDay(),
            );
            $periodAllocated = $periodBounds['seconds_allocated'];
            $periodPercent = $periodAllocated > 0 ? round($periodTracked / $periodAllocated, 4) : 0.0;
            $currentPeriod = [
                'starts_at' => $periodBounds['starts_at']->toDateString(),
                'ends_at' => $periodBounds['ends_at']->toDateString(),
                'allocated_seconds' => $periodAllocated,
                'tracked_seconds' => $periodTracked,
                'delta_seconds' => $periodTracked - $periodAllocated,
                'percent' => $periodPercent,
            ];
        }

        return new RetainerStatusResource([
            'as_of' => $asOf->toDateString(),
            'allocated_seconds' => $allocated,
            'tracked_seconds' => $tracked,
            'delta_seconds' => $tracked - $allocated,
            'percent' => $percent,
            'hard_cap_enabled' => $retainer->hard_cap_enabled,
            'hard_cap_scope' => $retainer->hard_cap_scope?->value,
            'current_period' => $currentPeriod,
        ]);
    }

    /**
     * At most one retainer per client may be active on any given day. Enforced here,
     * application-side, rather than as a DB constraint (mirrors how overlapping time
     * entries are prevented elsewhere in the codebase).
     *
     * @throws ValidationException
     */
    private function assertNoOverlap(Client $client, string $startsAt, ?string $endsAt, ?string $exceptRetainerId = null): void
    {
        $start = Carbon::parse($startsAt);
        $end = $endsAt !== null ? Carbon::parse($endsAt) : null;

        $query = Retainer::query()->whereBelongsTo($client, 'client');
        if ($exceptRetainerId !== null) {
            $query->where('id', '!=', $exceptRetainerId);
        }

        foreach ($query->get() as $other) {
            $startsBeforeOtherEnds = $other->ends_at === null || $start->lte($other->ends_at);
            $endsAfterOtherStarts = $end === null || $end->gte($other->starts_at);
            if ($startsBeforeOtherEnds && $endsAfterOtherStarts) {
                throw ValidationException::withMessages([
                    'starts_at' => __('validation.retainer_overlaps'),
                ]);
            }
        }
    }
}
