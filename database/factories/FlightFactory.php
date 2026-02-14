<?php

namespace Database\Factories;

use App\Models\Flight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Flight>
 */
class FlightFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'icao24' => strtolower(fake()->bothify('??####')),
            'airport_icao' => fake()->randomElement([
                'EDDF', 'EGLL', 'LFPG', 'LROP'
            ]),
            'direction' => fake()->randomElement([
                'arrival',
                'departure',
            ]),

            'first_seen_at' => now(),
            'last_seen_at' => now()->addMinutes(30),
        ];
    }

    public function withoutFirstSeen(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_seen_at' => null,
        ]);
    }
}
