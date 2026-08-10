<?php

declare(strict_types=1);

namespace App\Exceptions\Retainer;

use App\Rules\RetainerCapNotExceeded;
use App\Service\Retainer\CapEnforcer;
use RuntimeException;

/**
 * Thrown internally by {@see CapEnforcer} when saving a time
 * entry would push a retainer (or one of its per-project sub-caps) over its hard cap.
 *
 * This is a plain domain exception, not an `App\Exceptions\Api\ApiException`: it is
 * always caught by {@see RetainerCapNotExceeded} and turned into a regular
 * 422 validation failure, consistent with how the rest of the time entry business
 * rules (f.e. max duration, overlap) are surfaced to API clients.
 */
class RetainerCapExceededException extends RuntimeException
{
    public function __construct(
        public readonly string $retainerId,
        public readonly int $capSeconds,
        public readonly int $trackedSeconds,
        public readonly int $attemptedDeltaSeconds,
    ) {
        parent::__construct('Saving this time entry would exceed the retainer cap.');
    }
}
