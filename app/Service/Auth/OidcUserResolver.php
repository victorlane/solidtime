<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Enums\Weekday;
use App\Models\User;
use App\Service\UserService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Resolves the local User for a set of verified OIDC ID-token/userinfo claims.
 *
 * This is intentionally split out of OidcService so the account-linking policy - the
 * highest-risk part of OIDC support - can be unit/feature tested directly against a set of
 * claims, without needing to exercise the full OIDC discovery/token-exchange flow against a
 * real (or mocked) identity provider.
 */
class OidcUserResolver
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    /**
     * Resolve the local User for a successfully-verified OIDC login, in order of trust:
     *
     * 1. Match by `oidc_sub` - the only unconditionally safe path, since it can only ever
     *    have been set by a previous successful OIDC login for this exact user.
     * 2. If an *existing* local account has this email, only link to it (setting its
     *    `oidc_sub`) when `services.oidc.auto_link` is enabled AND the IdP asserts
     *    `email_verified: true` for the claimed email (`services.oidc.require_verified_email`,
     *    default true) - otherwise reject outright. This never links against an account
     *    already linked to a *different* `oidc_sub`.
     * 3. Otherwise (no existing account at all), auto-provision a brand-new user via
     *    UserService::createUser() - the same path used by normal self-registration - if
     *    `services.oidc.auto_register` is enabled.
     *
     * @param  array<string, mixed>  $claims
     *
     * @throws RuntimeException when the claims are invalid, or the resulting account-linking
     *                          decision is not permitted by config (see above)
     */
    public function resolve(array $claims): User
    {
        $subject = $this->stringClaim($claims, 'sub');
        if ($subject === null || $subject === '') {
            throw new RuntimeException('OIDC sub claim is missing or empty.');
        }

        $email = Str::lower(trim((string) $this->stringClaim($claims, 'email')));
        if ($email === '') {
            throw new RuntimeException('OIDC email claim is missing or empty.');
        }

        $emailVerified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $requireVerifiedEmail = (bool) config('services.oidc.require_verified_email', true);

        /** @var User|null $user */
        $user = User::query()
            ->active()
            ->where('oidc_sub', $subject)
            ->first();

        if ($user !== null) {
            return $this->syncOidcProfileClaims($user, $this->nameFromClaims($claims, $email), $email, $emailVerified);
        }

        /** @var User|null $existing */
        $existing = User::query()
            ->active()
            ->where('email', $email)
            ->first();

        if ($existing === null) {
            return $this->autoRegister($claims, $subject, $email, $emailVerified);
        }

        if ($existing->oidc_sub !== null && $existing->oidc_sub !== $subject) {
            throw new RuntimeException('This account is already linked to a different OIDC identity.');
        }

        if (! (bool) config('services.oidc.auto_link', true)) {
            throw new RuntimeException('An account with this email already exists and OIDC auto-linking is disabled.');
        }

        if ($requireVerifiedEmail && ! $emailVerified) {
            // Do NOT link to the existing account when the IdP has not verified this email:
            // that would allow an attacker who controls an account at the IdP with a victim's
            // (unverified) email address to be silently logged into the victim's existing
            // local-password account. We reject outright here rather than falling through to
            // auto-registration, since a new account with a duplicate email is not possible
            // (see the partial unique index on users.email) and would be confusing besides.
            throw new RuntimeException('OIDC email is not verified by the identity provider; refusing to link to an existing account.');
        }

        $existing->forceFill(['oidc_sub' => $subject])->save();

        return $this->syncOidcProfileClaims($existing, $this->nameFromClaims($claims, $email), $email, $emailVerified);
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function autoRegister(array $claims, string $subject, string $email, bool $emailVerified): User
    {
        if (! (bool) config('services.oidc.auto_register', true)) {
            throw new RuntimeException('No local account exists for this OIDC identity and auto-registration is disabled.');
        }

        $name = $this->nameFromClaims($claims, $email);

        // UserService::createUser() is the exact same path used by normal self-registration
        // (App\Actions\Fortify\CreateNewUser), including honouring any organization
        // invitation that was already accepted (via the existing signed-URL flow) for this
        // email - we deliberately do not implement any separate/weaker invitation matching.
        $user = $this->userService->createUser(
            $name,
            $email,
            Str::random(64),
            'UTC',
            Weekday::Monday,
            null,
            null,
            null,
            null,
            null,
            null,
            $emailVerified
        );
        $user->forceFill(['oidc_sub' => $subject])->save();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function nameFromClaims(array $claims, string $fallbackEmail): string
    {
        $nameClaim = (string) config('services.oidc.name_claim', 'name');
        $name = trim((string) ($claims[$nameClaim] ?? $claims['name'] ?? $claims['preferred_username'] ?? $fallbackEmail));

        return $name !== '' ? $name : $fallbackEmail;
    }

    private function syncOidcProfileClaims(User $user, string $name, string $email, bool $emailVerified): User
    {
        $updates = [
            'name' => $name,
        ];

        if ($email !== $user->email) {
            $emailTaken = User::query()
                ->active()
                ->where('email', $email)
                ->whereKeyNot($user->getKey())
                ->exists();

            if (! $emailTaken) {
                $updates['email'] = $email;
                $updates['email_verified_at'] = $emailVerified ? now() : null;
            } else {
                Log::warning('Skipped OIDC email sync because email is already in use.', [
                    'user_id' => $user->getKey(),
                ]);
            }
        } elseif ($emailVerified && $user->email_verified_at === null) {
            $updates['email_verified_at'] = now();
        }

        $user->forceFill($updates)->save();

        return $user->refresh();
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function stringClaim(array $claims, string $key): ?string
    {
        return isset($claims[$key]) && is_string($claims[$key]) ? $claims[$key] : null;
    }
}
