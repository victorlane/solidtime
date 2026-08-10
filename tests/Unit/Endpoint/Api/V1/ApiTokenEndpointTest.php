<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Http\Controllers\Api\V1\ApiTokenController;
use App\Http\Requests\V1\ApiToken\ApiTokenStoreRequest;
use App\Models\Passport\Client;
use App\Models\Passport\Token;
use Illuminate\Support\Carbon;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\UsesClass;

#[UsesClass(ApiTokenController::class)]
class ApiTokenEndpointTest extends ApiEndpointTestAbstract
{
    public function test_index_endpoint_returns_list_api_tokens(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $personalAccessClient = $this->createPersonalAccessClient();
        $client = $this->createClient();
        $token = Token::factory()->forUser($data->user)->forClient($personalAccessClient)->create([
            'last_used_at' => Carbon::now()->startOfSecond()->subDay(),
        ]);
        $otherTokenType = Token::factory()->forUser($data->user)->forClient($client)->create();
        $otherData = $this->createUserWithPermission([]);
        $otherToken = Token::factory()->forUser($otherData->user)->forClient($personalAccessClient)->create();
        Passport::actingAs($data->user);

        // Act
        $response = $this->getJson(route('api.v1.api-tokens.index'));

        // Assert
        $this->assertResponseCode($response, 200);
        $response->assertJsonCount(1, 'data');
        $response->assertExactJson([
            'data' => [
                [
                    'id' => $token->id,
                    'name' => $token->name,
                    'scopes' => $token->scopes,
                    'revoked' => $token->revoked,
                    'created_at' => $token->created_at->toIso8601ZuluString(),
                    'expires_at' => $token->expires_at->toIso8601ZuluString(),
                    'last_used_at' => $token->last_used_at->toIso8601ZuluString(),
                ],
            ],
        ]);
    }

    public function test_index_endpoint_returns_null_as_last_used_at_for_an_api_token_that_was_never_used(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $personalAccessClient = $this->createPersonalAccessClient();
        Token::factory()->forUser($data->user)->forClient($personalAccessClient)->create([
            'last_used_at' => null,
        ]);
        Passport::actingAs($data->user);

        // Act
        $response = $this->getJson(route('api.v1.api-tokens.index'));

        // Assert
        $this->assertResponseCode($response, 200);
        $this->assertNull($response->json('data.0.last_used_at'));
    }

    public function test_index_endpoint_returns_api_tokens_ordered_by_created_at_descending(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $personalAccessClient = $this->createPersonalAccessClient();
        $tokenOldest = Token::factory()->forUser($data->user)->forClient($personalAccessClient)->create([
            'created_at' => now()->subDays(3),
        ]);
        $tokenNewest = Token::factory()->forUser($data->user)->forClient($personalAccessClient)->create([
            'created_at' => now()->subDay(),
        ]);
        $tokenMiddle = Token::factory()->forUser($data->user)->forClient($personalAccessClient)->create([
            'created_at' => now()->subDays(2),
        ]);
        Passport::actingAs($data->user);

        // Act
        $response = $this->getJson(route('api.v1.api-tokens.index'));

        // Assert
        $this->assertResponseCode($response, 200);
        $ids = collect($response->json('data'))->pluck('id')->values()->toArray();
        $this->assertSame([$tokenNewest->id, $tokenMiddle->id, $tokenOldest->id], $ids);
    }

