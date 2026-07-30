<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\Member;

use App\Enums\Role;
use App\Http\Requests\V1\BaseFormRequest;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * @property Organization $organization
 */
class MemberUpdateRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string|ValidationRule|\Illuminate\Contracts\Validation\Rule>>
     */
    public function rules(): array
    {
        return [
            'role' => [
                'string',
                Rule::enum(Role::class),
            ],
            'billable_rate' => array_merge(
                [
                    'nullable',
                ],
                $this->moneyRules()
            ),
            // Weekly billable-hours target in seconds
            'weekly_billable_target' => [
                'nullable',
                'integer',
                'min:0',
                'max:2147483647',
            ],
        ];
    }

    public function getBillableRate(): ?int
    {
        $input = $this->input('billable_rate');

        return $input !== null && $input !== 0 ? (int) $this->input('billable_rate') : null;
    }

    public function getWeeklyBillableTarget(): ?int
    {
        $input = $this->input('weekly_billable_target');

        return $input !== null && $input !== 0 ? (int) $input : null;
    }

    public function getRole(): Role
    {
        return Role::from($this->input('role'));
    }
}
