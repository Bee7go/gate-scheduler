<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AllocationsApiTest extends TestCase
{
    use RefreshDatabase;

    private string $plainKey = 'test-api-key-plain-text-value';

    protected function setUp(): void
    {
        parent::setUp();

        ApiKey::create([
            'name' => 'Test Client',
            'key'  => hash('sha256', $this->plainKey),
        ]);
    }

    private function apiGet(string $uri, array $headers = []): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($uri, array_merge(['X-Api-Key' => $this->plainKey], $headers));
    }

    // ── Authentication ───────────────────────────────────────────────

    public function test_request_without_api_key_returns_401(): void
    {
        $this->getJson('/api/v1/allocations')
            ->assertStatus(401)
            ->assertJson(['message' => 'API key required.']);
    }

    public function test_request_with_invalid_api_key_returns_401(): void
    {
        $this->getJson('/api/v1/allocations', ['X-Api-Key' => 'wrong-key'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Invalid API key.']);
    }

    public function test_request_with_valid_api_key_returns_200(): void
    {
        $this->apiGet('/api/v1/allocations')
            ->assertStatus(200);
    }

    public function test_last_used_at_is_updated_on_successful_request(): void
    {
        $this->assertNull(ApiKey::first()->last_used_at);

        $this->apiGet('/api/v1/allocations');

        $this->assertNotNull(ApiKey::first()->refresh()->last_used_at);
    }

    // ── Basic listing ────────────────────────────────────────────────

    public function test_returns_paginated_allocations(): void
    {
        GateAllocation::factory()->count(3)->create();

        $response = $this->apiGet('/api/v1/allocations');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'gate_id', 'flight_id', 'occupied_from', 'occupied_until']],
                'current_page',
                'last_page',
                'total',
            ])
            ->assertJsonMissingPath('per_page')
            ->assertJsonMissingPath('links');
    }

    public function test_includes_gate_and_flight_relations(): void
    {
        GateAllocation::factory()->create();

        $response = $this->apiGet('/api/v1/allocations');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['gate' => ['id', 'code'], 'flight' => ['id', 'icao24']]],
            ]);
    }

    // ── Filtering ────────────────────────────────────────────────────

    public function test_filters_by_gate_code(): void
    {
        $gateA = Gate::factory()->create(['code' => 'G1']);
        $gateB = Gate::factory()->create(['code' => 'G2']);

        GateAllocation::factory()->for($gateA)->create();
        GateAllocation::factory()->for($gateB)->create();

        $response = $this->apiGet('/api/v1/allocations?gate_code=G1');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.gate.code', 'G1');
    }

    public function test_filters_by_occupied_from(): void
    {
        GateAllocation::factory()->create(['occupied_from' => '2026-04-01 08:00:00']);
        GateAllocation::factory()->create(['occupied_from' => '2026-04-01 14:00:00']);

        $response = $this->apiGet('/api/v1/allocations?occupied_from=2026-04-01 12:00:00');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_filters_by_occupied_until(): void
    {
        GateAllocation::factory()->create(['occupied_until' => '2026-04-01 10:00:00']);
        GateAllocation::factory()->create(['occupied_until' => '2026-04-01 18:00:00']);

        $response = $this->apiGet('/api/v1/allocations?occupied_until=2026-04-01 12:00:00');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    // ── Pagination ───────────────────────────────────────────────────

    public function test_respects_per_page_parameter(): void
    {
        GateAllocation::factory()->count(5)->create();

        $response = $this->apiGet('/api/v1/allocations?per_page=2');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('total', 5)
            ->assertJsonPath('last_page', 3);
    }

    public function test_defaults_to_15_per_page(): void
    {
        GateAllocation::factory()->count(16)->create();

        $this->apiGet('/api/v1/allocations')
            ->assertJsonPath('last_page', 2)
            ->assertJsonPath('total', 16);
    }

    // ── Validation ───────────────────────────────────────────────────

    public function test_rejects_invalid_per_page(): void
    {
        $this->apiGet('/api/v1/allocations?per_page=999')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_rejects_non_integer_per_page(): void
    {
        $this->apiGet('/api/v1/allocations?per_page=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors('per_page');
    }

    public function test_rejects_invalid_date_format(): void
    {
        $this->apiGet('/api/v1/allocations?occupied_from=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors('occupied_from');
    }

    public function test_rejects_occupied_from_after_occupied_until(): void
    {
        $this->apiGet('/api/v1/allocations?occupied_from=2026-04-02&occupied_until=2026-04-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('occupied_from');
    }
}
