<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Enums\RetainerHardCapEnforcement;
use App\Enums\RetainerHardCapScope;
use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Enums\RetainerSubCapMode;
use App\Http\Controllers\Api\V1\TimeEntryController;
use App\Models\Client;
use App\Models\Project;
use App\Models\Retainer;
use App\Models\TimeEntry;
use App\Rules\RetainerCapNotExceeded;
use Illuminate\Support\Carbon;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Hard-cap enforcement is wired in via a FormRequest validation rule
 * ({@see RetainerCapNotExceeded}), not a model observer — see
 * app/Service/Retainer/CapEnforcer.php for the reasoning. These tests exercise it
 * end-to-end through the same store/update endpoints
 * {@see TimeEntryEndpointTest} already covers, rather than
 * unit-testing CapEnforcer in isolation.
 */
#[UsesClass(TimeEntryController::class)]
class RetainerCapEnforcementTest extends ApiEndpointTestAbstract
{
    private function makeClientAndProject(object $data): array
    {
        $client = Client::factory()->forOrganization($data->organization)->create();
        $project = Project::factory()->forOrganization($data->organization)->forClient($client)->create();

        return [$client, $project];
    }

    public function test_store_endpoint_is_blocked_once_retainer_hard_cap_is_exceeded(): void
    {
        $data = $this->createUserWithPermission([
            'time-entries:create:own',
            'projects:view:all',
        ]);
        [$client, $project] = $this->makeClientAndProject($data);

        // Cumulative scope: a fixed budget regardless of how much of the period has
        // elapsed, which keeps this test's expectations independent of proration
        // (proration itself is covered by AllocationCalculatorTest).
        Retainer::factory()->forClient($client)->create([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 100 * 3600,
            'starts_at' => '2026-01-01',
            'billable_only' => false,
            'hard_cap_enabled' => true,
            'hard_cap_scope' => RetainerHardCapScope::Cumulative,
            'hard_cap_cumulative_seconds' => 2 * 3600,
            'hard_cap_enforcement' => RetainerHardCapEnforcement::Block,
        ]);
        // Already 1h tracked this period.
        TimeEntry::factory()->forOrganization($data->organization)->forMember($data->member)->forProject($project)
            ->startWithDuration(Carbon::parse('2026-01-05T09:00:00Z'), 3600)
            ->create();
        Passport::actingAs($data->user);

        // Adding another 1h30 would bring the total to 2h30, past the 2h cumulative cap.
        $response = $this->postJson(route('api.v1.time-entries.store', [$data->organization->getKey()]), [
            'member_id' => $data->member->getKey(),
            'project_id' => $project->getKey(),
            'start' => '2026-01-06T09:00:00Z',
            'end' => '2026-01-06T10:30:00Z',
            'billable' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors([
            'end' => 'Saving this time entry would exceed the retainer cap for this client.',
        ]);
        $this->assertSame(1, TimeEntry::query()->count());
    }

    public function test_store_endpoint_succeeds_when_within_retainer_hard_cap(): void
    {
        $data = $this->createUserWithPermission([
            'time-entries:create:own',
            'projects:view:all',
        ]);
        [$client, $project] = $this->makeClientAndProject($data);

        Retainer::factory()->forClient($client)->create([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 100 * 3600,
            'starts_at' => '2026-01-01',
            'billable_only' => false,
            'hard_cap_enabled' => true,
            'hard_cap_scope' => RetainerHardCapScope::Cumulative,
            'hard_cap_cumulative_seconds' => 2 * 3600,
            'hard_cap_enforcement' => RetainerHardCapEnforcement::Block,
        ]);
        Passport::actingAs($data->user);

        $response = $this->postJson(route('api.v1.time-entries.store', [$data->organization->getKey()]), [
            'member_id' => $data->member->getKey(),
            'project_id' => $project->getKey(),
            'start' => '2026-01-06T09:00:00Z',
            'end' => '2026-01-06T10:00:00Z',
            'billable' => true,
        ]);

        $response->assertStatus(201);
        $this->assertSame(1, TimeEntry::query()->count());
    }

    public function test_store_endpoint_is_not_blocked_when_hard_cap_is_disabled(): void
    {
        $data = $this->createUserWithPermission([
            'time-entries:create:own',
            'projects:view:all',
        ]);
        [$client, $project] = $this->makeClientAndProject($data);

        Retainer::factory()->forClient($client)->create([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 2 * 3600,
            'starts_at' => '2026-01-01',
            'hard_cap_enabled' => false,
        ]);
        Passport::actingAs($data->user);

        $response = $this->postJson(route('api.v1.time-entries.store', [$data->organization->getKey()]), [
            'member_id' => $data->member->getKey(),
            'project_id' => $project->getKey(),
            'start' => '2026-01-06T09:00:00Z',
            'end' => '2026-01-06T15:00:00Z',
            'billable' => true,
        ]);

        $response->assertStatus(201);
    }

    public function test_store_endpoint_is_not_blocked_when_enforcement_mode_is_flag(): void
    {
        // Only "block" enforcement is implemented in v1; flag/approval are a no-op.
        $data = $this->createUserWithPermission([
            'time-entries:create:own',
            'projects:view:all',
        ]);
        [$client, $project] = $this->makeClientAndProject($data);

        Retainer::factory()->forClient($client)->create([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 2 * 3600,
            'starts_at' => '2026-01-01',
            'hard_cap_enabled' => true,
            'hard_cap_scope' => RetainerHardCapScope::PerPeriod,
            'hard_cap_enforcement' => RetainerHardCapEnforcement::Flag,
        ]);
        Passport::actingAs($data->user);

        $response = $this->postJson(route('api.v1.time-entries.store', [$data->organization->getKey()]), [
            'member_id' => $data->member->getKey(),
            'project_id' => $project->getKey(),
            'start' => '2026-01-06T09:00:00Z',
            'end' => '2026-01-06T15:00:00Z',
            'billable' => true,
        ]);

        $response->assertStatus(201);
    }

    public function test_update_endpoint_never_blocks_shrinking_an_entrys_duration(): void
    {
        $data = $this->createUserWithPermission([
            'time-entries:create:own',
            'time-entries:update:own',
            'projects:view:all',
        ]);
        [$client, $project] = $this->makeClientAndProject($data);

        Retainer::factory()->forClient($client)->create([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 2 * 3600,
            'starts_at' => '2026-01-01',
            'billable_only' => false,
            'hard_cap_enabled' => true,
            'hard_cap_scope' => RetainerHardCapScope::PerPeriod,
            'hard_cap_enforcement' => RetainerHardCapEnforcement::Block,
        ]);
        // Already fully at the cap.
        $entry = TimeEntry::factory()->forOrganization($data->organization)->forMember($data->member)->forProject($project)
            ->startWithDuration(Carbon::parse('2026-01-05T09:00:00Z'), 2 * 3600)
            ->create();
        Passport::actingAs($data->user);

        // Shrinking the entry's duration should never be blocked, even though the
        // retainer is already fully (or over) consumed.
        $response = $this->putJson(route('api.v1.time-entries.update', [$data->organization->getKey(), $entry->getKey()]), [
            'end' => '2026-01-05T10:30:00Z',
        ]);

        $response->assertStatus(200);
    }

    public function test_store_endpoint_is_blocked_by_strict_per_project_sub_cap_even_when_parent_cap_has_room(): void
    {
        $data = $this->createUserWithPermission([
            'time-entries:create:own',
            'projects:view:all',
        ]);
        [$client, $project] = $this->makeClientAndProject($data);

        $retainer = Retainer::factory()->forClient($client)->create([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 100 * 3600,
            'starts_at' => '2026-01-01',
            'billable_only' => false,
            'hard_cap_enabled' => true,
            'hard_cap_scope' => RetainerHardCapScope::PerPeriod,
            'hard_cap_enforcement' => RetainerHardCapEnforcement::Block,
            'sub_cap_mode' => RetainerSubCapMode::Strict,
        ]);
        $retainer->projectCaps()->create([
            'project_id' => $project->getKey(),
            'seconds_per_period' => 3600,
        ]);
        Passport::actingAs($data->user);

        // Well within the 100h parent cap, but over the 1h sub-cap for this project.
        $response = $this->postJson(route('api.v1.time-entries.store', [$data->organization->getKey()]), [
            'member_id' => $data->member->getKey(),
            'project_id' => $project->getKey(),
            'start' => '2026-01-06T09:00:00Z',
            'end' => '2026-01-06T11:00:00Z',
            'billable' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['end']);
    }
}
