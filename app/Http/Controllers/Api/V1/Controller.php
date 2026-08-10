<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Api\OrganizationApiKeyCanNotActOnBehalfOfAUser;
use App\Models\Organization;
use App\Models\User;
use App\Service\Auth\ActorResolver;
use App\Service\BillingContract;
use App\Service\PermissionStore;
use Illuminate\Auth\Access\AuthorizationException;

class Controller extends \App\Http\Controllers\Controller
{
    public function __construct(
        protected PermissionStore $permissionStore,
    ) {}

    /**
     * @throws AuthorizationException
     */
    protected function checkPermission(Organization $organization, string $permission): void
    {
        if (! $this->permissionStore->has($organization, $permission)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  array<string>  $permissions
     *
     * @throws AuthorizationException
     */
    protected function checkAnyPermission(Organization $organization, array $permissions): void
    {
        foreach ($permissions as $permission) {
            if ($this->permissionStore->has($organization, $permission)) {
                return;
            }
        }
        throw new AuthorizationException;
    }

    protected function hasPermission(Organization $organization, string $permission): bool
    {
        return $this->permissionStore->has($organization, $permission);
    }

    /**
     * The person performing this request, for actions that record who performed them.
     *
     * An organization API key acts for the organization and has nobody behind it, so an action
     * that names its actor has no honest answer. Attributing it to whoever created the key would
     * blame a person for something an integration did, and would go stale the moment that person
     * leaves, which is the reason organization API keys exist in the first place. Refusing is the
     * only answer that stays true.
     *
     * @throws OrganizationApiKeyCanNotActOnBehalfOfAUser
     * @throws AuthorizationException
     */
    protected function actingUser(): User
    {
        if (app(ActorResolver::class)->organizationApiKey() !== null) {
            throw new OrganizationApiKeyCanNotActOnBehalfOfAUser;
        }

        return $this->user();
    }

    protected function canAccessPremiumFeatures(Organization $organization): bool
    {
        return app(BillingContract::class)->hasSubscription($organization) || app(BillingContract::class)->hasTrial($organization);
    }
}
