<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Auth;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\Passport\Client;
use App\Models\User;
use App\Service\Auth\ActorResolver;
use App\Service\Auth\OrganizationApiKeyActor;
use App\Service\Auth\UserActor;
use App\Service\PermissionStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

#[CoversClass(OrganizationApiKeyActor::class)]
#[CoversClass(ActorResolver::class)]
#[CoversClass(UserActor::class)]
class OrganizationApiKeyActorTest extends TestCase
{
    use RefreshDatabase;

    private function createApiKey(Organization $organization, Role $role = Role::Admin): Client
    {
        return Client::factory()->organizationApiKey($organization, $role)->create();
    }

    public function test_api_key_resolves_to_the_permissions_of_its_role(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $apiKey = $this->createApiKey($organization, Role::Admin);
        Passport::actingAsClient($apiKey);
        $permissionStore = new PermissionStore;

        // Act
        $permissions = $permissionStore->getPermissions($organization);

        // Assert
        $this->assertContains('projects:create', $permissions);
        $this->assertTrue($permissionStore->has($organization, 'projects:create'));
    }

    public function test_api_key_is_denied_against_an_organization_it_does_not_own(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $apiKey = $this->createApiKey($organization, Role::Admin);
        Passport::actingAsClient($apiKey);
        $permissionStore = new PermissionStore;

        // Act
        $permissions = $permissionStore->getPermissions($otherOrganization);

        // Assert
        $this->assertSame([], $permissions);
        $this->assertFalse($permissionStore->has($otherOrganization, 'projects:create'));
    }

    public function test_manager_api_key_has_fewer_permissions_than_an_admin_api_key(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $adminKey = $this->createApiKey($organization, Role::Admin);
        $managerKey = $this->createApiKey($organization, Role::Manager);

        // Act
        Passport::actingAsClient($adminKey);
        $adminPermissions = (new PermissionStore)->getPermissions($organization);
        Passport::actingAsClient($managerKey);
        $managerPermissions = (new PermissionStore)->getPermissions($organization);

        // Assert
        $this->assertNotSame($adminPermissions, $managerPermissions);
        $this->assertContains('members:invite-placeholder', $adminPermissions);
        $this->assertNotContains('members:invite-placeholder', $managerPermissions);
    }

    public function test_api_key_does_not_receive_permissions_scoped_to_the_acting_member(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $apiKey = $this->createApiKey($organization, Role::Admin);
        Passport::actingAsClient($apiKey);

        // Act
        $permissions = (new PermissionStore)->getPermissions($organization);

        // Assert
        foreach ($permissions as $permission) {
            $this->assertStringEndsNotWith(':own', $permission);
        }
    }

    public function test_client_that_is_not_an_organization_api_key_resolves_to_no_actor(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $client = Client::factory()->apiClient()->create();
        Passport::actingAsClient($client);

        // Act
        $actor = (new ActorResolver)->resolve();

        // Assert
        $this->assertNull($actor);
        $this->assertFalse((new PermissionStore)->has($organization, 'projects:create'));
    }

    public function test_api_key_without_a_role_is_not_treated_as_an_organization_api_key(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $apiKey = $this->createApiKey($organization, Role::Admin);
        $apiKey->role = null;
        $apiKey->save();
        Passport::actingAsClient($apiKey);

        // Act
        $actor = (new ActorResolver)->resolve();

        // Assert
        $this->assertNull($actor);
    }

    public function test_a_person_still_resolves_to_a_user_actor(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user, ['role' => Role::Admin->value]);
        $this->actingAs($user);

        // Act
        $actor = (new ActorResolver)->resolve();

        // Assert
        $this->assertInstanceOf(UserActor::class, $actor);
        $this->assertTrue((new PermissionStore)->has($organization, 'projects:create'));
    }

    public function test_employee_permissions_are_unchanged_for_a_person(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user, ['role' => Role::Employee->value]);
        $this->actingAs($user);
        $permissionStore = new PermissionStore;

        // Act
        $permissions = $permissionStore->getPermissions($organization);

        // Assert
        $this->assertSame(PermissionStore::permissionsForRole(Role::Employee->value), $permissions);
        $this->assertTrue($permissionStore->has($organization, 'time-entries:view:own'));
    }

    public function test_employees_can_manage_tasks_setting_still_applies_to_a_person(): void
    {
        // Arrange
        $organization = Organization::factory()->create([
            'employees_can_manage_tasks' => true,
        ]);
        $user = User::factory()->create();
        $organization->users()->attach($user, ['role' => Role::Employee->value]);
        $this->actingAs($user);

        // Act
        $result = (new PermissionStore)->has($organization, 'tasks:create');

        // Assert
        $this->assertTrue($result);
    }

    public function test_user_has_still_answers_for_a_user_that_is_not_authenticated(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $organization->users()->attach($user, ['role' => Role::Admin->value]);
        $permissionStore = new PermissionStore;

        // Act
        $result = $permissionStore->userHas($organization, $user, 'projects:create');

        // Assert
        $this->assertTrue($result);
    }

    public function test_user_has_returns_false_for_a_user_outside_the_organization(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $user = User::factory()->create();
        $permissionStore = new PermissionStore;

        // Act
        $result = $permissionStore->userHas($organization, $user, 'projects:create');

        // Assert
        $this->assertFalse($result);
    }

    public function test_organization_api_key_scope_only_returns_organization_api_keys(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $apiKey = $this->createApiKey($organization, Role::Manager);
        Client::factory()->apiClient()->create();
        Client::factory()->personalAccessClient()->create();

        // Act
        $found = Client::query()->organizationApiKeys()->pluck('id')->all();

        // Assert
        $this->assertSame([$apiKey->getKey()], $found);
    }

    public function test_owner_organization_returns_the_organization_that_owns_the_key(): void
    {
        // Arrange
        $organization = Organization::factory()->create();
        $apiKey = $this->createApiKey($organization);

        // Act
        $owner = $apiKey->ownerOrganization();

        // Assert
        $this->assertNotNull($owner);
        $this->assertSame($organization->getKey(), $owner->getKey());
    }
}