    public function test_store_endpoint_creates_new_api_token(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $personalAccessClient = $this->createPersonalAccessClient();
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.api-tokens.store'), [
            'name' => 'Test Token',
        ]);

        // Assert
        $this->assertResponseCode($response, 200);
        $response->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'scopes',
                'revoked',
                'created_at',
                'expires_at',
                'last_used_at',
                'access_token',
            ],
        ]);
    }

    public function test_store_endpoint_creates_api_token_that_expires_after_one_year_by_default(): void
    {
        // Arrange
        $now = Carbon::now()->startOfSecond();
        $this->travelTo($now);
        $data = $this->createUserWithPermission([]);
        $this->createPersonalAccessClient();
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.api-tokens.store'), [
            'name' => 'Test Token',
        ]);

        // Assert
        $this->assertResponseCode($response, 200);
        $this->assertSame($now->copy()->addYear()->toIso8601ZuluString(), $response->json('data.expires_at'));
        $this->assertNull($response->json('data.last_used_at'));
    }

    public function test_store_endpoint_creates_api_token_with_the_given_expiration_date(): void
    {
        // Arrange
        $now = Carbon::now()->startOfSecond();
        $this->travelTo($now);
        $expiresAt = $now->copy()->addYears(5);
        $data = $this->createUserWithPermission([]);
        $this->createPersonalAccessClient();
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.api-tokens.store'), [
            'name' => 'Test Token',
            'expires_at' => $expiresAt->toIso8601ZuluString(),
        ]);

        // Assert
        $this->assertResponseCode($response, 200);
        $this->assertSame($expiresAt->toIso8601ZuluString(), $response->json('data.expires_at'));
        $this->assertDatabaseHas(Token::class, [
            'id' => $response->json('data.id'),
            'expires_at' => $expiresAt->toDateTimeString(),
        ]);
    }

    public function test_store_endpoint_creates_api_token_that_never_expires(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $this->createPersonalAccessClient();
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.api-tokens.store'), [
            'name' => 'Test Token',
            'expires_at' => null,
        ]);

        // Assert
        $this->assertResponseCode($response, 200);
        $this->assertNull($response->json('data.expires_at'));
        $this->assertDatabaseHas(Token::class, [
            'id' => $response->json('data.id'),
            'expires_at' => null,
        ]);
    }

    public function test_store_endpoint_does_not_change_the_configured_default_expiration_for_the_next_token(): void
    {
        // Arrange
        $now = Carbon::now()->startOfSecond();
        $this->travelTo($now);
        $data = $this->createUserWithPermission([]);
        $this->createPersonalAccessClient();
        Passport::actingAs($data->user);
        $this->postJson(route('api.v1.api-tokens.store'), [
            'name' => 'Token with custom expiration',
            'expires_at' => $now->copy()->addDays(30)->toIso8601ZuluString(),
        ]);

        // Act
        $response = $this->postJson(route('api.v1.api-tokens.store'), [
            'name' => 'Token with default expiration',
        ]);

        // Assert
        $this->assertResponseCode($response, 200);
        $this->assertSame($now->copy()->addYear()->toIso8601ZuluString(), $response->json('data.expires_at'));
    }

    public function test_store_endpoint_fails_if_expiration_date_is_in_the_past(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $this->createPersonalAccessClient();
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.api-tokens.store'), [
            'name' => 'Test Token',
            'expires_at' => Carbon::now()->subDay()->toIso8601ZuluString(),
        ]);

        // Assert
        $this->assertResponseCode($response, 422);
        $response->assertJsonValidationErrors(['expires_at']);
    }

    public function test_store_endpoint_fails_if_expiration_date_is_too_far_in_the_future(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $this->createPersonalAccessClient();
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.api-tokens.store'), [
            'name' => 'Test Token',
            'expires_at' => Carbon::now()->addYears(ApiTokenStoreRequest::MAX_EXPIRATION_IN_YEARS + 1)->toIso8601ZuluString(),
        ]);

        // Assert
        $this->assertResponseCode($response, 422);
        $response->assertJsonValidationErrors(['expires_at']);
    }

    public function test_store_fails_if_personal_access_client_is_not_configured(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.api-tokens.store'), [
            'name' => 'Test Token',
        ]);

        // Assert
        $this->assertResponseCode($response, 400);
        $response->assertExactJson([
            'error' => true,
            'key' => 'personal_access_client_is_not_configured',
            'message' => 'Personal access client is not configured',
        ]);
    }

    public function test_revoke_endpoint_revokes_api_token(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $client = $this->createPersonalAccessClient();
        $token = Token::factory()->forUser($data->user)->forClient($client)->create();
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.api-tokens.revoke', $token->id));

        // Assert
        $this->assertResponseCode($response, 204);
        $this->assertDatabaseHas(Token::class, [
            'id' => $token->id,
            'revoked' => true,
        ]);
    }

    public function test_revoke_fails_if_token_is_not_personal_access_token(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $personalAccessClient = $this->createPersonalAccessClient();
        $client = $this->createClient();
        $token = Token::factory()->forUser($data->user)->forClient($client)->create();
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.api-tokens.revoke', $token->id));

        // Assert
        $this->assertResponseCode($response, 403);
        $this->assertDatabaseHas(Token::class, [
            'id' => $token->id,
            'revoked' => false,
        ]);
    }

    public function test_revoke_fails_if_token_with_id_does_not_exist(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.api-tokens.revoke', 'not-valid'));

        // Assert
        $this->assertResponseCode($response, 404);
    }

    public function test_revoke_fails_if_the_token_does_not_belong_to_the_user(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $otherData = $this->createUserWithPermission([]);
        $client = $this->createPersonalAccessClient();
        $token = Token::factory()->forUser($otherData->user)->forClient($client)->create();
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.api-tokens.revoke', $token->id));

        // Assert
        $this->assertResponseCode($response, 403);
        $this->assertDatabaseHas(Token::class, [
            'id' => $token->id,
            'revoked' => false,
        ]);
    }

    public function test_destroy_endpoint_deletes_api_token(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $client = $this->createPersonalAccessClient();
        $token = Token::factory()->forUser($data->user)->forClient($client)->create();
        Passport::actingAs($data->user);

        // Act
        $response = $this->deleteJson(route('api.v1.api-tokens.destroy', $token->id));

        // Assert
        $this->assertResponseCode($response, 204);
        $this->assertDatabaseMissing(Token::class, ['id' => $token->id]);
    }

    public function test_destroy_fails_if_token_is_not_personal_access_token(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $personalAccessClient = $this->createPersonalAccessClient();
        $client = $this->createClient();
        $token = Token::factory()->forUser($data->user)->forClient($client)->create();
        Passport::actingAs($data->user);

        // Act
        $response = $this->deleteJson(route('api.v1.api-tokens.destroy', $token->id));

        // Assert
        $this->assertResponseCode($response, 403);
        $this->assertDatabaseHas(Token::class, [
            'id' => $token->id,
        ]);
    }

    public function test_destroy_fails_if_token_with_id_does_not_exist(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        Passport::actingAs($data->user);

        // Act
        $response = $this->deleteJson(route('api.v1.api-tokens.destroy', 'not-valid'));

        // Assert
        $this->assertResponseCode($response, 404);
    }

    public function test_destroy_fails_if_the_token_does_not_belong_to_the_user(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([]);
        $otherData = $this->createUserWithPermission([]);
        $client = $this->createPersonalAccessClient();
        $token = Token::factory()->forUser($otherData->user)->forClient($client)->create();
        Passport::actingAs($data->user);

        // Act
        $response = $this->deleteJson(route('api.v1.api-tokens.destroy', $token->id));

        // Assert
        $this->assertResponseCode($response, 403);
        $this->assertDatabaseHas(Token::class, [
            'id' => $token->id,
        ]);
    }

    private function createPersonalAccessClient(): Client
    {
        $clientRepository = new ClientRepository;
        /** @var Client $client */
        $client = $clientRepository->createPersonalAccessGrantClient('Test Personal Access Client');

        return $client;
    }

    private function createClient(): Client
    {
        $clientRepository = new ClientRepository;
        /** @var Client $client */
        $client = $clientRepository->createAuthorizationCodeGrantClient(
            name: 'Desktop App',
            redirectUris: ['http://localhost']
        );

        return $client;
    }
}
