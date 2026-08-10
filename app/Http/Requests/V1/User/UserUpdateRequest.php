<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\User;

use App\Enums\HideableNavItem;
use App\Enums\Weekday;
use App\Http\Requests\V1\BaseFormRequest;
use App\Models\User;
use App\Rules\Base64ImageRule;
use App\Service\TimezoneService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Korridor\LaravelModelValidationRules\Rules\UniqueEloquent;

/**
 * @property User $user User from model binding
 */
class UserUpdateRequest extends BaseFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('email') && is_string($this->input('email'))) {
            $this->merge([
                'email' => Str::lower((string) $this->input('email')),
            ]);
        }

        // Browsers can report legacy/renamed IANA timezone identifiers (e.g. "Europe/Kiev").
        // Normalize those to their current canonical name before the `timezone:all` rule
        // runs, otherwise a legacy zone would fail validation forever.
        if ($this->has('timezone') && is_string($this->input('timezone'))) {
            $service = app(TimezoneService::class);
            $timezone = (string) $this->input('timezone');
            if (! $service->isValid($timezone)) {
                $mapped = $service->mapLegacyTimezone($timezone);
                if ($mapped !== null) {
                    $this->merge(['timezone' => $mapped]);
                }
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string|\Illuminate\Contracts\Validation\Rule|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'string',
                'max:255',
            ],
            'email' => [
                'email:rfc,strict',
                'max:255',
                UniqueEloquent::make(User::class, 'email')->ignore($this->user->id)->query(function (Builder $query) {
                    /** @var Builder<User> $query */
                    return $query->where('is_placeholder', '=', false);
                }),
            ],
            'photo' => [
                'nullable',
                new Base64ImageRule,
            ],
            'timezone' => [
                'timezone:all',
            ],
            'week_start' => [
                Rule::enum(Weekday::class),
            ],
            'hidden_nav_items' => [
                'array',
            ],
            'hidden_nav_items.*' => [
                Rule::enum(HideableNavItem::class),
            ],
        ];
    }

    public function getName(): ?string
    {
        return $this->has('name') ? (string) $this->input('name') : null;
    }

    public function getEmail(): ?string
    {
        return $this->has('email') ? Str::lower((string) $this->input('email')) : null;
    }

    public function getTimezone(): ?string
    {
        return $this->has('timezone') ? (string) $this->input('timezone') : null;
    }

    public function getWeekStart(): ?Weekday
    {
        return $this->has('week_start') ? Weekday::from($this->input('week_start')) : null;
    }

    /**
     * Null means the field was not sent (leave the user's stored preference untouched);
     * an empty array is a valid value meaning "everything visible".
     *
     * @return array<int, string>|null
     */
    /**
     * @return list<string>|null
     */
    public function getHiddenNavItems(): ?array
    {
        if (! $this->has('hidden_nav_items')) {
            return null;
        }
        $value = $this->input('hidden_nav_items');

        return is_array($value) ? array_values(array_map('strval', $value)) : [];
    }

    public function hasPhotoKey(): bool
    {
        return $this->has('photo');
    }

    public function getPhoto(): ?string
    {
        $value = $this->input('photo');

        return is_string($value) ? $value : null;
    }
}
