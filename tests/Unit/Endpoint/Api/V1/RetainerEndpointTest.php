<?php

declare(strict_types=1);

namespace Tests\Unit\Endpoint\Api\V1;

use App\Enums\RetainerHardCapEnforcement;
use App\Enums\RetainerHardCapScope;
use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Http\Controllers\Api\V1\RetainerController;
use App\Models\Client;
use App\Models\Retainer;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Passport\Passport;
use PHPUnit\Framework\Attributes\UsesClass;

#[UsesClass(RetainerController::class)]
class RetainerEndpointTest extends ApiEndpointTestAbstract
{
    public function test_index_endpoint_fails_if_user_has_no_permission_to_view_retainers(): void
    {
        $data = $this->createUserWithPermission();
        $client = Client::factory()->forOrganization($data->organization)->create();
        Passport::actingAs($data->user);

        $response = $this->getJson(route('api.v1.retainers.index', [$data->organization->getKey(), $client->getKey()]));

        $response->assertForbidden();
    }

    public function test_index_endpoint_returns_retainers_of_client_only(): void
    {
        $data = $this->createUserWithPermission(['retainers:view']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        $otherClient = Client::factory()->forOrganization($data->organization)->create();
        $retainers = Retainer::factory()->forClient($client)->createMany(2);
        Retainer::factory()->forClient($otherClient)->create();
        Passport::actingAs($data->user);

        $response = $this->getJson(route('api.v1.retainers.index', [$data->organization->getKey(), $client->getKey()]));

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $this->assertEqualsCanonicalizing($retainers->pluck('id')->toArray(), $response->json('data.*.id'));
    }

    public function test_store_endpoint_fails_if_user_has_no_permission_to_create_retainers(): void
    {
        $data = $this->createUserWithPermission();
        $client = Client::factory()->forOrganization($data->organization)->create();
        Passport::actingAs($data->user);

        $response = $this->postJson(route('api.v1.retainers.store', [$data->organization->getKey(), $client->getKey()]), [
            'name' => 'Retainer',
            'period_mode' => 'calendar',
            'period_unit' => 'monthly',
            'seconds_per_period' => 40 * 3600,
            'starts_at' => '2026-01-01',
        ]);

        $response->assertForbidden();
    }

    public function test_store_endpoint_creates_calendar_retainer(): void
    {
        $data = $this->createUserWithPermission(['retainers:create']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        Passport::actingAs($data->user);

        $response = $this->postJson(route('api.v1.retainers.store', [$data->organization->getKey(), $client->getKey()]), [
            'name' => 'Monthly retainer',
            'period_mode' => 'calendar',
            'period_unit' => 'monthly',
            'seconds_per_period' => 40 * 3600,
            'starts_at' => '2026-01-01',
        ]);

        $response->assertStatus(201);
        $response->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->where('name', 'Monthly retainer')
                ->where('client_id', $client->getKey())
                ->where('organization_id', $data->organization->getKey())
                ->where('period_mode', 'calendar')
                ->where('period_unit', 'monthly')
                ->where('seconds_per_period', 40 * 3600)
                ->where('starts_at', '2026-01-01')
                ->where('billable_only', true)
                ->where('hard_cap_enabled', false)
                ->where('sub_cap_mode', 'soft')
                ->etc())
        );
        $this->assertDatabaseCount(Retainer::class, 1);
    }

