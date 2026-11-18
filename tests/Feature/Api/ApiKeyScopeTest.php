<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Models\Gate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiKeyScopeTest extends TestCase
{
    use RefreshDatabase;

    private function createKey(?array $scopes, string $plain): void
    {
        ApiKey::create([
            'name' => 'scoped key',
            'key' => hash('sha256', $plain),
            'scopes' => $scopes,
        ]);
    }

    public function test_a_key_without_scopes_is_unrestricted(): void
    {
        $this->createKey(null, 'legacy-key');
        Gate::create(['code' => 'A1']);

        $this->getJson('/api/v1/allocations', ['X-Api-Key' => 'legacy-key'])->assertStatus(200);

        $this->postJson('/api/v1/gates/unavailabilities', [
            'gate_id' => Gate::first()->id,
            'start_at' => now()->addHour()->toISOString(),
            'end_at' => now()->addHours(2)->toISOString(),
        ], ['X-Api-Key' => 'legacy-key'])->assertStatus(201);
    }

    public function test_a_read_key_can_read_but_not_write(): void
    {
        $this->createKey(['read'], 'read-key');
        Gate::create(['code' => 'A1']);

        $this->getJson('/api/v1/gates/status', ['X-Api-Key' => 'read-key'])->assertStatus(200);

        $this->postJson('/api/v1/gates/unavailabilities', [
            'gate_id' => Gate::first()->id,
            'start_at' => now()->addHour()->toISOString(),
            'end_at' => now()->addHours(2)->toISOString(),
        ], ['X-Api-Key' => 'read-key'])
            ->assertStatus(403)
            ->assertJson(['message' => 'This API key is missing the required scope [write].']);
    }

    public function test_a_write_key_cannot_read_or_sync(): void
    {
        $this->createKey(['write'], 'write-key');

        $this->getJson('/api/v1/allocations', ['X-Api-Key' => 'write-key'])
            ->assertStatus(403)
            ->assertJson(['message' => 'This API key is missing the required scope [read].']);

        $this->postJson('/api/v1/system/sync-now', [], ['X-Api-Key' => 'write-key'])
            ->assertStatus(403)
            ->assertJson(['message' => 'This API key is missing the required scope [sync].']);
    }

    public function test_key_creation_accepts_known_scopes_and_returns_them(): void
    {
        $token = $this->registerAndLogin();

        $this->postJson('/api/v1/api-keys', [
            'name' => 'reader',
            'scopes' => ['read', 'write'],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(201)
            ->assertJsonPath('data.scopes', ['read', 'write']);

        $this->assertSame(['read', 'write'], ApiKey::latest('id')->first()->scopes);
    }

    public function test_key_creation_rejects_unknown_scopes(): void
    {
        $token = $this->registerAndLogin();

        $this->postJson('/api/v1/api-keys', [
            'name' => 'bogus',
            'scopes' => ['read', 'admin'],
        ], ['Authorization' => 'Bearer '.$token])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'scopes.1' => 'Each scope must be one of: read, write, sync.',
            ]);
    }

    private function registerAndLogin(): string
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Scope Tester',
            'email' => 'scopes@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(201);

        $response = $this->postJson('/api/v1/login', [
            'email' => 'scopes@example.com',
            'password' => 'Password123',
        ]);

        return $response->json('data.access_token');
    }
}
