<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\UpdateApiTokenLastUsedAt;
use App\Models\Passport\Client;
use App\Models\Passport\Token;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\AccessToken;
use Laravel\Passport\Passport;
use Laravel\Passport\TransientToken;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(UpdateApiTokenLastUsedAt::class)]
class UpdateApiTokenLastUsedAtMiddlewareTest extends MiddlewareTestAbstract
{
    private function createTestRoute(): string
    {
        $route = Route::get('/test-route', function () {
            return response()->json(['message' => 'Test route']);
        })->middleware([UpdateApiTokenLastUsedAt::class]);

        return $route->uri;
    }

    private function createApiToken(User $user, ?Carbon $lastUsedAt = null): Token
    {
        $client = Client::factory()->personalAccessClient()->create();
        $token = Token::factory()->forUser($user)->forClient($client)->create([
            'last_used_at' => $lastUsedAt,
        ]);
        $token->refresh();

        return $token;
    }

    /**
     * Authenticate as the given user with an access token that exists in the database, which is how
     * requests that are authenticated with a bearer token look.
     */
    private function actingWithApiToken(User $user, Token $token): void
    {
        Passport::actingAs($user);
        $user->withAccessToken(new AccessToken([
            'oauth_access_token_id' => $token->getKey(),
            'oauth_client_id' => $token->client_id,
            'oauth_user_id' => $user->getKey(),
            'oauth_scopes' => $token->scopes,
        ]));
    }

    public function test_request_records_the_first_usage_of_an_api_token(): void
    {
        // Arrange
        $now = Carbon::now()->startOfSecond();
        $this->travelTo($now);
        $data = $this->createUserWithPermission();
        $token = $this->createApiToken($data->user);
        $this->actingWithApiToken($data->user, $token);
        $url = $this->createTestRoute();

        // Act
        $response = $this->get($url);

        // Assert
        $response->assertStatus(200);
        $token->refresh();
        $this->assertSame($now->toIso8601ZuluString(), $token->last_used_at?->toIso8601ZuluString());
    }

    public function test_request_updates_the_usage_of_an_api_token_that_was_last_used_a_while_ago(): void
    {
        // Arrange
        $now = Carbon::now()->startOfSecond();
        $this->travelTo($now);
        $data = $this->createUserWithPermission();
        $token = $this->createApiToken($data->user, $now->copy()->subHour());
        $this->actingWithApiToken($data->user, $token);
        $url = $this->createTestRoute();

        // Act
        $response = $this->get($url);

        // Assert
        $response->assertStatus(200);
        $token->refresh();
        $this->assertSame($now->toIso8601ZuluString(), $token->last_used_at?->toIso8601ZuluString());
    }

    public function test_request_does_not_update_the_usage_of_an_api_token_that_was_just_used(): void
    {
        // Arrange
        $now = Carbon::now()->startOfSecond();
        $this->travelTo($now);
        $data = $this->createUserWithPermission();
        $lastUsedAt = $now->copy()->subSeconds(5);
        $token = $this->createApiToken($data->user, $lastUsedAt);
        $this->actingWithApiToken($data->user, $token);
        $url = $this->createTestRoute();

        // Act
        $response = $this->get($url);

        // Assert
        $response->assertStatus(200);
        $token->refresh();
        $this->assertSame($lastUsedAt->toIso8601ZuluString(), $token->last_used_at?->toIso8601ZuluString());
    }

    public function test_request_does_not_change_the_updated_at_of_the_api_token(): void
    {
        // Arrange
        $now = Carbon::now()->startOfSecond();
        $this->travelTo($now);
        $data = $this->createUserWithPermission();
        $token = $this->createApiToken($data->user);
        $updatedAt = $token->updated_at;
        $this->actingWithApiToken($data->user, $token);
        $url = $this->createTestRoute();

        // Act
        $response = $this->get($url);

        // Assert
        $response->assertStatus(200);
        $token->refresh();
        $this->assertNotNull($token->last_used_at);
        $this->assertSame($updatedAt?->toIso8601ZuluString(), $token->updated_at?->toIso8601ZuluString());
    }

    public function test_request_that_is_authenticated_with_the_session_cookie_does_not_record_a_usage(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $token = $this->createApiToken($data->user);
        Passport::actingAs($data->user);
        $data->user->withAccessToken(new TransientToken);
        $url = $this->createTestRoute();

        // Act
        $response = $this->get($url);

        // Assert
        $response->assertStatus(200);
        $token->refresh();
        $this->assertNull($token->last_used_at);
    }

    public function test_request_of_a_guest_does_not_fail(): void
    {
        // Arrange
        $url = $this->createTestRoute();

        // Act
        $response = $this->get($url);

        // Assert
        $response->assertStatus(200);
    }
}