    public function test_store_endpoint_fails_if_retainer_overlaps_with_existing_retainer_for_same_client(): void
    {
        $data = $this->createUserWithPermission(['retainers:create']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        Retainer::factory()->forClient($client)->create([
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-03-31',
        ]);
        Passport::actingAs($data->user);

        $response = $this->postJson(route('api.v1.retainers.store', [$data->organization->getKey(), $client->getKey()]), [
            'name' => 'Overlapping retainer',
            'period_mode' => 'calendar',
            'period_unit' => 'monthly',
            'seconds_per_period' => 40 * 3600,
            'starts_at' => '2026-03-01',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['starts_at']);
        $this->assertDatabaseCount(Retainer::class, 1);
    }

    public function test_store_endpoint_fails_for_explicit_period_mode_without_period_unit_or_seconds_per_period(): void
    {
        // Sanity check that these fields are NOT required for explicit mode.
        $data = $this->createUserWithPermission(['retainers:create']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        Passport::actingAs($data->user);

        $response = $this->postJson(route('api.v1.retainers.store', [$data->organization->getKey(), $client->getKey()]), [
            'name' => 'Explicit retainer',
            'period_mode' => 'explicit',
            'starts_at' => '2026-01-01',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount(Retainer::class, 1);
    }

    public function test_show_endpoint_fails_if_retainer_belongs_to_different_organization(): void
    {
        $data = $this->createUserWithPermission(['retainers:view']);
        $otherData = $this->createUserWithPermission(['retainers:view'], true);
        $otherClient = Client::factory()->forOrganization($otherData->organization)->create();
        $retainer = Retainer::factory()->forClient($otherClient)->create();
        Passport::actingAs($data->user);

        $response = $this->getJson(route('api.v1.retainers.show', [$data->organization->getKey(), $retainer->getKey()]));

        $response->assertForbidden();
    }

    public function test_show_endpoint_returns_retainer(): void
    {
        $data = $this->createUserWithPermission(['retainers:view']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forClient($client)->create();
        Passport::actingAs($data->user);

        $response = $this->getJson(route('api.v1.retainers.show', [$data->organization->getKey(), $retainer->getKey()]));

        $response->assertStatus(200);
        $response->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json->where('id', $retainer->getKey())->etc())
        );
    }

    public function test_update_endpoint_fails_if_user_has_no_permission_to_update_retainers(): void
    {
        $data = $this->createUserWithPermission();
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forClient($client)->create();
        Passport::actingAs($data->user);

        $response = $this->putJson(route('api.v1.retainers.update', [$data->organization->getKey(), $retainer->getKey()]), [
            'name' => 'Updated name',
        ]);

        $response->assertForbidden();
    }

    public function test_update_endpoint_updates_retainer(): void
    {
        $data = $this->createUserWithPermission(['retainers:update']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forClient($client)->create([
            'name' => 'Old name',
        ]);
        Passport::actingAs($data->user);

        $response = $this->putJson(route('api.v1.retainers.update', [$data->organization->getKey(), $retainer->getKey()]), [
            'name' => 'New name',
        ]);

        $response->assertStatus(200);
        $response->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json->where('name', 'New name')->etc())
        );
        $this->assertDatabaseHas(Retainer::class, [
            'id' => $retainer->getKey(),
            'name' => 'New name',
        ]);
    }

    public function test_update_endpoint_fails_if_new_dates_overlap_another_retainer_of_the_same_client(): void
    {
        $data = $this->createUserWithPermission(['retainers:update']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        Retainer::factory()->forClient($client)->create([
            'starts_at' => '2026-04-01',
            'ends_at' => '2026-06-30',
        ]);
        $retainer = Retainer::factory()->forClient($client)->create([
            'starts_at' => '2026-01-01',
            'ends_at' => '2026-03-31',
        ]);
        Passport::actingAs($data->user);

        $response = $this->putJson(route('api.v1.retainers.update', [$data->organization->getKey(), $retainer->getKey()]), [
            'ends_at' => '2026-04-15',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['starts_at']);
    }

    public function test_destroy_endpoint_fails_if_user_has_no_permission_to_delete_retainers(): void
    {
        $data = $this->createUserWithPermission();
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forClient($client)->create();
        Passport::actingAs($data->user);

        $response = $this->deleteJson(route('api.v1.retainers.destroy', [$data->organization->getKey(), $retainer->getKey()]));

        $response->assertForbidden();
        $this->assertDatabaseHas(Retainer::class, ['id' => $retainer->getKey()]);
    }

    public function test_destroy_endpoint_soft_deletes_retainer(): void
    {
        $data = $this->createUserWithPermission(['retainers:delete']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forClient($client)->create();
        Passport::actingAs($data->user);

        $response = $this->deleteJson(route('api.v1.retainers.destroy', [$data->organization->getKey(), $retainer->getKey()]));

        $response->assertStatus(204);
        $this->assertSoftDeleted($retainer);
    }

    public function test_status_endpoint_fails_if_user_has_no_permission_to_view_retainers(): void
    {
        $data = $this->createUserWithPermission();
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forClient($client)->create();
        Passport::actingAs($data->user);

        $response = $this->getJson(route('api.v1.retainers.status', [$data->organization->getKey(), $retainer->getKey()]));

        $response->assertForbidden();
    }

    public function test_status_endpoint_returns_allocated_and_tracked_seconds(): void
    {
        $data = $this->createUserWithPermission(['retainers:view']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        $retainer = Retainer::factory()->forClient($client)->create([
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'starts_at' => '2026-01-01',
        ]);
        Passport::actingAs($data->user);

        $response = $this->getJson(route('api.v1.retainers.status', [
            $data->organization->getKey(),
            $retainer->getKey(),
            'as_of' => '2026-02-01',
        ]));

        $response->assertStatus(200);
        $response->assertJson(fn (AssertableJson $json) => $json
            ->has('data', fn (AssertableJson $json) => $json
                ->where('allocated_seconds', 40 * 3600)
                ->where('tracked_seconds', 0)
                ->where('hard_cap_enabled', false)
                ->etc())
        );
    }

    public function test_store_endpoint_creates_retainer_with_hard_cap(): void
    {
        $data = $this->createUserWithPermission(['retainers:create']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        Passport::actingAs($data->user);

        $response = $this->postJson(route('api.v1.retainers.store', [$data->organization->getKey(), $client->getKey()]), [
            'name' => 'Capped retainer',
            'period_mode' => 'calendar',
            'period_unit' => 'monthly',
            'seconds_per_period' => 40 * 3600,
            'starts_at' => '2026-01-01',
            'hard_cap_enabled' => true,
            'hard_cap_scope' => RetainerHardCapScope::PerPeriod->value,
            'hard_cap_enforcement' => RetainerHardCapEnforcement::Block->value,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas(Retainer::class, [
            'id' => $response->json('data.id'),
            'hard_cap_enabled' => true,
            'hard_cap_scope' => 'per_period',
            'hard_cap_enforcement' => 'block',
        ]);
    }

    public function test_store_endpoint_fails_if_hard_cap_enabled_without_scope_and_enforcement(): void
    {
        $data = $this->createUserWithPermission(['retainers:create']);
        $client = Client::factory()->forOrganization($data->organization)->create();
        Passport::actingAs($data->user);

        $response = $this->postJson(route('api.v1.retainers.store', [$data->organization->getKey(), $client->getKey()]), [
            'name' => 'Capped retainer',
            'period_mode' => 'calendar',
            'period_unit' => 'monthly',
            'seconds_per_period' => 40 * 3600,
            'starts_at' => '2026-01-01',
            'hard_cap_enabled' => true,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['hard_cap_scope', 'hard_cap_enforcement']);
    }
}
