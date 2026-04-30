<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateUnavailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GateStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private string $plainKey = 'test-api-key-for-gate-status';

    protected function setUp(): void
    {
        parent::setUp();

        ApiKey::create([
            'name' => 'Test Client',
            'key'  => hash('sha256', $this->plainKey),
        ]);
    }

    private function apiGet(string $uri): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($uri, ['X-Api-Key' => $this->plainKey]);
    }

    // ── Authentication ───────────────────────────────────────────────

    public function test_requires_api_key(): void
    {
        $this->getJson('/api/v1/gates/status')
            ->assertStatus(401);
    }

    // ── Basic response ───────────────────────────────────────────────

    public function test_returns_gate_status_list(): void
    {
        Gate::factory()->create(['code' => 'G1']);

        $response = $this->apiGet('/api/v1/gates/status');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonStructure([
                'data' => [[
                    'gate_id',
                    'gate_code',
                    'status',
                    'occupied_until',
                    'flight',
                ]],
            ]);
    }

    // ── Status: free ─────────────────────────────────────────────────

    public function test_gate_without_allocation_or_unavailability_is_free(): void
    {
        Gate::factory()->create(['code' => 'G1']);

        $response = $this->apiGet('/api/v1/gates/status');

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'free')
            ->assertJsonPath('data.0.occupied_until', null)
            ->assertJsonPath('data.0.flight', null);
    }

    // ── Status: occupied ─────────────────────────────────────────────

    public function test_gate_with_active_allocation_is_occupied(): void
    {
        $gate = Gate::factory()->create(['code' => 'G1']);
        $flight = Flight::factory()->create([
            'icao24'       => '4ba9cc',
            'airport_icao' => 'EHAM',
            'direction'    => 'arrival',
        ]);

        GateAllocation::factory()->create([
            'gate_id'        => $gate->id,
            'flight_id'      => $flight->id,
            'occupied_from'  => '2026-04-04 16:00:00',
            'occupied_until' => '2026-04-04 18:36:01',
        ]);

        $response = $this->apiGet('/api/v1/gates/status?at=2026-04-04T17:00:00Z');

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'occupied')
            ->assertJsonPath('data.0.occupied_until', '2026-04-04T18:36:01.000000Z')
            ->assertJsonPath('data.0.flight.id', $flight->id)
            ->assertJsonPath('data.0.flight.icao24', '4ba9cc')
            ->assertJsonPath('data.0.flight.direction', 'arrival');
    }

    public function test_gate_with_past_allocation_is_free(): void
    {
        $gate = Gate::factory()->create(['code' => 'G1']);

        GateAllocation::factory()->create([
            'gate_id'        => $gate->id,
            'occupied_from'  => '2026-04-04 10:00:00',
            'occupied_until' => '2026-04-04 12:00:00',
        ]);

        $response = $this->apiGet('/api/v1/gates/status?at=2026-04-04T14:00:00Z');

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'free');
    }

    // ── Status: maintenance ──────────────────────────────────────────

    public function test_gate_with_active_unavailability_is_maintenance(): void
    {
        $gate = Gate::factory()->create(['code' => 'G3']);

        GateUnavailability::factory()->create([
            'gate_id'  => $gate->id,
            'start_at' => '2026-04-04 15:00:00',
            'end_at'   => '2026-04-04 19:00:00',
            'reason'   => 'maintenance',
        ]);

        $response = $this->apiGet('/api/v1/gates/status?at=2026-04-04T17:00:00Z');

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'maintenance')
            ->assertJsonPath('data.0.occupied_until', null)
            ->assertJsonPath('data.0.flight', null);
    }

    // ── Filtering: gate_code ─────────────────────────────────────────

    public function test_filters_by_gate_code(): void
    {
        Gate::factory()->create(['code' => 'G1']);
        Gate::factory()->create(['code' => 'G2']);

        $response = $this->apiGet('/api/v1/gates/status?gate_code=G2');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.gate_code', 'G2');
    }

    // ── Filtering: at defaults to now ────────────────────────────────

    public function test_at_defaults_to_now(): void
    {
        $gate = Gate::factory()->create(['code' => 'G1']);

        GateAllocation::factory()->create([
            'gate_id'        => $gate->id,
            'occupied_from'  => now()->subMinutes(30),
            'occupied_until' => now()->addMinutes(30),
        ]);

        $response = $this->apiGet('/api/v1/gates/status');

        $response->assertOk()
            ->assertJsonPath('data.0.status', 'occupied');
    }

    // ── Validation ───────────────────────────────────────────────────

    public function test_rejects_invalid_at_date(): void
    {
        $this->apiGet('/api/v1/gates/status?at=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors('at');
    }

    // ── Combined: multiple gates with mixed statuses ─────────────────

    public function test_returns_mixed_statuses_across_gates(): void
    {
        $gateOccupied = Gate::factory()->create(['code' => 'G1']);
        $gateFree = Gate::factory()->create(['code' => 'G2']);
        $gateMaint = Gate::factory()->create(['code' => 'G3']);

        $flight = Flight::factory()->create([
            'icao24'       => '4ba9cc',
            'airport_icao' => 'EHAM',
            'direction'    => 'arrival',
        ]);

        GateAllocation::factory()->create([
            'gate_id'        => $gateOccupied->id,
            'flight_id'      => $flight->id,
            'occupied_from'  => '2026-04-04 16:00:00',
            'occupied_until' => '2026-04-04 18:36:01',
        ]);

        GateUnavailability::factory()->create([
            'gate_id'  => $gateMaint->id,
            'start_at' => '2026-04-04 15:00:00',
            'end_at'   => '2026-04-04 20:00:00',
        ]);

        $response = $this->apiGet('/api/v1/gates/status?at=2026-04-04T17:00:00Z');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.status', 'occupied')
            ->assertJsonPath('data.0.flight.icao24', '4ba9cc')
            ->assertJsonPath('data.1.status', 'free')
            ->assertJsonPath('data.2.status', 'maintenance');
    }
}
