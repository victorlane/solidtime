<?php

declare(strict_types=1);

namespace App\Service\Retainer;

/**
 * Consumption of a retainer as of a given day, as returned by the retainer status endpoint.
 */
readonly class RetainerStatus
{
    /**
     * @param  array{starts_at: string, ends_at: string, allocated_seconds: int, tracked_seconds: int, delta_seconds: int, percent: float}|null  $currentPeriod
     */
    public function __construct(
        public string $asOf,
        public int $allocatedSeconds,
        public int $trackedSeconds,
        public int $deltaSeconds,
        public float $percent,
        public bool $hardCapEnabled,
        public ?string $hardCapScope,
        public ?array $currentPeriod,
    ) {}
}
