<?php

namespace Database\Factories;

use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ApiKey>
 */
class ApiKeyFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true).' key',
            'description' => null,
            'key' => hash('sha256', Str::random(64)),
            'last_used_at' => null,
            'expires_at' => now()->addYear(),
        ];
    }
}
