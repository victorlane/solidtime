<?php

declare(strict_types=1);

return [
    'gotenberg' => [
        'url' => env('GOTENBERG_URL'),
        'basic_auth_username' => env('GOTENBERG_BASIC_AUTH_USERNAME'),
        'basic_auth_password' => env('GOTENBERG_BASIC_AUTH_PASSWORD'),
    ],

    // Generic OIDC / SSO login. See App\Service\Auth\OidcService and
    // App\Service\Auth\OidcUserResolver for how these are used.
    'oidc' => [
        'enabled' => env('OIDC_ENABLED', false),
        'issuer' => env('OIDC_ISSUER'),
        'client_id' => env('OIDC_CLIENT_ID'),
        'client_secret' => env('OIDC_CLIENT_SECRET'),
        // Optional override, otherwise route('oidc.callback') is used.
        'redirect_uri' => env('OIDC_REDIRECT_URI'),
        // Optional override, otherwise "client_secret_basic" (with a secret) or "none" (public client) is used.
        'token_endpoint_auth_method' => env('OIDC_TOKEN_ENDPOINT_AUTH_METHOD'),
        'scopes' => array_values(array_filter(array_map(
            'trim',
            explode(' ', (string) env('OIDC_SCOPES', 'openid profile email'))
        ))),
        'button_label' => env('OIDC_BUTTON_LABEL', 'Continue with SSO'),
        'name_claim' => env('OIDC_NAME_CLAIM', 'name'),
        // Whether previously-unseen OIDC users are allowed to auto-provision a new account/organization.
        'auto_register' => env('OIDC_AUTO_REGISTER', true),
        // Whether an OIDC login is allowed to link to an *existing* local account matched by email
        // (only ever considered when the email is verified, see "require_verified_email" below).
        'auto_link' => env('OIDC_AUTO_LINK', true),
        // SECURITY: keep this true unless you fully understand the risk. When true (the default),
        // an OIDC login is only ever matched to an existing account by email if the identity
        // provider asserts `email_verified: true` for that email. Setting this to false allows an
        // attacker who can create an account at the IdP using a victim's email address (even
        // without proving ownership of it) to be logged into the victim's existing solidtime
        // account. Do not disable this without also adding an explicit account-linking
        // confirmation step.
        'require_verified_email' => env('OIDC_REQUIRE_VERIFIED_EMAIL', true),
        'http_timeout' => env('OIDC_HTTP_TIMEOUT', 10),
    ],
];
