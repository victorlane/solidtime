<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use App\Http\Resources\V1\BaseResource;
use App\Models\Retainer;
use Illuminate\Http\Request;

/**
 * @property Retainer $resource
 */
class RetainerResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** ID */
            'id' => $this->resource->id,
            /** Organization ID */
            'organization_id' => $this->resource->organization_id,
            /** Client ID */
            'client_id' => $this->resource->client_id,
            /** Name */
            'name' => $this->resource->name,
            /** Description */
            'description' => $this->resource->description,
            /** How periods are derived: calendar | anchor | explicit */
            'period_mode' => $this->resource->period_mode->value,
            /** Recurrence unit: weekly | monthly | quarterly (null for explicit) */
            'period_unit' => $this->resource->period_unit?->value,
            /** Seconds allocated per period (null for explicit) */
            'seconds_per_period' => $this->resource->seconds_per_period,
            /** Anchor date periods are walked forward from (Format: "Y-m-d") */
            'anchor_date' => $this->formatDate($this->resource->anchor_date),
            /** @var string $starts_at Start date of the retainer (Format: "Y-m-d") */
            'starts_at' => $this->formatDate($this->resource->starts_at),
            /** End date of the retainer, open-ended if null (Format: "Y-m-d") */
            'ends_at' => $this->formatDate($this->resource->ends_at),
            /** Whether only billable time entries count toward consumption */
            'billable_only' => $this->resource->billable_only,
            /** Whether a hard cap blocks saving once exhausted */
            'hard_cap_enabled' => $this->resource->hard_cap_enabled,
            /** per_period | cumulative */
            'hard_cap_scope' => $this->resource->hard_cap_scope?->value,
            /** block | flag | approval (only block is enforced in v1) */
            'hard_cap_enforcement' => $this->resource->hard_cap_enforcement?->value,
            /** Total cap in seconds when hard_cap_scope is cumulative */
            'hard_cap_cumulative_seconds' => $this->resource->hard_cap_cumulative_seconds,
            /** soft | strict */
            'sub_cap_mode' => $this->resource->sub_cap_mode->value,
            /** When the retainer was created */
            'created_at' => $this->formatDateTime($this->resource->created_at),
            /** When the retainer was last updated */
            'updated_at' => $this->formatDateTime($this->resource->updated_at),
        ];
    }
}
