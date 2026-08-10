<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Models\Passport\Client;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Guards\TokenGuard;

/**
 * Works out who is acting on the current request.
 *
 * A person always wins over a client. Requests from the first party frontend carry both a session
 * user and the first party OAuth client, and they must keep resolving to the user, so the client is
 * only considered once no user is authenticated.
 */
class ActorResolver
{
    /**
     * Deliberately not memoised. The container binds this per request, but a single test boots the
     * application once and sends several requests through it, so a remembered actor would outlive
     * the authentication that produced it. Both sources below already cache within a request: the
     * guard holds its user and the token guard holds its client.
     */
    public function resolve(): ?Actor
    {
        $user = Auth::user();
        if ($user instanceof User) {
            return new UserActor($user);
        }

        $client = $this->currentClient();
        if ($client !== null && $client->isOrganizationApiKey()) {
            return new OrganizationApiKeyActor($client);
        }

        return null;
    }

    /**
     * The organization API key that authenticated the current request, if any.
     */
    public function organizationApiKey(): ?Client
    {
        $actor = $this->resolve();

        return $actor instanceof OrganizationApiKeyActor ? $actor->client : null;
    }

    private function currentClient(): ?Client
    {
        $guard = Auth::guard('api');
        if (! ($guard instanceof TokenGuard)) {
            return null;
        }

        $client = $guard->client();

        return $client instanceof Client ? $client : null;
    }
}
