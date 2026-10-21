<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\ApiDocs;

use App\Console\Commands\ApiDocs\ApiDocsCacheCommand;
use Dedoc\Scramble\Infer\Context;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCaseWithDatabase;

#[CoversClass(ApiDocsCacheCommand::class)]
class ApiDocsCacheCommandTest extends TestCaseWithDatabase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Scramble keeps its inference context in a static property, which would otherwise outlive the refreshed application
        Context::reset();
        config(['scramble.cache.store' => 'array']);
    }

    public function test_command_caches_the_openapi_document(): void
    {
        // Act
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('api-docs:cache');

        // Assert
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertTrue(Cache::store('array')->has('scramble.openapi:default'));
    }

    public function test_command_succeeds_even_if_the_document_can_not_be_cached(): void
    {
        // Arrange
        config(['scramble.cache.store' => 'store-that-does-not-exist']);

        // Act
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('api-docs:cache');

        // Assert
        $this->assertSame(Command::SUCCESS, $exitCode);
    }

    public function test_command_is_part_of_optimize(): void
    {
        // Act
        $exitCode = $this->withoutMockingConsoleOutput()->artisan('optimize', ['--except' => 'config,events,routes,views']);

        // Assert
        $this->assertSame(Command::SUCCESS, $exitCode);
        $this->assertTrue(Cache::store('array')->has('scramble.openapi:default'));
    }
}
