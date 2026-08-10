<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Providers\RouteServiceProvider;
use App\Service\Auth\OidcService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

class OidcController extends Controller
{
    /**
     * Start the OIDC login flow: generate state/nonce/PKCE, store them in the session, and
     * redirect to the identity provider's authorization endpoint.
     */
    public function redirect(Request $request, OidcService $oidc): RedirectResponse
    {
        if (! $oidc->isEnabled()) {
            abort(404);
        }

        try {
            return redirect()->away($oidc->authorizationRedirectUrl($request));
        } catch (Throwable $exception) {
            report($exception);

            return redirect()
                ->route('login')
                ->with('message', __('OIDC login failed. Please try again or contact your administrator.'));
        }
    }

    /**
     * Handle the identity provider's callback: verify state, exchange the code, verify the ID
     * token, resolve/provision the local user, and log them in through the normal web guard.
     */
    public function callback(Request $request, OidcService $oidc): RedirectResponse
    {
        if (! $oidc->isEnabled()) {
            abort(404);
        }

        try {
            $user = $oidc->authenticateCallback($request);
        } catch (Throwable $exception) {
            Log::warning('OIDC login failed.', [
                'exception' => $exception->getMessage(),
            ]);

            return redirect()
                ->route('login')
                ->with('message', __('OIDC login failed. Please try again or contact your administrator.'));
        }

        Auth::guard('web')->login($user, true);
        // Regenerate the session id to prevent session fixation across the OIDC redirect.
        $request->session()->regenerate();

        return redirect()->intended(RouteServiceProvider::HOME);
    }
}
