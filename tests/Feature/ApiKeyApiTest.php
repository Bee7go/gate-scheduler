<?php

namespace Tests\Feature;

use App\Models\AccessToken;
use App\Models\ApiKey;
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

    private function createApiKey(User $user, string $name): array
    {
        $plainTextApiKey = 'test-api-key-'.$name;
        $apiKey = ApiKey::create([
            'user_id' => $user->id,
            'name' => $name,
            'key' => hash('sha256', $plainTextApiKey),
            'expires_at' => now()->addYear(),
        ]);

        return [$apiKey, $plainTextApiKey];
    }

    private function createUser(string $email = 'jane@example.com'): User
    {
        return User::create([
            'name' => 'Jane Doe',
            'email' => $email,
            'password' => Hash::make('Password123'),
        ]);
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

    public function test_user_can_list_only_their_api_key_metadata(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser('other@example.com');
        [$olderKey] = $this->createApiKey($user, 'Older key');
        $this->travel(1)->minutes();
        [$newerKey] = $this->createApiKey($user, 'Newer key');
        $this->createApiKey($otherUser, 'Other user key');

        $response = $this->getJson('/api/v1/api-keys', [
            'Authorization' => 'Bearer '.$this->loginAndGetBearerToken($user),
        ]);

        $response->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.id', $newerKey->id)
            ->assertJsonPath('data.1.id', $olderKey->id)
            ->assertJsonMissing(['user_id' => $user->id])
            ->assertJsonMissing(['key' => hash('sha256', 'test-api-key-Newer key')])
            ->assertJsonMissing(['api_key' => 'test-api-key-Newer key']);
    }

    public function test_api_key_list_is_paginated(): void
    {
        $user = $this->createUser();

        foreach (range(1, 16) as $number) {
            $this->createApiKey($user, "Key {$number}");
        }

        $response = $this->getJson('/api/v1/api-keys', [
            'Authorization' => 'Bearer '.$this->loginAndGetBearerToken($user),
        ]);

        $response->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('total', 16)
            ->assertJsonCount(15, 'data');
    }

    public function test_user_can_revoke_their_api_key(): void
    {
        $user = $this->createUser();
        [$apiKey, $plainTextApiKey] = $this->createApiKey($user, 'Revoked key');

        $this->deleteJson("/api/v1/api-keys/{$apiKey->id}", [], [
            'Authorization' => 'Bearer '.$this->loginAndGetBearerToken($user),
        ])->assertNoContent();

        $this->assertDatabaseMissing('api_keys', ['id' => $apiKey->id]);
        $this->getJson('/api/v1/allocations', ['X-Api-Key' => $plainTextApiKey])
            ->assertStatus(401)
            ->assertJson(['message' => 'Invalid API key.']);
    }

    public function test_user_cannot_revoke_another_users_api_key(): void
    {
        $user = $this->createUser();
        $otherUser = $this->createUser('other@example.com');
        [$apiKey] = $this->createApiKey($otherUser, 'Other user key');

        $this->deleteJson("/api/v1/api-keys/{$apiKey->id}", [], [
            'Authorization' => 'Bearer '.$this->loginAndGetBearerToken($user),
        ])->assertNotFound();

        $this->assertDatabaseHas('api_keys', ['id' => $apiKey->id]);
    }

    public function test_api_key_lifecycle_endpoints_require_a_valid_bearer_token(): void
    {
        $this->getJson('/api/v1/api-keys')
            ->assertStatus(401)
            ->assertJson(['message' => 'Bearer token required.']);

        $this->getJson('/api/v1/api-keys', ['Authorization' => 'Bearer invalid-token'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Invalid or expired bearer token.']);

        $user = $this->createUser();
        $plainTextToken = 'expired-bearer-token';
        AccessToken::create([
            'user_id' => $user->id,
            'token' => hash('sha256', $plainTextToken),
            'expires_at' => now()->subMinute(),
        ]);

        $this->deleteJson('/api/v1/api-keys/1', [], [
            'Authorization' => 'Bearer '.$plainTextToken,
        ])->assertStatus(401)
            ->assertJson(['message' => 'Invalid or expired bearer token.']);
    }
}
