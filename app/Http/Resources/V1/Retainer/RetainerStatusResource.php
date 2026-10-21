<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use App\Http\Resources\V1\BaseResource;
use App\Service\Retainer\RetainerStatus;
use Illuminate\Http\Request;

/**
 * @property RetainerStatus $resource
 */
class RetainerStatusResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** Date the status was computed as of (Format: "Y-m-d") */
            'as_of' => $this->resource->asOf,
            /** Cumulative allocated seconds from the retainer's start up to as_of */
            'allocated_seconds' => $this->resource->allocatedSeconds,
            /** Cumulative tracked seconds from the retainer's start up to as_of */
            'tracked_seconds' => $this->resource->trackedSeconds,
            /** tracked_seconds minus allocated_seconds */
            'delta_seconds' => $this->resource->deltaSeconds,
            /** tracked_seconds / allocated_seconds, 0 if nothing is allocated yet */
            'percent' => $this->resource->percent,
            /** Whether a hard cap blocks saving once exhausted */
            'hard_cap_enabled' => $this->resource->hardCapEnabled,
            /** per_period | cumulative */
            'hard_cap_scope' => $this->resource->hardCapScope,
            /** Un-prorated bounds and consumption of the period containing as_of, null if as_of falls outside the retainer's active window */
            'current_period' => $this->resource->currentPeriod,
        ];
    }
}
