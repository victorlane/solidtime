<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Service\Auth\ActorResolver;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates an API request as either a person or an organization API key.
 *
 * The stock `auth:api` middleware asks the guard whether it has a user, and Passport's token guard
 * deliberately reports none for a client credentials token: there is no person behind one. That is
 * correct for the guard and useless for us, because an organization API key is exactly a request
 * with no person behind it. So the client is checked separately once no user was found.
 *
 * Only organization API keys are accepted. Any other client credentials token, such as one issued
 * to a first party client, is rejected, so this does not quietly widen access to every client that
 * happens to hold the grant.
 */
class AuthenticateApi
{
    public function __construct(
        private readonly ActorResolver $actorResolver,
    ) {}

    /**
     * @param  Closure(Request): (Response)  $next
     *
     * @throws AuthenticationException
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::guard('api')->check()) {
            Auth::shouldUse('api');

            return $next($request);
        }

        // shouldUse has to happen before the actor is resolved, because resolving reads the guard.
        Auth::shouldUse('api');

        if ($this->actorResolver->organizationApiKey() !== null) {
            return $next($request);
        }

        throw new AuthenticationException('Unauthenticated.', ['api']);
    }
}
