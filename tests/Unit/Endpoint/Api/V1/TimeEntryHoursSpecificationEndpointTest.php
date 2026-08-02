<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Enums\CurrencyFormat;
use App\Enums\DateFormat;
use App\Enums\IntervalFormat;
use App\Enums\NumberFormat;
use App\Enums\Role;
use App\Http\Controllers\Api\V1\TimeEntryController;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Task;
use App\Models\TimeEntry;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\UsesClass;

#[UsesClass(TimeEntryController::class)]
class TimeEntryHoursSpecificationEndpointTest extends ApiEndpointTestAbstract
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * The PDF render goes through a real Gotenberg instance (CI runs it as a service container,
     * locally it comes from docker-compose). Without it the endpoint returns a bare 400 that looks
     * like a code failure, so skip with a reason instead. Everything that is about the content of
     * the document uses `debug=true` and does not need Gotenberg at all.
     */
    private function skipIfPdfRendererIsNotConfigured(): void
    {
        if (config('services.gotenberg.url') === null) {
            $this->markTestSkipped('GOTENBERG_URL is not set — start Gotenberg (see .env.ci) to run the PDF export tests.');
        }
    }

    /**
     * The organization factory randomises the display formats, which would make every assertion on
     * a rendered duration or amount a coin flip. Pin them so the expected strings are stable.
     */
    private function pinDisplayFormats(Organization $organization): void
    {
        $organization->currency = 'EUR';
        $organization->number_format = NumberFormat::ThousandsCommaDecimalPoint;
        $organization->currency_format = CurrencyFormat::ISOCodeAfterWithSpace;
        $organization->interval_format = IntervalFormat::HoursMinutesSecondsColonSeparated;
        $organization->date_format = DateFormat::PointSeparatedDMYYYY;
        $organization->save();
    }

    /**
     * @param  array<string, mixed>  $queryParameters
     */
    private function requestSpecification(string $organizationId, array $queryParameters = []): TestResponse
    {
        return $this->getJson(route('api.v1.time-entries.hours-specification-export', array_merge([
            $organizationId,
            'start' => Carbon::now()->startOfYear()->toIso8601ZuluString(),
            'end' => Carbon::now()->endOfYear()->toIso8601ZuluString(),
            'debug' => 'true',
        ], $queryParameters)));
    }

    private function getDebugHtml(TestResponse $response): string
    {
        $content = $response->json('html');
        $this->assertIsString($content);

        return $content;
    }

    public function test_hours_specification_export_endpoint_fails_if_user_has_no_permission_to_view_time_entries(): void
    {
        // Arrange
        $data = $this->createUserWithPermission();
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithSubscription();
        $this->pinDisplayFormats($data->organization);

        // Act
        $response = $this->requestSpecification($data->organization->getKey());

        // Assert
        $this->assertResponseCode($response, 403);
    }

    public function test_hours_specification_export_endpoint_fails_if_organization_has_no_subscription(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:view:all',
        ]);
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithoutSubscriptionAndWithoutTrial();

        // Act
        $response = $this->requestSpecification($data->organization->getKey());

        // Assert
        $response->assertStatus(400);
        $response->assertExactJson([
            'error' => true,
            'key' => 'feature_is_not_available_in_free_plan',
            'message' => 'Feature is not available in free plan',
        ]);
    }

    public function test_hours_specification_export_endpoint_renders_the_organization_the_period_and_the_projects(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:view:all',
        ]);
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithSubscription();
        $this->pinDisplayFormats($data->organization);
        $client = Client::factory()->forOrganization($data->organization)->create(['name' => 'Acme BV']);
        $project = Project::factory()->forOrganization($data->organization)->forClient($client)->create(['name' => 'Platform migration']);
        $task = Task::factory()->forOrganization($data->organization)->forProject($project)->create(['name' => 'Data import']);
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forTask($task)
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(10)->setTime(9, 0), 3600)
            ->create(['description' => 'Mapped the legacy tables']);

        // Act
        $response = $this->requestSpecification($data->organization->getKey());

        // Assert
        $this->assertResponseCode($response, 200);
        $html = $this->getDebugHtml($response);
        $this->assertStringContainsString($data->organization->name, $html);
        $this->assertStringContainsString('Hours specification', $html);
        $this->assertStringContainsString('Platform migration', $html);
        $this->assertStringContainsString('Acme BV', $html);
        $this->assertStringContainsString('Data import', $html);
        $this->assertStringContainsString('Mapped the legacy tables', $html);
        // One hour of work, in the interval format of the organization and as decimal hours
        $this->assertStringContainsString('1:00:00', $html);
        $this->assertStringContainsString('1.00', $html);
        $this->assertIsString($response->json('footer_html'));
        $this->assertStringContainsString('totalPages', (string) $response->json('footer_html'));
    }

    public function test_hours_specification_export_endpoint_excludes_time_entries_of_internal_projects(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:view:all',
        ]);
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithSubscription();
        $this->pinDisplayFormats($data->organization);
        $clientProject = Project::factory()->forOrganization($data->organization)->create(['name' => 'Client work']);
        $internalProject = Project::factory()->forOrganization($data->organization)->create([
            'name' => 'Own bookkeeping',
            'is_internal' => true,
        ]);
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forProject($clientProject)
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(3)->setTime(9, 0), 3600)
            ->create();
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forProject($internalProject)
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(4)->setTime(9, 0), 7200)
            ->create();

        // Act
        $response = $this->requestSpecification($data->organization->getKey());

        // Assert
        $this->assertResponseCode($response, 200);
        $html = $this->getDebugHtml($response);
        $this->assertStringContainsString('Client work', $html);
        $this->assertStringNotContainsString('Own bookkeeping', $html);
        // Only the client hour is totalled, the two internal hours are not
        $this->assertStringContainsString('1:00:00', $html);
        $this->assertStringNotContainsString('3:00:00', $html);
    }

    public function test_hours_specification_export_endpoint_never_includes_an_internal_project_even_if_it_is_explicitly_requested(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:view:all',
        ]);
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithSubscription();
        $this->pinDisplayFormats($data->organization);
        // Internal work can have a client attached and a description that names one, so nothing
        // about it may reach the document — not the project, not the client, not the hours.
        $client = Client::factory()->forOrganization($data->organization)->create(['name' => 'Interne Klant']);
        $internalProject = Project::factory()->forOrganization($data->organization)->forClient($client)->create([
            'name' => 'Interne Uren',
            'is_internal' => true,
        ]);
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forProject($internalProject)
            ->billableRate(10000)
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(4)->setTime(9, 0), 7200)
            ->create(['description' => 'Eigen administratie']);

        // Act
        $response = $this->requestSpecification($data->organization->getKey(), [
            'project_ids' => [$internalProject->getKey()],
            'include_amounts' => 'true',
        ]);

        // Assert
        $this->assertResponseCode($response, 200);
        $html = $this->getDebugHtml($response);
        $this->assertStringNotContainsString('Interne Uren', $html);
        $this->assertStringNotContainsString('Interne Klant', $html);
        $this->assertStringNotContainsString('Eigen administratie', $html);
        $this->assertStringNotContainsString('2:00:00', $html);
        $this->assertStringNotContainsString('200.00', $html);
        // The document is empty, and its total is zero rather than the internal two hours
        $this->assertStringContainsString('No hours were tracked in this period.', $html);
        $this->assertStringContainsString('0:00:00', $html);
    }

    public function test_hours_specification_export_endpoint_excludes_breaks_and_running_timers(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:view:all',
        ]);
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithSubscription();
        $this->pinDisplayFormats($data->organization);
        $project = Project::factory()->forOrganization($data->organization)->create(['name' => 'Client work']);
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forProject($project)
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(3)->setTime(9, 0), 3600)
            ->create();
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->isBreak()
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(3)->setTime(12, 0), 1800)
            ->create(['description' => 'Lunch break']);
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forProject($project)
            ->active()
            ->start(Carbon::now()->startOfYear()->addDays(5)->setTime(9, 0))
            ->create(['description' => 'Still running']);

        // Act
        $response = $this->requestSpecification($data->organization->getKey());

        // Assert
        $this->assertResponseCode($response, 200);
        $html = $this->getDebugHtml($response);
        $this->assertStringNotContainsString('Lunch break', $html);
        $this->assertStringNotContainsString('Still running', $html);
        $this->assertStringContainsString('1:00:00', $html);
    }

    public function test_hours_specification_export_endpoint_collapses_multiple_time_entries_of_one_day_into_one_row(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:view:all',
        ]);
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithSubscription();
        $this->pinDisplayFormats($data->organization);
        $project = Project::factory()->forOrganization($data->organization)->create(['name' => 'Client work']);
        $day = Carbon::now()->startOfYear()->addDays(3);
        foreach ([9, 11, 14] as $hour) {
            TimeEntry::factory()
                ->forOrganization($data->organization)
                ->forMember($data->member)
                ->forProject($project)
                ->startWithDuration($day->copy()->setTime($hour, 0), 3600)
                ->create(['description' => 'Refactoring']);
        }

        // Act
        $response = $this->requestSpecification($data->organization->getKey());

        // Assert
        $this->assertResponseCode($response, 200);
        $html = $this->getDebugHtml($response);
        // Three timer runs on one day become one row of three hours, and the description is not repeated
        $this->assertSame(1, substr_count($html, 'Refactoring'));
        $this->assertStringContainsString('3:00:00', $html);
        $this->assertStringContainsString('1 day', $html);
    }

    public function test_hours_specification_export_endpoint_can_be_scoped_to_one_client(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:view:all',
        ]);
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithSubscription();
        $this->pinDisplayFormats($data->organization);
        $clientA = Client::factory()->forOrganization($data->organization)->create(['name' => 'Acme BV']);
        $clientB = Client::factory()->forOrganization($data->organization)->create(['name' => 'Globex NV']);
        $projectA = Project::factory()->forOrganization($data->organization)->forClient($clientA)->create(['name' => 'Acme platform']);
        $projectB = Project::factory()->forOrganization($data->organization)->forClient($clientB)->create(['name' => 'Globex portal']);
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forProject($projectA)
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(3)->setTime(9, 0), 3600)
            ->create();
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forProject($projectB)
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(4)->setTime(9, 0), 3600)
            ->create();

        // Act
        $response = $this->requestSpecification($data->organization->getKey(), [
            'client_ids' => [$clientA->getKey()],
        ]);

        // Assert
        $this->assertResponseCode($response, 200);
        $html = $this->getDebugHtml($response);
        $this->assertStringContainsString('Acme platform', $html);
        $this->assertStringNotContainsString('Globex portal', $html);
        $this->assertStringNotContainsString('Globex NV', $html);
    }

    public function test_hours_specification_export_endpoint_shows_amounts_if_they_are_requested(): void
    {
        // Arrange
        $data = $this->createUserWithRole(Role::Admin);
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithSubscription();
        $this->pinDisplayFormats($data->organization);
        $project = Project::factory()->forOrganization($data->organization)->create(['name' => 'Client work']);
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forProject($project)
            ->billableRate(10000)
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(3)->setTime(9, 0), 3600)
            ->create();

        // Act
        $response = $this->requestSpecification($data->organization->getKey(), [
            'include_amounts' => 'true',
        ]);

        // Assert
        $this->assertResponseCode($response, 200);
        $html = $this->getDebugHtml($response);
        $this->assertStringContainsString('Amount', $html);
        $this->assertStringContainsString('100.00', $html);
    }

    public function test_hours_specification_export_endpoint_does_not_show_amounts_by_default(): void
    {
        // Arrange
        $data = $this->createUserWithRole(Role::Admin);
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithSubscription();
        $this->pinDisplayFormats($data->organization);
        $project = Project::factory()->forOrganization($data->organization)->create(['name' => 'Client work']);
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forProject($project)
            ->billableRate(10000)
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(3)->setTime(9, 0), 3600)
            ->create();

        // Act
        $response = $this->requestSpecification($data->organization->getKey());

        // Assert
        $this->assertResponseCode($response, 200);
        $html = $this->getDebugHtml($response);
        $this->assertStringNotContainsString('Amount', $html);
        $this->assertStringNotContainsString('100.00', $html);
    }

    public function test_hours_specification_export_endpoint_hides_amounts_from_employees_that_may_not_see_billable_rates(): void
    {
        // Arrange
        $data = $this->createUserWithRole(Role::Employee, false);
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithSubscription();
        $this->pinDisplayFormats($data->organization);
        $project = Project::factory()->forOrganization($data->organization)->create(['name' => 'Client work']);
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forProject($project)
            ->billableRate(10000)
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(3)->setTime(9, 0), 3600)
            ->create();

        // Act
        $response = $this->requestSpecification($data->organization->getKey(), [
            'member_id' => $data->member->getKey(),
            'include_amounts' => 'true',
        ]);

        // Assert
        $this->assertResponseCode($response, 200);
        $html = $this->getDebugHtml($response);
        $this->assertStringNotContainsString('Amount', $html);
        $this->assertStringNotContainsString('100.00', $html);
    }

    public function test_hours_specification_export_endpoint_shows_amounts_to_employees_if_the_organization_allows_it(): void
    {
        // Arrange
        $data = $this->createUserWithRole(Role::Employee, true);
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithSubscription();
        $this->pinDisplayFormats($data->organization);
        $project = Project::factory()->forOrganization($data->organization)->create(['name' => 'Client work']);
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forProject($project)
            ->billableRate(10000)
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(3)->setTime(9, 0), 3600)
            ->create();

        // Act
        $response = $this->requestSpecification($data->organization->getKey(), [
            'member_id' => $data->member->getKey(),
            'include_amounts' => 'true',
        ]);

        // Assert
        $this->assertResponseCode($response, 200);
        $html = $this->getDebugHtml($response);
        $this->assertStringContainsString('100.00', $html);
    }

    public function test_hours_specification_export_endpoint_shows_a_reference_in_the_header(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:view:all',
        ]);
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithSubscription();
        $this->pinDisplayFormats($data->organization);

        // Act
        $response = $this->requestSpecification($data->organization->getKey(), [
            'reference' => 'Invoice 2026-014',
        ]);

        // Assert
        $this->assertResponseCode($response, 200);
        $html = $this->getDebugHtml($response);
        $this->assertStringContainsString('Invoice 2026-014', $html);
        $this->assertStringContainsString('No hours were tracked in this period.', $html);
    }

    public function test_hours_specification_export_endpoint_can_filter_out_hours_that_were_already_invoiced(): void
    {
        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:view:all',
        ]);
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithSubscription();
        $this->pinDisplayFormats($data->organization);
        $project = Project::factory()->forOrganization($data->organization)->create(['name' => 'Client work']);
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forProject($project)
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(3)->setTime(9, 0), 3600)
            ->create(['description' => 'Not invoiced yet', 'metadata' => null]);
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forProject($project)
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(4)->setTime(9, 0), 3600)
            ->create(['description' => 'Already on invoice 13', 'metadata' => ['invoice_id' => '13']]);

        // Act
        $response = $this->requestSpecification($data->organization->getKey(), [
            'metadata_key' => 'invoice_id',
            'metadata_exists' => 'false',
        ]);

        // Assert
        $this->assertResponseCode($response, 200);
        $html = $this->getDebugHtml($response);
        $this->assertStringContainsString('Not invoiced yet', $html);
        $this->assertStringNotContainsString('Already on invoice 13', $html);
    }

    public function test_hours_specification_export_endpoint_creates_a_pdf(): void
    {
        $this->skipIfPdfRendererIsNotConfigured();

        // Arrange
        $data = $this->createUserWithPermission([
            'time-entries:view:all',
        ]);
        Passport::actingAs($data->user);
        $this->actAsOrganizationWithSubscription();
        $this->pinDisplayFormats($data->organization);
        $client = Client::factory()->forOrganization($data->organization)->create();
        $project = Project::factory()->forOrganization($data->organization)->forClient($client)->create();
        TimeEntry::factory()
            ->forOrganization($data->organization)
            ->forMember($data->member)
            ->forProject($project)
            ->startWithDuration(Carbon::now()->startOfYear()->addDays(3)->setTime(9, 0), 3600)
            ->create();

        // Act
        $response = $this->requestSpecification($data->organization->getKey(), [
            'debug' => 'false',
        ]);

        // Assert
        $this->assertResponseCode($response, 200);
        $this->assertIsString($response->json('download_url'));
        $disk = Storage::disk(config('filesystems.private'));
        $files = $disk->files('exports');
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('exports/hours-specification-', $files[0]);
        $this->assertStringStartsWith('%PDF', $disk->get($files[0]));
    }
}
