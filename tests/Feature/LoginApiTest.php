<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_and_receive_bearer_token(): void
    {
        User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'JANE@EXAMPLE.COM',
            'password' => 'Password123',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'jane@example.com')
            ->assertJsonPath('data.expires_in', 3600)
            ->assertJsonMissingPath('data.api_key')
            ->assertJsonMissingPath('data.authenticated');

        $accessToken = $response->json('data.access_token');

        $this->assertIsString($accessToken);
        $this->assertNotEmpty($accessToken);

        $this->assertDatabaseHas('access_tokens', [
            'user_id' => User::firstOrFail()->id,
            'token' => hash('sha256', $accessToken),
        ]);

        $this->assertDatabaseCount('api_keys', 0);
    }

    public function test_login_requires_expected_fields(): void
    {
        $this->postJson('/api/v1/login', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_login_rejects_unknown_email(): void
    {
        $this->postJson('/api/v1/login', [
            'email' => 'missing@example.com',
            'password' => 'Password123',
        ])->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid credentials.',
            ]);
    }

    public function test_login_rejects_wrong_password(): void
    {
        User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $this->postJson('/api/v1/login', [
            'email' => 'jane@example.com',
            'password' => 'WrongPassword123',
        ])->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid credentials.',
            ]);
    }

}