<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateUnavailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthApiTest extends TestCase
{
    use RefreshDatabase;

    private string $plainKey = 'test-api-key-for-health';

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

    public function test_requires_api_key(): void
    {
        $this->getJson('/api/v1/system/health')
            ->assertStatus(401);
    }

    public function test_returns_healthy_status(): void
    {
        $response = $this->apiGet('/api/v1/system/health');

        $response->assertOk()
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.database.status', 'ok')
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'database' => ['status'],
                    'sync'     => ['last_synced_at'],
                    'flights'  => ['total'],
                    'gates'    => ['total', 'active_allocations', 'active_unavailabilities'],
                    'checked_at',
                ],
            ]);
    }

    public function test_returns_correct_counts_with_no_data(): void
    {
        $response = $this->apiGet('/api/v1/system/health');

        $response->assertOk()
            ->assertJsonPath('data.sync.last_synced_at', null)
            ->assertJsonPath('data.flights.total', 0)
            ->assertJsonPath('data.gates.total', 0)
            ->assertJsonPath('data.gates.active_allocations', 0)
            ->assertJsonPath('data.gates.active_unavailabilities', 0);
    }

    public function test_returns_correct_counts_with_data(): void
    {
        $gate = Gate::factory()->create();
        Gate::factory()->create();
        Flight::factory()->count(5)->create();

        // Active allocation
        GateAllocation::factory()->create([
            'gate_id'       => $gate->id,
            'occupied_from' => now()->subMinutes(30),
            'occupied_until' => now()->addMinutes(30),
        ]);

        // Past allocation (should not count)
        GateAllocation::factory()->create([
            'gate_id'       => $gate->id,
            'occupied_from' => now()->subHours(3),
            'occupied_until' => now()->subHours(2),
        ]);

        // Active unavailability
        GateUnavailability::factory()->create([
            'gate_id'  => $gate->id,
            'start_at' => now()->subMinutes(10),
            'end_at'   => now()->addMinutes(50),
        ]);

        // Past unavailability (should not count)
        GateUnavailability::factory()->create([
            'gate_id'  => $gate->id,
            'start_at' => now()->subHours(5),
            'end_at'   => now()->subHours(4),
        ]);

        $response = $this->apiGet('/api/v1/system/health');

        $response->assertOk()
            ->assertJsonPath('data.gates.total', 2)
            ->assertJsonPath('data.gates.active_allocations', 1)
            ->assertJsonPath('data.gates.active_unavailabilities', 1);

        $this->assertGreaterThanOrEqual(5, $response->json('data.flights.total'));
    }

    public function test_last_synced_at_reflects_latest_flight(): void
    {
        Flight::factory()->create(['updated_at' => now()->subHours(2)]);
        $latest = Flight::factory()->create(['updated_at' => now()->subMinutes(5)]);

        $response = $this->apiGet('/api/v1/system/health');

        $response->assertOk();

        $lastSynced = $response->json('data.sync.last_synced_at');
        $this->assertNotNull($lastSynced);
    }

    public function test_returns_503_and_degraded_status_when_database_unreachable(): void
    {
        \Illuminate\Support\Facades\DB::shouldReceive('connection')
            ->andThrow(new \RuntimeException('DB down'));

        $response = $this->apiGet('/api/v1/system/health');

        $response->assertStatus(503)
            ->assertJsonPath('data.status', 'degraded')
            ->assertJsonPath('data.database.status', 'unreachable')
            ->assertJsonPath('data.sync.last_synced_at', null)
            ->assertJsonPath('data.flights.total', null)
            ->assertJsonPath('data.gates.total', null)
            ->assertJsonPath('data.gates.active_allocations', null)
            ->assertJsonPath('data.gates.active_unavailabilities', null);

        $this->assertNotNull($response->json('data.checked_at'));
    }
}
