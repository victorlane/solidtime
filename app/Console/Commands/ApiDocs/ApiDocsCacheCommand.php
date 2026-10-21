<?php

declare(strict_types=1);

namespace App\Console\Commands\ApiDocs;

use App\Http\Middleware\IncreaseMemoryLimitForApiDocs;
use App\Service\MemoryLimit;
use Illuminate\Console\Command;
use Throwable;

/**
 * Warms Scramble's cache of the OpenAPI document, so `/docs/api` and `/docs/api.json` no longer
 * analyse every API controller on each request.
 *
 * Registered as an `optimize` task (see AppServiceProvider), which the production container runs on
 * every start. That container exits on the first failing command, so this command never fails: if
 * the document can not be built, the documentation routes keep generating it per request, exactly
 * as without a cache. The memory limit is raised first because a memory exhaustion is a fatal error
 * that no catch block can recover from.
 */
class ApiDocsCacheCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-docs:cache';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Cache the generated OpenAPI document without ever failing';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        MemoryLimit::raiseTo(IncreaseMemoryLimitForApiDocs::REQUIRED_MEMORY_LIMIT);

        try {
            $exitCode = $this->call('scramble:cache');
        } catch (Throwable $exception) {
            report($exception);
            $this->warn('Could not cache the API documentation, it will be generated per request: '.$exception->getMessage());

            return self::SUCCESS;
        }

        if ($exitCode !== self::SUCCESS) {
            $this->warn('Could not cache the API documentation, it will be generated per request.');
        }

        return self::SUCCESS;
    }
}
