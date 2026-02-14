<?php

namespace Database\Factories;

use App\Models\Gate;
use App\Models\GateUnavailability;
use Illuminate\Database\Eloquent\Factories\Factory;

class GateUnavailabilityFactory extends Factory
{
    protected $model = GateUnavailability::class;

    public function definition(): array
    {
        $start = $this->faker->dateTimeBetween('-1 week', '+1 week');
        $end = (clone $start)->modify('+2 hours');

        return [
            'gate_id' => Gate::factory(),
            'start_at' => $start,
            'end_at' => $end,
            'reason' => $this->faker->randomElement(['maintenance', 'repairs', 'cleaning']),
        ];
    }

    public function withinPeriod(string $start, string $end, ?int $gateId = null): static
    {
        return $this->state(function () use ($start, $end, $gateId) {
            return [
                'gate_id' => $gateId ?? Gate::factory(),
                'start_at' => $start,
                'end_at' => $end,
                'reason' => 'maintenance',
            ];
        });
    }
}
