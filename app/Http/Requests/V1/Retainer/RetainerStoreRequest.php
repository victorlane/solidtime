<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Retainer;

use App\Enums\RetainerHardCapEnforcement;
use App\Enums\RetainerHardCapScope;
use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Enums\RetainerSubCapMode;
use App\Http\Requests\V1\BaseFormRequest;
use App\Models\Client;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * @property Organization $organization Organization from model binding
 * @property Client $client Client from model binding
 */
class RetainerStoreRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string|ValidationRule|\Illuminate\Contracts\Validation\Rule>>
     */
    public function rules(): array
    {
        return [
            // Name of the retainer
            'name' => ['required', 'string', 'min:1', 'max:255'],
            // Free-text description
            'description' => ['nullable', 'string'],

            // How periods are derived: calendar | anchor | explicit
            'period_mode' => ['required', Rule::enum(RetainerPeriodMode::class)],
            // Recurrence unit, required unless period_mode is explicit
            'period_unit' => ['nullable', Rule::enum(RetainerPeriodUnit::class), 'required_unless:period_mode,explicit'],
            // Seconds allocated per period, required unless period_mode is explicit
            'seconds_per_period' => ['nullable', 'integer', 'min:0', 'required_unless:period_mode,explicit'],
            // Anchor date periods are walked forward from, required when period_mode is anchor
            'anchor_date' => ['nullable', 'date', 'required_if:period_mode,anchor'],

            // Start date of the retainer (Format: "Y-m-d")
            'starts_at' => ['required', 'date'],
            // End date of the retainer, open-ended if omitted (Format: "Y-m-d")
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],

            // Whether only billable time entries count toward consumption
            'billable_only' => ['boolean'],

            // Whether a hard cap blocks saving once exhausted
            'hard_cap_enabled' => ['boolean'],
            // per_period | cumulative, required when hard_cap_enabled is true
            'hard_cap_scope' => ['nullable', Rule::enum(RetainerHardCapScope::class), 'required_if:hard_cap_enabled,true'],
            // block | flag | approval, required when hard_cap_enabled is true. Only "block" is enforced in v1.
            'hard_cap_enforcement' => ['nullable', Rule::enum(RetainerHardCapEnforcement::class), 'required_if:hard_cap_enabled,true'],
            // Total cap in seconds, required when hard_cap_scope is cumulative
            'hard_cap_cumulative_seconds' => ['nullable', 'integer', 'min:0', 'required_if:hard_cap_scope,cumulative'],

            // soft | strict, whether per-project sub-caps actually block saving
            'sub_cap_mode' => ['nullable', Rule::enum(RetainerSubCapMode::class)],
        ];
    }
}
