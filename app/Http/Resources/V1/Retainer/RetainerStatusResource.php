<?php

declare(strict_types=1);

namespace App\Http\Resources\V1\Retainer;

use App\Http\Controllers\Api\V1\RetainerController;
use App\Http\Resources\V1\BaseResource;
use Illuminate\Http\Request;

/**
 * The controller passes a plain associative array as $resource (see
 * {@see RetainerController::status()}), it is not backed
 * by an Eloquent model.
 *
 * @property array<string, mixed> $resource
 */
class RetainerStatusResource extends BaseResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /** @var string $as_of Date the status was computed as of (Format: "Y-m-d") */
            'as_of' => $this->resource['as_of'],
            /** @var int $allocated_seconds Cumulative allocated seconds from the retainer's start up to as_of */
            'allocated_seconds' => $this->resource['allocated_seconds'],
            /** @var int $tracked_seconds Cumulative tracked seconds from the retainer's start up to as_of */
            'tracked_seconds' => $this->resource['tracked_seconds'],
            /** @var int $delta_seconds tracked_seconds minus allocated_seconds */
            'delta_seconds' => $this->resource['delta_seconds'],
            /** @var float $percent tracked_seconds / allocated_seconds, 0 if nothing is allocated yet */
            'percent' => $this->resource['percent'],
            /** @var bool $hard_cap_enabled Whether a hard cap blocks saving once exhausted */
            'hard_cap_enabled' => $this->resource['hard_cap_enabled'],
            /** @var string|null $hard_cap_scope per_period | cumulative */
            'hard_cap_scope' => $this->resource['hard_cap_scope'],
            /** @var array{starts_at: string, ends_at: string, allocated_seconds: int, tracked_seconds: int, delta_seconds: int, percent: float}|null $current_period Un-prorated bounds and consumption of the period containing as_of, null if as_of falls outside the retainer's active window */
            'current_period' => $this->resource['current_period'],
        ];
    }
}
