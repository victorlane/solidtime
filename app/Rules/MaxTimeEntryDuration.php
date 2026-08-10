<?php

declare(strict_types=1);

namespace App\Rules;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Throwable;

/**
 * Rejects a time entry whose duration (end - start) exceeds the configured maximum
 * (config('time.max_duration_hours'), default 168h / 7 days).
 */
class MaxTimeEntryDuration implements ValidationRule
{
    public function __construct(private readonly ?string $start) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $this->start === null) {
            return;
        }

        try {
            $start = CarbonImmutable::parse($this->start);
            $end = CarbonImmutable::parse($value);
        } catch (Throwable) {
            // Malformed dates are reported by the date_format / after_or_equal rules.
            return;
        }

        $maxHours = (int) config('time.max_duration_hours', 168);

        if ($end->diffInSeconds($start, true) > $maxHours * 3600) {
            $fail(__('validation.time_entry_max_duration', ['hours' => $maxHours]));
        }
    }
}
