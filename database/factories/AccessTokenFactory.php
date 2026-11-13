<?php

namespace Database\Factories;

use App\Models\AccessToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccessToken>
 */
class AccessTokenFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'token' => hash('sha256', Str::random(80)),
            'expires_at' => now()->addHour(),
            'last_used_at' => null,
        ];
    }
}
