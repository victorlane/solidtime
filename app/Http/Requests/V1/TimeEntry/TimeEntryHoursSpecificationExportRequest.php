<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\TimeEntry;

use App\Models\Organization;
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * @property Organization $organization
 */
class TimeEntryHoursSpecificationExportRequest extends TimeEntryIndexExportRequest
{
    /**
     * The hours specification takes the same filters as the detailed export — that is the point,
     * you scope it to one client for one month — but it is a fixed document rather than a data
     * dump, so the parameters that shape a dump do not apply.
     *
     * @return array<string, array<string|ValidationRule|Rule|\Closure>>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset(
            $rules['format'],
            $rules['limit'],
            $rules['only_full_dates'],
            $rules['include_metadata'],
        );

        return array_merge($rules, [
            // Show the money next to the hours. Ignored for members that may not see billable rates.
            'include_amounts' => [
                'string',
                'in:true,false',
            ],
            // Free text for the document header, f.e. the number of the invoice this belongs to
            'reference' => [
                'string',
                'max:100',
            ],
        ]);
    }

    public function getIncludeAmounts(): bool
    {
        return $this->input('include_amounts', 'false') === 'true';
    }

    public function getReference(): ?string
    {
        $reference = $this->input('reference');
        if (! is_string($reference) || trim($reference) === '') {
            return null;
        }

        return trim($reference);
    }
}
