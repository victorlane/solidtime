<?php

declare(strict_types=1);

use App\Extensions\Scramble\ApiExceptionTypeToSchema;
use App\Extensions\Scramble\PaginatedResourceCollectionTypeToSchema;
use App\Http\Middleware\IncreaseMemoryLimitForApiDocs;
use Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess;

return [
    /*
     * Your API path. By default, all routes starting with this path will be added to the docs.
     * If you need to change this behavior, you can add your custom routes resolver using `Scramble::routes()`.
     */
    'api_path' => 'api',

    /*
     * Your API domain. By default, app domain is used. This is also a part of the default API routes
     * matcher, so when implementing your own, make sure you use this config if needed.
     */
    'api_domain' => null,

    'info' => [
        /*
         * API version.
         */
        'version' => '0.0.1',

        /*
         * Description rendered on the home page of the API documentation (`/docs/api`).
         */
        'description' => '',
    ],

    /*
     * Customize Stoplight Elements UI
     */
    'ui' => [
        /*
         * Hide the `Try It` feature. Enabled by default.
         */
        'hide_try_it' => false,

        /*
         * URL to an image that displays as a small square logo next to the title, above the table of contents.
         */
        'logo' => '',

        /*
         * Use to fetch the credential policy for the Try It feature. Options are: omit, include (default), and same-origin
         */
        'try_it_credentials_policy' => 'include',
    ],

    /*
     * The list of servers of the API. By default, when `null`, server URL will be created from
     * `scramble.api_path` and `scramble.api_domain` config variables. When providing an array, you
     * will need to specify the local server URL manually (if needed).
     *
     * Example of non-default config (final URLs are generated using Laravel `url` helper):
     *
     * ```php
     * 'servers' => [
     *     'Live' => 'api',
     *     'Prod' => 'https://scramble.dedoc.co/api',
     * ],
     * ```
     */
    'servers' => [
        'Production' => 'https://app.solidtime.io/api',
        'Staging' => 'https://app.staging.solidtime.io/api',
        'Local' => 'https://solidtime.test/api',
    ],

    /*
     * Middleware applied to the API documentation routes (`/docs/api` and `/docs/api.json`).
     *
     * The documentation describes the same API that every organization member can call with their
     * own token, so it is served to signed in members instead of being restricted to the local
     * environment. The session based stack below mirrors the authenticated web routes, and
     * `RestrictedDocsAccess` additionally checks the `viewApiDocs` gate (see AuthServiceProvider),
     * so guests can never reach the UI or the generated OpenAPI document.
     */
    'middleware' => [
        'web',
        'auth:web',
        'auth.session',
        'verified',
        RestrictedDocsAccess::class,
        IncreaseMemoryLimitForApiDocs::class,
    ],

    'extensions' => [
        ApiExceptionTypeToSchema::class,
        PaginatedResourceCollectionTypeToSchema::class,
    ],
];
