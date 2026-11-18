<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API errors must be JSON even when the client sends no Accept header —
 * a partner forgetting it must never receive a browser-style 302 redirect.
 */
class JsonErrorRenderingTest extends TestCase
{
    use RefreshDatabase;

    public function test_validation_failures_return_json_without_an_accept_header(): void
    {
        // Plain post(), unlike postJson(), sends no Accept: application/json.
        $response = $this->post('/api/v1/register', ['email' => 'not-an-email']);

        $response->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_validation_failures_carry_a_stable_summary_message(): void
    {
        $this->postJson('/api/v1/register', ['email' => 'not-an-email'])
            ->assertStatus(422)
            ->assertJson(['message' => 'The request failed validation.'])
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    }

    public function test_unknown_api_routes_return_json_without_an_accept_header(): void
    {
        $this->get('/api/v1/nope')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json');
    }
}
