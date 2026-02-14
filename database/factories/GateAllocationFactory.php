<?php

namespace Database\Factories;

use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GateAllocation>
 */
class GateAllocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $from = now();

        return [
            'gate_id' => Gate::factory(),
            'flight_id' => Flight::factory(),
            'occupied_from' => $from,
            'occupied_until' => $from->copy()->addMinutes(90),
        ];
    }
}
