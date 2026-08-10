<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Passport\Token;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Passport\AccessToken;
use Symfony\Component\HttpFoundation\Response;

class UpdateApiTokenLastUsedAt
{
    /**
     * How long a recorded usage of an API token stays fresh.
     *
     * Without this, every single API request would cause a write. A resolution of one minute is
     * more than enough to tell an unused API token apart from one that is still in use.
     */
    private const int FRESH_FOR_IN_SECONDS = 60;

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $this->recordUsage($request);

        return $next($request);
    }

    private function recordUsage(Request $request): void
    {
        $user = $request->user();
        if (! ($user instanceof User)) {
            return;
        }

        $token = $user->token();
        // Requests that are authenticated with the session cookie use a transient token that does not exist in the database.
        if (! ($token instanceof AccessToken)) {
            return;
        }

        // Access tokens that did not come from the OAuth server, f.e. the ones faked in tests, have no database row.
        $tokenId = $token->toArray()['oauth_access_token_id'] ?? null;
        if (! is_string($tokenId)) {
            return;
        }

        $now = Carbon::now();
        Token::withoutTimestamps(function () use ($tokenId, $now): void {
            Token::query()
                ->whereKey($tokenId)
                ->where(function (Builder $builder) use ($now): void {
                    /** @var Builder<Token> $builder */
                    $builder->whereNull('last_used_at')
                        ->orWhere('last_used_at', '<=', $now->copy()->subSeconds(self::FRESH_FOR_IN_SECONDS));
                })
                ->update([
                    'last_used_at' => $now,
                ]);
        });
    }
}
