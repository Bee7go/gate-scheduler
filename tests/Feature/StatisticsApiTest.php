<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateUnavailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatisticsApiTest extends TestCase
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
        $this->getJson('/api/v1/statistics?from=2026-04-01&to=2026-04-30')
            ->assertStatus(401);
    }

    // ── Validation ───────────────────────────────────────────────────

    public function test_from_and_to_are_required(): void
    {
        $this->apiGet('/api/v1/statistics')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from', 'to']);
    }

    public function test_to_must_be_after_from(): void
    {
        $this->apiGet('/api/v1/statistics?from=2026-04-30&to=2026-04-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    // ── Response structure ───────────────────────────────────────────

    public function test_returns_full_response_structure(): void
    {
        $this->apiGet('/api/v1/statistics?from=2026-04-01&to=2026-04-30')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'period' => ['from', 'to'],
                    'gates' => ['total', 'active', 'had_unavailability', 'utilization_rate', 'average_turnaround_minutes'],
                    'flights' => ['total', 'arrivals', 'departures', 'unallocated', 'allocation_rate'],
                    'allocations' => ['total', 'average_duration_minutes', 'shortest_duration_minutes', 'longest_duration_minutes'],
                    'peak' => ['busiest_hour', 'max_simultaneous_gates', 'busiest_date', 'busiest_date_allocations'],
                    'top_gates',
                    'unavailability' => ['total_events', 'total_downtime_minutes', 'affected_gates', 'most_common_reason'],
                    'generated_at',
                ],
            ]);
    }

    // ── Empty data ───────────────────────────────────────────────────

    public function test_returns_zeros_when_no_data_exists(): void
    {
        Gate::factory()->count(3)->create();

        $response = $this->apiGet('/api/v1/statistics?from=2026-04-01&to=2026-04-30')
            ->assertStatus(200);

        $data = $response->json('data');

        $this->assertEquals(3, $data['gates']['total']);
        $this->assertEquals(0, $data['gates']['active']);
        $this->assertEquals(0, $data['flights']['total']);
        $this->assertEquals(0, $data['allocations']['total']);
        $this->assertNull($data['allocations']['average_duration_minutes']);
        $this->assertEmpty($data['top_gates']);
        $this->assertEquals(0, $data['unavailability']['total_events']);
    }

    // ── Gates statistics ─────────────────────────────────────────────

    public function test_gates_counts_active_and_unavailable(): void
    {
        $gate1 = Gate::factory()->create();
        $gate2 = Gate::factory()->create();
        $gate3 = Gate::factory()->create();

        $flight = Flight::factory()->create([
            'first_seen_at' => '2026-04-10 10:00:00',
            'direction'     => 'arrival',
        ]);

        GateAllocation::factory()->create([
            'gate_id'        => $gate1->id,
            'flight_id'      => $flight->id,
            'occupied_from'  => '2026-04-10 10:00:00',
            'occupied_until' => '2026-04-10 11:30:00',
        ]);

        GateUnavailability::factory()->create([
            'gate_id'  => $gate2->id,
            'start_at' => '2026-04-15 08:00:00',
            'end_at'   => '2026-04-15 12:00:00',
        ]);

        $data = $this->apiGet('/api/v1/statistics?from=2026-04-01&to=2026-04-30')
            ->assertStatus(200)
            ->json('data');

        $this->assertEquals(3, $data['gates']['total']);
        $this->assertEquals(1, $data['gates']['active']);
        $this->assertEquals(1, $data['gates']['had_unavailability']);
    }

    // ── Flights statistics ───────────────────────────────────────────

    public function test_flights_counts_by_direction_and_allocation(): void
    {
        $gate = Gate::factory()->create();

        $arrival1 = Flight::factory()->create([
            'first_seen_at' => '2026-04-10 10:00:00',
            'direction'     => 'arrival',
        ]);
        $arrival2 = Flight::factory()->create([
            'first_seen_at' => '2026-04-11 10:00:00',
            'direction'     => 'arrival',
        ]);
        $departure1 = Flight::factory()->create([
            'first_seen_at' => '2026-04-12 10:00:00',
            'direction'     => 'departure',
        ]);

        // Only allocate arrival1
        GateAllocation::factory()->create([
            'gate_id'        => $gate->id,
            'flight_id'      => $arrival1->id,
            'occupied_from'  => '2026-04-10 10:00:00',
            'occupied_until' => '2026-04-10 11:30:00',
        ]);

        $data = $this->apiGet('/api/v1/statistics?from=2026-04-01&to=2026-04-30')
            ->assertStatus(200)
            ->json('data');

        $this->assertEquals(3, $data['flights']['total']);
        $this->assertEquals(2, $data['flights']['arrivals']);
        $this->assertEquals(1, $data['flights']['departures']);
        $this->assertEquals(2, $data['flights']['unallocated']);
        $this->assertEquals(0.33, $data['flights']['allocation_rate']);
    }

    // ── Allocations statistics ───────────────────────────────────────

    public function test_allocation_duration_statistics(): void
    {
        $gate = Gate::factory()->create();

        $flight1 = Flight::factory()->create(['first_seen_at' => '2026-04-10 10:00:00', 'direction' => 'arrival']);
        $flight2 = Flight::factory()->create(['first_seen_at' => '2026-04-11 10:00:00', 'direction' => 'arrival']);

        GateAllocation::factory()->create([
            'gate_id'        => $gate->id,
            'flight_id'      => $flight1->id,
            'occupied_from'  => '2026-04-10 10:00:00',
            'occupied_until' => '2026-04-10 11:00:00', // 60 min
        ]);

        GateAllocation::factory()->create([
            'gate_id'        => $gate->id,
            'flight_id'      => $flight2->id,
            'occupied_from'  => '2026-04-11 10:00:00',
            'occupied_until' => '2026-04-11 12:00:00', // 120 min
        ]);

        $data = $this->apiGet('/api/v1/statistics?from=2026-04-01&to=2026-04-30')
            ->assertStatus(200)
            ->json('data');

        $this->assertEquals(2, $data['allocations']['total']);
        $this->assertEquals(90, $data['allocations']['average_duration_minutes']);
        $this->assertEquals(60, $data['allocations']['shortest_duration_minutes']);
        $this->assertEquals(120, $data['allocations']['longest_duration_minutes']);
    }

    // ── Peak statistics ──────────────────────────────────────────────

    public function test_peak_identifies_busiest_hour_and_date(): void
    {
        $gate1 = Gate::factory()->create();
        $gate2 = Gate::factory()->create();

        $flight1 = Flight::factory()->create(['first_seen_at' => '2026-04-10 14:00:00', 'direction' => 'arrival']);
        $flight2 = Flight::factory()->create(['first_seen_at' => '2026-04-10 14:30:00', 'direction' => 'arrival']);
        $flight3 = Flight::factory()->create(['first_seen_at' => '2026-04-11 10:00:00', 'direction' => 'arrival']);

        // Two allocations overlapping at 14:00-15:00 on April 10
        GateAllocation::factory()->create([
            'gate_id' => $gate1->id, 'flight_id' => $flight1->id,
            'occupied_from' => '2026-04-10 14:00:00', 'occupied_until' => '2026-04-10 15:30:00',
        ]);
        GateAllocation::factory()->create([
            'gate_id' => $gate2->id, 'flight_id' => $flight2->id,
            'occupied_from' => '2026-04-10 14:30:00', 'occupied_until' => '2026-04-10 16:00:00',
        ]);

        // One allocation on April 11
        GateAllocation::factory()->create([
            'gate_id' => $gate1->id, 'flight_id' => $flight3->id,
            'occupied_from' => '2026-04-11 10:00:00', 'occupied_until' => '2026-04-11 11:30:00',
        ]);

        $data = $this->apiGet('/api/v1/statistics?from=2026-04-10&to=2026-04-12')
            ->assertStatus(200)
            ->json('data');

        $this->assertEquals(2, $data['peak']['max_simultaneous_gates']);
        $this->assertEquals('2026-04-10', $data['peak']['busiest_date']);
        $this->assertEquals(2, $data['peak']['busiest_date_allocations']);
    }

    // ── Top gates ────────────────────────────────────────────────────

    public function test_top_gates_returns_up_to_5_ordered_by_count(): void
    {
        $gates = Gate::factory()->count(3)->create();

        // Gate 0: 1 allocation, Gate 1: 3 allocations, Gate 2: 2 allocations
        foreach ([1, 3, 2] as $i => $count) {
            for ($j = 0; $j < $count; $j++) {
                $flight = Flight::factory()->create([
                    'first_seen_at' => "2026-04-10 " . (10 + $j) . ":00:00",
                    'direction'     => 'arrival',
                ]);
                GateAllocation::factory()->create([
                    'gate_id'        => $gates[$i]->id,
                    'flight_id'      => $flight->id,
                    'occupied_from'  => "2026-04-" . (10 + $i) . " " . (10 + $j) . ":00:00",
                    'occupied_until' => "2026-04-" . (10 + $i) . " " . (11 + $j) . ":00:00",
                ]);
            }
        }

        $data = $this->apiGet('/api/v1/statistics?from=2026-04-01&to=2026-04-30')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(3, $data['top_gates']);
        $this->assertEquals($gates[1]->code, $data['top_gates'][0]['gate_code']);
        $this->assertEquals(3, $data['top_gates'][0]['allocations_count']);
    }

    // ── Unavailability statistics ────────────────────────────────────

    public function test_unavailability_counts_and_most_common_reason(): void
    {
        $gate1 = Gate::factory()->create();
        $gate2 = Gate::factory()->create();

        GateUnavailability::factory()->create([
            'gate_id' => $gate1->id, 'start_at' => '2026-04-10 08:00:00',
            'end_at' => '2026-04-10 10:00:00', 'reason' => 'Cleaning',
        ]);
        GateUnavailability::factory()->create([
            'gate_id' => $gate1->id, 'start_at' => '2026-04-11 08:00:00',
            'end_at' => '2026-04-11 10:00:00', 'reason' => 'Cleaning',
        ]);
        GateUnavailability::factory()->create([
            'gate_id' => $gate2->id, 'start_at' => '2026-04-12 08:00:00',
            'end_at' => '2026-04-12 11:00:00', 'reason' => 'Repair',
        ]);

        $data = $this->apiGet('/api/v1/statistics?from=2026-04-01&to=2026-04-30')
            ->assertStatus(200)
            ->json('data');

        $this->assertEquals(3, $data['unavailability']['total_events']);
        $this->assertEquals(420, $data['unavailability']['total_downtime_minutes']); // 2h + 2h + 3h
        $this->assertEquals(2, $data['unavailability']['affected_gates']);
        $this->assertEquals('Cleaning', $data['unavailability']['most_common_reason']);
    }

    public function test_unavailability_most_common_reason_is_null_when_no_reasons(): void
    {
        $gate = Gate::factory()->create();

        GateUnavailability::factory()->create([
            'gate_id' => $gate->id, 'start_at' => '2026-04-10 08:00:00',
            'end_at' => '2026-04-10 10:00:00', 'reason' => null,
        ]);

        $data = $this->apiGet('/api/v1/statistics?from=2026-04-01&to=2026-04-30')
            ->assertStatus(200)
            ->json('data');

        $this->assertNull($data['unavailability']['most_common_reason']);
    }

    // ── Period filtering ─────────────────────────────────────────────

    public function test_excludes_data_outside_period(): void
    {
        $gate = Gate::factory()->create();

        // Flight outside period
        Flight::factory()->create([
            'first_seen_at' => '2026-03-15 10:00:00',
            'direction'     => 'arrival',
        ]);

        // Flight inside period
        $insideFlight = Flight::factory()->create([
            'first_seen_at' => '2026-04-10 10:00:00',
            'direction'     => 'arrival',
        ]);

        GateAllocation::factory()->create([
            'gate_id'        => $gate->id,
            'flight_id'      => $insideFlight->id,
            'occupied_from'  => '2026-04-10 10:00:00',
            'occupied_until' => '2026-04-10 11:30:00',
        ]);

        $data = $this->apiGet('/api/v1/statistics?from=2026-04-01&to=2026-04-30')
            ->assertStatus(200)
            ->json('data');

        $this->assertEquals(1, $data['flights']['total']);
        $this->assertEquals(1, $data['allocations']['total']);
    }
}
