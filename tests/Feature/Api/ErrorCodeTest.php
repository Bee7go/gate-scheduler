<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every error response carries a stable machine-readable `code` from the
 * documented catalog, alongside the human-readable `message`.
 */
class ErrorCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_failures_carry_validation_failed(): void
    {
        $this->postJson('/api/v1/register', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJson(['code' => 'validation_failed'])
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_bad_credentials_carry_authentication_failed(): void
    {
        $this->getJson('/api/v1/gates/status', ['X-Api-Key' => 'wrong'])
            ->assertStatus(401)
            ->assertJson(['code' => 'authentication_failed']);
    }

    public function test_a_missing_scope_carries_insufficient_scope(): void
    {
        ApiKey::create(['name' => 'reader', 'key' => hash('sha256', 'read-key'), 'scopes' => ['read']]);

        $this->postJson('/api/v1/gates/unavailabilities', [], ['X-Api-Key' => 'read-key'])
            ->assertStatus(403)
            ->assertJson(['code' => 'insufficient_scope']);
    }

    public function test_unknown_routes_carry_not_found(): void
    {
        $response = $this->getJson('/api/v1/nope')
            ->assertStatus(404)
            ->assertJson(['code' => 'not_found']);

        // The framework's route-specific message is preserved.
        $this->assertStringContainsString('could not be found', $response->json('message'));
    }

    public function test_wrong_http_methods_carry_method_not_allowed(): void
    {
        $this->getJson('/api/v1/register')
            ->assertStatus(405)
            ->assertJson(['code' => 'method_not_allowed'])
            ->assertHeader('Allow');
    }

    public function test_failed_logins_carry_authentication_failed(): void
    {
        $this->postJson('/api/v1/register', [
            'name' => 'Codes Tester',
            'email' => 'codes@example.com',
            'password' => 'Password123',
            'password_confirmation' => 'Password123',
        ])->assertStatus(201);

        $this->postJson('/api/v1/login', [
            'email' => 'codes@example.com',
            'password' => 'wrong-password',
        ])
            ->assertStatus(401)
            ->assertJson([
                'code' => 'authentication_failed',
                'message' => 'Invalid credentials.',
            ]);
    }

    public function test_exceeding_the_rate_limit_carries_rate_limited(): void
    {
        ApiKey::create(['name' => 'limited', 'key' => hash('sha256', 'limited-key'), 'rate_limit_per_minute' => 1]);

        $this->getJson('/api/v1/gates/status', ['X-Api-Key' => 'limited-key'])->assertOk();

        $this->getJson('/api/v1/gates/status', ['X-Api-Key' => 'limited-key'])
            ->assertStatus(429)
            ->assertJson(['code' => 'rate_limited'])
            ->assertHeader('Retry-After');
    }
}
