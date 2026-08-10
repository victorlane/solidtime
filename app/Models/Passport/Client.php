<?php

declare(strict_types=1);

namespace App\Models\Passport;

use App\Enums\Role;
use App\Models\Organization;
use Database\Factories\Passport\ClientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Carbon;
use Laravel\Passport\Client as PassportClient;

/**
 * @property string $id
 * @property string|null $owner_id
 * @property string|null $owner_type
 * @property string $name
 * @property string|null $secret
 * @property string|null $provider
 * @property array<string> $grant_types
 * @property array<string> $redirect_uris
 * @property Role|null $role
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $revoked
 *
 * @method Builder<Client> organizationApiKeys()
 */
class Client extends PassportClient
{
    /** @use HasFactory<ClientFactory> */
    use HasFactory;

    /**
     * The roles that an organization API key may be created with.
     *
     * The permissions of every other role are mostly scoped to the member that acts, and an
     * organization API key acts on behalf of the organization instead of a member, so those
     * permissions would have nothing to resolve against.
     *
     * @var array<int, Role>
     */
    public const array ASSIGNABLE_ROLES = [
        Role::Admin,
        Role::Manager,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
        ];
    }

    /**
     * Whether this client is an API key that belongs to an organization instead of a person.
     */
    public function isOrganizationApiKey(): bool
    {
        return $this->owner_type === self::organizationMorphClass()
            && $this->owner_id !== null
            && $this->role !== null
            && $this->hasGrantType('client_credentials');
    }

    /**
     * The value stored in owner_type for an organization.
     *
     * The application enforces a morph map, so this is the alias rather than the class name.
     */
    private static function organizationMorphClass(): string
    {
        return (new Organization)->getMorphClass();
    }

    /**
     * The organization that this API key belongs to, null for every other kind of client.
     *
     * Deliberately not named after the relation it reads. Eloquent treats any method whose name
     * matches an accessed property as a relation and insists on being handed a relation instance,
     * so a method called organization() would make `$client->organization` throw.
     *
     * The inherited owner relation is typed as a user by Passport, so the organization is looked
     * up by key rather than read through it.
     */
    public function ownerOrganization(): ?Organization
    {
        if ($this->owner_type !== self::organizationMorphClass() || $this->owner_id === null) {
            return null;
        }

        return Organization::query()->whereKey($this->owner_id)->first();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOrganizationApiKeys(Builder $query): Builder
    {
        return $query->where('owner_type', '=', self::organizationMorphClass())
            ->whereNotNull('owner_id')
            ->whereNotNull('role')
            ->whereJsonContains('grant_types', 'client_credentials');
    }

    /**
     * Create a new factory instance for the model.
     *
     * @return ClientFactory
     */
    protected static function newFactory(): Factory
    {
        return ClientFactory::new();
    }
}
