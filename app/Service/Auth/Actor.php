<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Models\Organization;

/**
 * Whoever is acting on the current request.
 *
 * Requests are authenticated either as a person, who acts through their membership of an
 * organization, or as an organization API key, which acts on behalf of the organization itself and
 * has no member behind it. Both answer the same two questions, so permission checks do not have to
 * care which one they are dealing with.
 */
interface Actor
{
    /**
     * Whether this actor is entitled to act within the given organization at all.
     */
    public function belongsTo(Organization $organization): bool;

    /**
     * The permissions this actor has within the given organization, empty if it has none.
     *
     * @return array<string>
     */
    public function permissionsFor(Organization $organization): array;

    /**
     * A stable key for this actor, used to cache resolved permissions per request.
     */
    public function cacheKey(): string;
}
