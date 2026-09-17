<?php

namespace Database\Factories;

use App\Models\Campaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Campaign>
 */
class CampaignFactory extends Factory
{
    protected $model = Campaign::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bc_id' => fake()->uuid(),
            'code' => mb_strtoupper(fake()->bothify('CP####')),
            'description' => fake()->words(4, true),
            'starting_date' => now()->subWeek()->toDateString(),
            'ending_date' => now()->addWeeks(6)->toDateString(),
            'activated' => true,
            'customers' => [],
            'bc_modified_at' => fake()->dateTimeBetween('-1 year'),
            'bc_payload' => [],
        ];
    }

    /**
     * A campaign Business Central has switched off.
     */
    public function deactivated(): static
    {
        return $this->state(fn (array $attributes): array => ['activated' => false]);
    }

    /**
     * A campaign whose end date has passed.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starting_date' => now()->subMonths(3)->toDateString(),
            'ending_date' => now()->subDay()->toDateString(),
        ]);
    }

    /**
     * A campaign that has not started yet.
     */
    public function upcoming(): static
    {
        return $this->state(fn (array $attributes): array => [
            'starting_date' => now()->addWeek()->toDateString(),
            'ending_date' => now()->addMonths(2)->toDateString(),
        ]);
    }
}
