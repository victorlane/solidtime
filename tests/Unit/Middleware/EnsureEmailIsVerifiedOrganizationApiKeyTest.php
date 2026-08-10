<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Enums\Role;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Models\Organization;
use App\Models\Passport\Client;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(EnsureEmailIsVerified::class)]
class EnsureEmailIsVerifiedOrganizationApiKeyTest extends MiddlewareTestAbstract
{
    private function createTestRoute(): string
    {
        $route = Route::get('/test-route', function () {
            return response()->json(['message' => 'Test route']);
        })->middleware([EnsureEmailIsVerified::class]);

        return $route->uri;
    }

    public function test_request_authenticated_with_an_organization_api_key_is_let_through(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $apiKey = Client::factory()->organizationApiKey($organization, Role::Admin)->create();
        Passport::actingAsClient($apiKey);
        $url = $this->createTestRoute();

        // Act
        $response = $this->getJson($url);

        // Assert
        $response->assertStatus(200);
    }

    public function test_request_of_a_user_with_an_unverified_email_is_still_rejected(): void
    {
        // Arrange
        $user = User::factory()->unverified()->create();
        Passport::actingAs($user);
        $url = $this->createTestRoute();

        // Act
        $response = $this->getJson($url);

        // Assert
        $response->assertStatus(403);
    }

    public function test_request_of_a_user_with_a_verified_email_is_let_through(): void
    {
        // Arrange
        $user = User::factory()->create();
        Passport::actingAs($user);
        $url = $this->createTestRoute();

        // Act
        $response = $this->getJson($url);

        // Assert
        $response->assertStatus(200);
    }

    public function test_request_without_any_authentication_is_rejected(): void
    {
        // Arrange
        $url = $this->createTestRoute();

        // Act
        $response = $this->getJson($url);

        // Assert
        $response->assertStatus(403);
    }
}
