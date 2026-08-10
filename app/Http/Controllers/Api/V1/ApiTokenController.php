<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Api\PersonalAccessClientIsNotConfiguredException;
use App\Http\Requests\V1\ApiToken\ApiTokenStoreRequest;
use App\Http\Resources\V1\ApiToken\ApiTokenCollection;
use App\Http\Resources\V1\ApiToken\ApiTokenWithAccessTokenResource;
use App\Models\Passport\Client;
use App\Models\Passport\Token;
use App\Models\User;
use DateInterval;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Laravel\Passport\PersonalAccessTokenResult;

class ApiTokenController extends Controller
{
    /**
     * Lifetime that is used for API tokens that never expire.
     *
     * The signed access token itself always carries an expiration date, so "never expires" is
     * implemented as a lifetime that outlives any realistic use of the token. The database
     * column stays `null` to mark the token as non-expiring for the rest of the application.
     */
    private const string NEVER_EXPIRES_LIFETIME = 'P100Y';

    /**
     * List all api token of the currently authenticated user
     *
     * This endpoint is independent of the organization.
     *
     * @operationId getApiTokens
     *
     * @throws AuthorizationException
     */
    public function index(): ApiTokenCollection
    {
        $user = $this->user();

        $tokens = $user->tokens()
            ->whereHas('client', function (Builder $query): void {
                /** @var Builder<Client> $query */
                $query->whereJsonContains('grant_types', 'personal_access');
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return new ApiTokenCollection($tokens);
    }

    /**
     * Create a new api token for the currently authenticated user
     *
     * The response will contain the access token that can be used to send authenticated API requests.
     * Please note that the access token is only shown in this response and cannot be retrieved later.
     *
     * @operationId createApiToken
     *
     * @throws AuthorizationException|PersonalAccessClientIsNotConfiguredException
     */
    public function store(ApiTokenStoreRequest $request): ApiTokenWithAccessTokenResource
    {
        $user = $this->user();

        $expiresAt = $request->hasExpiresAt() ? $request->getExpiresAt() : Carbon::now()->add(Passport::personalAccessTokensExpireIn());

        try {
            $token = $this->createTokenWithLifetime(
                $user,
                $request->getName(),
                $expiresAt ?? new DateInterval(self::NEVER_EXPIRES_LIFETIME)
            );

            /** @var Token $tokenModel */
            $tokenModel = $token->getToken();

            if ($expiresAt === null) {
                $tokenModel->expires_at = null;
                $tokenModel->save();
            }

            return new ApiTokenWithAccessTokenResource($tokenModel, $token->accessToken);
        } catch (\RuntimeException $exception) {
            report($exception);
            if (Str::contains($exception->getMessage(), ['Personal access client not found'])) {
                throw new PersonalAccessClientIsNotConfiguredException;
            }

            throw $exception;
        }
    }

    /**
     * Create a personal access token that expires at the given point in time.
     *
     * Passport takes the lifetime of personal access tokens from global configuration, so it has
     * to be swapped out for the time it takes to issue the token. The lifetime has to be in place
     * before the token is issued, because it is baked into the signed access token as well.
     *
     * @return PersonalAccessTokenResult<mixed>
     */
    private function createTokenWithLifetime(User $user, string $name, DateTimeInterface|DateInterval $lifetime): PersonalAccessTokenResult
    {
        $default = Passport::personalAccessTokensExpireIn();
        Passport::personalAccessTokensExpireIn($lifetime);

        try {
            return $user->createToken($name, ['*']);
        } finally {
            Passport::personalAccessTokensExpireIn($default);
        }
    }

    /**
     * Revoke an api token
     *
     * @operationId revokeApiToken
     *
     * @throws AuthorizationException
     * @throws PersonalAccessClientIsNotConfiguredException
     */
    public function revoke(Token $apiToken): JsonResponse
    {
        $user = $this->user();

        if ($apiToken->user_id !== $user->getKey()) {
            throw new AuthorizationException('API token does not belong to user');
        }
        if (! ($apiToken->client?->hasGrantType('personal_access') ?? false)) {
            throw new AuthorizationException('API token is not a personal access token');
        }

        $apiToken->revoke();

        return response()->json(null, 204);
    }

    /**
     * Delete an api token
     *
     * @operationId deleteApiToken
     *
     * @throws AuthorizationException|PersonalAccessClientIsNotConfiguredException
     */
    public function destroy(Token $apiToken): JsonResponse
    {
        $user = $this->user();

        if ($apiToken->user_id !== $user->getKey()) {
            throw new AuthorizationException('API token does not belong to user');
        }
        if (! ($apiToken->client?->hasGrantType('personal_access') ?? false)) {
            throw new AuthorizationException('API token is not a personal access token');
        }

        $apiToken->delete();

        return response()->json(null, 204);
    }
}
