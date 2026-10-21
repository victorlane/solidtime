<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Enums\Role;
use App\Http\Middleware\AuthenticateApi;
use App\Models\Member;
use App\Models\Passport\Client;
use App\Models\User;
use App\Providers\RouteServiceProvider;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(RouteServiceProvider::class)]
#[CoversClass(AuthenticateApi::class)]
class ApiRateLimitTest extends MiddlewareTestAbstract
{
    protected function setUp(): void
    {
        parent::setUp();
        // The api rate limiter is only active in production
        $this->app['env'] = 'production';
        config([
            'app.api_rate_limit_authenticated_per_minute' => 2,
            'app.api_rate_limit_guest_per_minute' => 1,
        ]);
    }

    public function test_api_requests_are_authenticated_before_they_are_throttled(): void
    {
        // Arrange
        $route = Route::getRoutes()->getByName('api.v1.tags.index');
        $this->assertNotNull($route);

        // Act
        $middleware = app(Router::class)->gatherRouteMiddleware($route);

        // Assert
        $authenticateIndex = array_search(AuthenticateApi::class, $middleware, true);
        $throttleIndex = array_search(ThrottleRequests::class.':api', $middleware, true);
        $this->assertIsInt($authenticateIndex);
        $this->assertIsInt($throttleIndex);
        $this->assertLessThan($throttleIndex, $authenticateIndex);
    }

    public function test_user_is_throttled_per_user_with_the_authenticated_limit(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['tags:view']);
        Passport::actingAs($data->user);
        $url = route('api.v1.tags.index', $data->organization->getKey());

        // Act
        $first = $this->getJson($url);
        $second = $this->getJson($url);
        $third = $this->getJson($url);

        // Assert
        $first->assertStatus(200);
        $second->assertStatus(200);
        $third->assertStatus(429);
    }

    public function test_organization_owner_is_not_throttled(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['tags:view']);
        Member::factory()->forUser($data->user)->role(Role::Owner)->create();
        Passport::actingAs($data->user);
        $url = route('api.v1.tags.index', $data->organization->getKey());

        // Act
        $responses = [];
        for ($i = 0; $i < 5; $i++) {
            $responses[] = $this->getJson($url);
        }

        // Assert
        foreach ($responses as $response) {
            $response->assertStatus(200);
        }
    }

    public function test_owner_does_not_lift_the_limit_of_other_users(): void
    {
        // Arrange
        $data = $this->createUserWithPermission(['tags:view']);
        $owner = User::factory()->create();
        Member::factory()->forUser($owner)->forOrganization($data->organization)->role(Role::Owner)->create();
        Passport::actingAs($data->user);
        $url = route('api.v1.tags.index', $data->organization->getKey());

        // Act
        $this->getJson($url);
        $this->getJson($url);
        $response = $this->getJson($url);

        // Assert
        $response->assertStatus(429);
    }

    public function test_organization_api_key_is_throttled_per_key_with_the_authenticated_limit(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        $client = Client::factory()->organizationApiKey($data->organization, Role::Admin)->create();
        Passport::actingAsClient($client);
        $url = route('api.v1.tags.index', $data->organization->getKey());

        // Act
        $first = $this->getJson($url);
        $second = $this->getJson($url);
        $third = $this->getJson($url);

        // Assert
        $first->assertStatus(200);
        $second->assertStatus(200);
        $third->assertStatus(429);
    }
}
