<?php

declare(strict_types=1);

namespace App\Http\Requests\V1\ApiToken;

use App\Http\Requests\V1\BaseFormRequest;
use Illuminate\Support\Carbon;

class ApiTokenStoreRequest extends BaseFormRequest
{
    /**
     * The furthest point in the future that an API token may expire at.
     */
    public const int MAX_EXPIRATION_IN_YEARS = 100;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:255',
            ],
            // Point in time at which the API token expires, null means that the API token never expires. If the parameter is omitted, the token expires after the default lifetime of one year. (Format: "Y-m-d\TH:i:s\Z", UTC timezone, Example: "2000-02-22T14:58:59Z")
            'expires_at' => [
                'nullable',
                'date_format:Y-m-d\TH:i:s\Z',
                'after:now',
                'before:'.Carbon::now()->addYears(self::MAX_EXPIRATION_IN_YEARS)->format('Y-m-d\TH:i:s\Z'),
            ],
        ];
    }

    public function getName(): string
    {
        return $this->input('name');
    }

    /**
     * Whether the request explicitly asked for a expiration date, if this is false the default expiration should be used.
     */
    public function hasExpiresAt(): bool
    {
        return $this->exists('expires_at');
    }

    /**
     * The requested expiration date, null means that the API token should never expire.
     */
    public function getExpiresAt(): ?Carbon
    {
        $expiresAt = $this->input('expires_at');
        if ($expiresAt === null) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d\TH:i:s\Z', $expiresAt, 'UTC');
    }
}
