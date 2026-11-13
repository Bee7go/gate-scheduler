<?php

namespace Database\Factories;

use App\Models\SyncRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SyncRun>
 */
class SyncRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'trigger' => 'scheduled',
            'status' => 'completed',
            'arrivals_source' => 'live',
            'departures_source' => 'live',
            'arrivals_fetched' => fake()->numberBetween(0, 20),
            'departures_fetched' => fake()->numberBetween(0, 20),
            'allocation_summary' => ['processed' => 0, 'assigned' => 0, 'unassigned' => 0, 'errors' => []],
            'failure_reason' => null,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ];
    }
}
