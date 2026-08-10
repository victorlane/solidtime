<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\RetainerHardCapEnforcement;
use App\Enums\RetainerHardCapScope;
use App\Enums\RetainerPeriodMode;
use App\Enums\RetainerPeriodUnit;
use App\Enums\RetainerSubCapMode;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Retainer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Retainer>
 */
class RetainerFactory extends Factory
{
    protected $model = Retainer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'client_id' => null,
            'name' => 'Retainer '.$this->faker->word(),
            'description' => null,
            'period_mode' => RetainerPeriodMode::Calendar,
            'period_unit' => RetainerPeriodUnit::Monthly,
            'seconds_per_period' => 40 * 3600,
            'anchor_date' => null,
            'starts_at' => Carbon::today()->startOfMonth(),
            'ends_at' => null,
            'billable_only' => true,
            'hard_cap_enabled' => false,
            'hard_cap_scope' => null,
            'hard_cap_enforcement' => null,
            'hard_cap_cumulative_seconds' => null,
            'sub_cap_mode' => RetainerSubCapMode::Soft,
        ];
    }

    public function configure(): self
    {
        return $this->afterMaking(function (Retainer $retainer): void {
            if ($retainer->client_id === null) {
                $client = Client::factory()->create(['organization_id' => $retainer->organization_id]);
                $retainer->client_id = $client->getKey();
            }
        });
    }

    public function forOrganization(Organization $organization): self
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $organization->getKey(),
            'client_id' => null,
        ]);
    }

    public function forClient(Client $client): self
    {
        return $this->state(fn (array $attributes): array => [
            'organization_id' => $client->organization_id,
            'client_id' => $client->getKey(),
        ]);
    }

    public function withHardCap(RetainerHardCapScope $scope = RetainerHardCapScope::PerPeriod, RetainerHardCapEnforcement $enforcement = RetainerHardCapEnforcement::Block): self
    {
        return $this->state(fn (array $attributes): array => [
            'hard_cap_enabled' => true,
            'hard_cap_scope' => $scope,
            'hard_cap_enforcement' => $enforcement,
            'hard_cap_cumulative_seconds' => $scope === RetainerHardCapScope::Cumulative ? 200 * 3600 : null,
        ]);
    }

    public function explicit(): self
    {
        return $this->state(fn (array $attributes): array => [
            'period_mode' => RetainerPeriodMode::Explicit,
            'period_unit' => null,
            'seconds_per_period' => null,
        ]);
    }
}
