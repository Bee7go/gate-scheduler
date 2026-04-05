<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ApiKeyApiTest extends TestCase
{
    use RefreshDatabase;

    private function loginAndGetBearerToken(User $user): string
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => 'Password123',
        ]);

        $response->assertOk();

        $token = $response->json('data.access_token');

        $this->assertIsString($token);

        return $token;
    }

    public function test_user_can_generate_api_key_with_valid_bearer_token(): void
    {
        $user = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $bearerToken = $this->loginAndGetBearerToken($user);

        $response = $this->postJson('/api/v1/api-keys', [
            'name' => 'app basic key',
            'description' => 'My Airport Labs Key',
        ], [
            'Authorization' => 'Bearer '.$bearerToken,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.token_type', 'ApiKey')
            ->assertJsonPath('data.name', 'app basic key');

        $plainTextApiKey = $response->json('data.api_key');

        $this->assertIsString($plainTextApiKey);
        $this->assertNotEmpty($plainTextApiKey);
        $this->assertIsInt($response->json('data.id'));
        $this->assertNotNull($response->json('data.created_at'));
        $this->assertNotNull($response->json('data.expires_at'));

        $this->assertDatabaseHas('api_keys', [
            'user_id' => $user->id,
            'name' => 'app basic key',
            'description' => 'My Airport Labs Key',
            'key' => hash('sha256', $plainTextApiKey),
        ]);
    }

    public function test_api_key_request_requires_bearer_token(): void
    {
        $this->postJson('/api/v1/api-keys', [
            'name' => 'app basic key',
        ])->assertStatus(401)
            ->assertJson([
                'message' => 'Bearer token required.',
            ]);
    }

    public function test_api_key_request_rejects_invalid_bearer_token(): void
    {
        $this->postJson('/api/v1/api-keys', [
            'name' => 'app basic key',
        ], [
            'Authorization' => 'Bearer invalid-token',
        ])->assertStatus(401)
            ->assertJson([
                'message' => 'Invalid or expired bearer token.',
            ]);
    }

    public function test_api_key_request_requires_name(): void
    {
        $user = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $bearerToken = $this->loginAndGetBearerToken($user);

        $response = $this->postJson('/api/v1/api-keys', [
            'description' => 'My Airport Labs Key',
        ], [
            'Authorization' => 'Bearer '.$bearerToken,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_issued_api_key_can_access_protected_allocations_endpoint(): void
    {
        $user = User::create([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => Hash::make('Password123'),
        ]);

        $bearerToken = $this->loginAndGetBearerToken($user);

        $response = $this->postJson('/api/v1/api-keys', [
            'name' => 'app basic key',
        ], [
            'Authorization' => 'Bearer '.$bearerToken,
        ]);

        $response->assertCreated();

        $plainTextApiKey = $response->json('data.api_key');

        $this->getJson('/api/v1/allocations', [
            'X-Api-Key' => $plainTextApiKey,
        ])->assertOk();
    }
}