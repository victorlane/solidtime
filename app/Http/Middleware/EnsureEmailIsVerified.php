<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Service\Auth\ActorResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmailIsVerified
{
    public function __construct(
        private readonly ActorResolver $actorResolver,
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null): Response
    {
        // An organization API key acts for the organization, not for a person, so there is no
        // address to have verified and nothing to send a verification link to.
        if ($this->actorResolver->organizationApiKey() !== null) {
            return $next($request);
        }

        if (! app()->isLocal() || config('app.local_email_verification')) {
            if ($request->user() === null ||
                (! $request->user()->hasVerifiedEmail())) {
                return $request->expectsJson()
                    ? abort(403, 'Your email address is not verified.')
                    : Redirect::guest(URL::route($redirectToRoute ?: 'verification.notice'));
            }
        }

        return $next($request);
    }
}
