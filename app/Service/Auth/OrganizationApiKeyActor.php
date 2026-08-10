<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Models\Organization;
use App\Models\Passport\Client;
use App\Service\PermissionStore;

/**
 * An organization API key acting on behalf of the organization that owns it.
 *
 * There is no member behind the key, so permissions come from the role stored on the client
 * itself. Permissions that are scoped to the acting member, the ":own" ones, have nothing to
 * resolve against and are dropped.
 */
class OrganizationApiKeyActor implements Actor
{
    public function __construct(
        public readonly Client $client,
    ) {}

    public function belongsTo(Organization $organization): bool
    {
        return $this->client->isOrganizationApiKey()
            && $this->client->owner_id === $organization->getKey();
    }

    /**
     * @return array<string>
     */
    public function permissionsFor(Organization $organization): array
    {
        if (! $this->belongsTo($organization)) {
            return [];
        }

        $role = $this->client->role;
        if ($role === null) {
            return [];
        }

        return array_values(array_filter(
            PermissionStore::permissionsForRole($role->value),
            fn (string $permission): bool => ! str_ends_with($permission, ':own'),
        ));
    }

    public function cacheKey(): string
    {
        return 'organization-api-key|'.$this->client->getKey();
    }
}
