<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\ProjectMember;

use App\Http\Requests\V1\BaseFormRequest;
use App\Models\Member;
use App\Models\Organization;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Korridor\LaravelModelValidationRules\Rules\ExistsEloquent;

/**
 * @property Organization $organization Organization from model binding
 */
class ProjectMemberStoreRequest extends BaseFormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'member_id' => [
                'required',
                ExistsEloquent::make(Member::class, null, function (Builder $builder): Builder {
                    /** @var Builder<Member> $builder */
                    return $builder->whereBelongsTo($this->organization, 'organization');
                })->uuid(),
            ],
            'billable_rate' => array_merge(
                [
                    'nullable',
                ],
                $this->moneyRules()
            ),
            // Weekly billable-hours target on this project in seconds
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
}
