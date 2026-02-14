<?php

namespace Database\Factories;

use App\Models\Gate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Gate>
 */

class GateFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'G' . fake()->unique()->numberBetween(1, 100),
        ];
    }
}

