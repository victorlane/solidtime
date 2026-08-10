<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Enums\Role;
use App\Http\Middleware\AuthenticateApi;
use App\Models\Organization;
use App\Models\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(AuthenticateApi::class)]
class AuthenticateApiMiddlewareTest extends MiddlewareTestAbstract
{
    private function organizationApiKey(Organization $organization, Role $role = Role::Admin): Client
    {
        return Client::factory()->organizationApiKey($organization, $role)->create();
    }

    public function test_request_with_an_organization_api_key_is_authenticated(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $client = $this->organizationApiKey($data->organization);
        Passport::actingAsClient($client);

        // Act
        $response = $this->getJson(route('api.v1.tags.index', $data->organization->getKey()));

        // Assert
        $response->assertStatus(200);
    }

    public function test_request_with_an_organization_api_key_of_another_organization_is_rejected(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $otherData = $this->createUserWithPermission();
        $client = $this->organizationApiKey($otherData->organization);
        Passport::actingAsClient($client);

        // Act
        $response = $this->getJson(route('api.v1.tags.index', $data->organization->getKey()));

        // Assert
        $response->assertStatus(403);
    }

    public function test_request_with_a_client_credentials_token_that_is_not_an_organization_api_key_is_rejected(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $clientRepository = new ClientRepository;
        /** @var Client $client */
        $client = $clientRepository->createClientCredentialsGrantClient('Some first party client');
        Passport::actingAsClient($client);

        // Act
        $response = $this->getJson(route('api.v1.tags.index', $data->organization->getKey()));

        // Assert
        $response->assertStatus(401);
    }

    public function test_request_without_any_authentication_is_rejected(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();

        // Act
        $response = $this->getJson(route('api.v1.tags.index', $data->organization->getKey()));

        // Assert
        $response->assertStatus(401);
    }

    public function test_request_of_a_user_is_still_authenticated(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['tags:view']);
        Passport::actingAs($data->user);

        // Act
        $response = $this->getJson(route('api.v1.tags.index', $data->organization->getKey()));

        // Assert
        $response->assertStatus(200);
    }

    public function test_organization_api_key_can_not_invite_a_member(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $client = $this->organizationApiKey($data->organization);
        Passport::actingAsClient($client);

        // Act
        $response = $this->postJson(route('api.v1.invitations.store', $data->organization->getKey()), [
            'email' => 'someone@example.com',
            'role' => 'employee',
        ]);

        // Assert
        $response->assertStatus(400);
        $response->assertJsonPath('key', 'organization_api_key_can_not_act_on_behalf_of_a_user');
    }

    public function test_user_can_still_invite_a_member(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['invitations:create']);
        Passport::actingAs($data->user);

        // Act
        $response = $this->postJson(route('api.v1.invitations.store', $data->organization->getKey()), [
            'email' => 'someone@example.com',
            'role' => 'employee',
        ]);

        // Assert
        $response->assertStatus(204);
    }
}
