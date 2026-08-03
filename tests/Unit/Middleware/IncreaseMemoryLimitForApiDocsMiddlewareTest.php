<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\IncreaseMemoryLimitForApiDocs;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Runs in separate processes: `ini_set('memory_limit', '128M')` in the low-limit test below
 * fails if the current process already uses more memory than that, which happens whenever
 * enough other tests have already run in the same PHPUnit worker before this one.
 */
#[CoversClass(IncreaseMemoryLimitForApiDocs::class)]
#[RunTestsInSeparateProcesses]
class IncreaseMemoryLimitForApiDocsMiddlewareTest extends MiddlewareTestAbstract
{
    private string $memoryLimitBeforeTest;

    protected function setUp(): void
    {
        parent::setUp();
        $memoryLimit = ini_get('memory_limit');
        $this->memoryLimitBeforeTest = is_string($memoryLimit) ? $memoryLimit : '-1';
    }

    protected function tearDown(): void
    {
        // Lifting the limit first makes sure PHP never has to lower it below the memory this
        // process currently uses, which it refuses to do.
        ini_set('memory_limit', '-1');
        ini_set('memory_limit', $this->memoryLimitBeforeTest);
        parent::tearDown();
    }

    private function createTestRoute(): string
    {
        $uri = Route::get('/test-route', function () {
            return [
                'memory_limit' => ini_get('memory_limit'),
            ];
        })->middleware(IncreaseMemoryLimitForApiDocs::class)->uri;

        return url($uri, [], false);
    }

    public function test_a_memory_limit_that_is_too_low_is_raised_for_the_request(): void
    {
        // Arrange
        ini_set('memory_limit', '128M');
        $route = $this->createTestRoute();

        // Act
        $response = $this->get($route);

        // Assert
        $response->assertSuccessful();
        $response->assertJson(['memory_limit' => '512M']);
    }

    public function test_a_memory_limit_that_is_already_high_enough_is_left_alone(): void
    {
        // Arrange
        ini_set('memory_limit', '1G');
        $route = $this->createTestRoute();

        // Act
        $response = $this->get($route);

        // Assert
        $response->assertSuccessful();
        $response->assertJson(['memory_limit' => '1G']);
    }

    public function test_an_unlimited_memory_limit_is_left_alone(): void
    {
        // Arrange
        ini_set('memory_limit', '-1');
        $route = $this->createTestRoute();

        // Act
        $response = $this->get($route);

        // Assert
        $response->assertSuccessful();
        $response->assertJson(['memory_limit' => '-1']);
    }
}
