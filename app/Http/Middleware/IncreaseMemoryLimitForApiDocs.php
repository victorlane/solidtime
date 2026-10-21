<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Service\MemoryLimit;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Scramble builds the OpenAPI document on every request by statically analysing all API
 * controllers, which currently allocates around 85 MB. That does not fit into the 128 MB of
 * php.ini-production, which the production image uses, so the documentation routes raise the limit
 * for themselves. Without it the documentation would be a 500 for everybody.
 *
 * The previous limit is deliberately not restored afterwards: building the document leaves most of
 * that memory allocated to the process, and PHP refuses to lower `memory_limit` below the memory
 * that is currently in use. On Octane, where the worker goes on to serve further requests, putting
 * the limit back would therefore either fail outright or leave the worker without headroom. A
 * higher limit is only a ceiling and never an allocation, so leaving it raised is the safe
 * direction.
 */
class IncreaseMemoryLimitForApiDocs
{
    public const string REQUIRED_MEMORY_LIMIT = '512M';

    public function handle(Request $request, Closure $next): Response
    {
        MemoryLimit::raiseTo(self::REQUIRED_MEMORY_LIMIT);

        return $next($request);
    }
}
