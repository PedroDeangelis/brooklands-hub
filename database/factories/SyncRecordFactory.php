<?php

namespace Database\Factories;

use App\Enums\SyncStatus;
use App\Models\SyncRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRecord>
 */
class SyncRecordFactory extends Factory
{
    protected $model = SyncRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => 'items',
            'bc_id' => fake()->uuid(),
            'status' => SyncStatus::Pending,
            'changed_fields' => [],
            'payload_hash' => hash('sha256', fake()->word()),
            'bc_modified_at' => fake()->dateTimeBetween('-1 year'),
            'dispatched_at' => now(),
        ];
    }

    public function synced(): static
    {
        return $this->state(fn (): array => [
            'status' => SyncStatus::Synced,
            'synced_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => SyncStatus::Failed,
            'last_error' => 'boom',
        ]);
    }
}
