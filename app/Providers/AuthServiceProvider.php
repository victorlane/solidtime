<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Passport\AuthCode;
use App\Models\Passport\Client;
use App\Models\Passport\RefreshToken;
use App\Models\Passport\Token;
use App\Models\User;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Laravel\Passport\Passport;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        // Gate used by Scramble's RestrictedDocsAccess middleware to guard `/docs/api` and
        // `/docs/api.json`. The generated OpenAPI document only describes the public API surface,
        // which every role (owner, manager, employee) is allowed to use with a personal access
        // token, so every member of an organization may read it. The `User` type hint makes the
        // gate deny guests automatically, and the docs routes additionally require a session.
        Gate::define('viewApiDocs', function (User $user): bool {
            return $user->organizations()->exists();
        });

        // define scopes for passport tokens
        Passport::tokensCan([
            'create' => 'Create resources',
            'read' => 'Read Resources',
            'update' => 'Update Resources',
            'delete' => 'Delete Resources',
        ]);

        // default scope for passport tokens
        Passport::setDefaultScope([
            // 'create',
            'read',
            // 'update',
            // 'delete',
        ]);

        Passport::useTokenModel(Token::class);
        Passport::useRefreshTokenModel(RefreshToken::class);
        Passport::useAuthCodeModel(AuthCode::class);
        Passport::useClientModel(Client::class);

        Passport::authorizationView('auth.oauth.authorize');

        // Passport::tokensExpireIn(now()->addDays(15));
        // Passport::refreshTokensExpireIn(now()->addDays(30));
        Passport::personalAccessTokensExpireIn(now()->addMonths(12));
    }
}
