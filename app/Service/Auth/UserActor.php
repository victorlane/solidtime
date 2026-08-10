<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use App\Service\PermissionStore;

/**
 * A person acting through their membership of an organization.
 */
class UserActor implements Actor
{
    public function __construct(
        public readonly User $user,
    ) {}

    public function belongsTo(Organization $organization): bool
    {
        return $this->user->isMemberOfOrganization($organization);
    }

    /**
     * @return array<string>
     */
    public function permissionsFor(Organization $organization): array
    {
        if (! $this->user->isMemberOfOrganization($organization)) {
            return [];
        }

        $role = $organization->users
            ->where('id', $this->user->getKey())
            ->first()
            ?->membership
            ?->role;

        if ($role === null) {
            return [];
        }

        $permissions = PermissionStore::permissionsForRole($role);

        // If the organization allows employees to manage tasks and the user is an employee,
        // add the task management permissions for accessible projects
        if ($role === Role::Employee->value && $organization->employees_can_manage_tasks) {
            $permissions = array_merge($permissions, [
                'tasks:create',
                'tasks:update',
                'tasks:delete',
            ]);
        }

        return $permissions;
    }

    public function cacheKey(): string
    {
        return 'user|'.$this->user->getKey();
    }
}
