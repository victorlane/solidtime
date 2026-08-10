<?php

declare(strict_types=1);

namespace App\Rules;

use App\Exceptions\Retainer\RetainerCapExceededException;
use App\Http\Requests\V1\TimeEntry\TimeEntryUpdateRequest;
use App\Models\TimeEntry;
use App\Service\Retainer\CapEnforcer;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Carbon;
use Illuminate\Translation\PotentiallyTranslatedString;
use Throwable;

/**
 * Blocks saving a time entry once the active retainer for its project's client has a
 * hard cap enabled (in `block` enforcement mode) and this save's net new seconds
 * would push tracked consumption past the cap (or a strict per-project sub-cap).
 *
 * Attach on the `end` field: a time entry without an end is a running timer and is
 * never subject to a cap (mirrors the source implementation's behaviour, which also
 * only enforces once an entry has a concrete duration to check).
 *
 * Because updates only send the fields that changed, this rule reads `project_id`
 * and `start` from the full request payload (via {@see DataAwareRule}) and falls
 * back to the existing time entry's values for anything not present in the payload,
 * the same way {@see TimeEntryUpdateRequest}
 * resolves the resulting `type` for its break-related rules.
 */
class RetainerCapNotExceeded implements DataAwareRule, ValidationRule
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    public function __construct(
        private readonly ?TimeEntry $existingTimeEntry = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @param  Closure(string): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            // No end -> running timer, nothing to enforce yet.
            return;
        }

        $projectId = $this->data['project_id'] ?? $this->existingTimeEntry?->project_id;
        if ($projectId === null || ! is_string($projectId)) {
            return;
        }

        $startRaw = $this->data['start'] ?? null;

        try {
            $start = is_string($startRaw) && $startRaw !== ''
                ? Carbon::parse($startRaw)
                : $this->existingTimeEntry?->start;
            $end = Carbon::parse($value);
        } catch (Throwable) {
            // Malformed dates are already reported by the date_format rule on these fields.
            return;
        }

        if ($start === null) {
            return;
        }

        $previousDuration = 0;
        if ($this->existingTimeEntry?->end !== null) {
            $previousDuration = (int) $this->existingTimeEntry->end->diffInSeconds($this->existingTimeEntry->start, absolute: true);
        }

        try {
            app(CapEnforcer::class)->enforce($projectId, $start, $end, $previousDuration);
        } catch (RetainerCapExceededException) {
            $fail(__('validation.retainer_cap_exceeded'));
        }
    }
}
