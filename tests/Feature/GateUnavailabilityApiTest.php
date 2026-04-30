<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\Gate;
use App\Models\GateUnavailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GateUnavailabilityApiTest extends TestCase
{
    use RefreshDatabase;

    private string $plainKey = 'test-api-key-for-unavailabilities';

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

    private function apiPost(string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($uri, $data, ['X-Api-Key' => $this->plainKey]);
    }

    // ── Authentication ───────────────────────────────────────────────

    public function test_requires_api_key(): void
    {
        $this->getJson('/api/v1/gates/unavailabilities')
            ->assertStatus(401);
    }

    // ── Basic listing ────────────────────────────────────────────────

    public function test_returns_all_unavailabilities(): void
    {
        GateUnavailability::factory()->count(3)->create();

        $response = $this->apiGet('/api/v1/gates/unavailabilities');

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'gate_id',
                    'start_at',
                    'end_at',
                    'reason',
                    'created_at',
                    'updated_at',
                ]],
            ]);
    }

    // ── Filtering: gate_id ───────────────────────────────────────────

    public function test_filters_by_gate_id(): void
    {
        $gate = Gate::factory()->create();
        $otherGate = Gate::factory()->create();

        GateUnavailability::factory()->create(['gate_id' => $gate->id]);
        GateUnavailability::factory()->create(['gate_id' => $gate->id]);
        GateUnavailability::factory()->create(['gate_id' => $otherGate->id]);

        $response = $this->apiGet("/api/v1/gates/unavailabilities?gate_id={$gate->id}");

        $response->assertOk()
            ->assertJsonCount(2, 'data');

        foreach ($response->json('data') as $item) {
            $this->assertEquals($gate->id, $item['gate_id']);
        }
    }

    // ── Filtering: from ──────────────────────────────────────────────

    public function test_filters_by_from(): void
    {
        $gate = Gate::factory()->create();

        GateUnavailability::factory()->create([
            'gate_id'  => $gate->id,
            'start_at' => '2026-04-01 08:00:00',
            'end_at'   => '2026-04-01 10:00:00',
        ]);
        GateUnavailability::factory()->create([
            'gate_id'  => $gate->id,
            'start_at' => '2026-05-10 08:00:00',
            'end_at'   => '2026-05-10 12:00:00',
        ]);

        $response = $this->apiGet('/api/v1/gates/unavailabilities?from=2026-05-01T00:00:00Z');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ── Filtering: to ────────────────────────────────────────────────

    public function test_filters_by_to(): void
    {
        $gate = Gate::factory()->create();

        GateUnavailability::factory()->create([
            'gate_id'  => $gate->id,
            'start_at' => '2026-05-10 08:00:00',
            'end_at'   => '2026-05-10 12:00:00',
        ]);
        GateUnavailability::factory()->create([
            'gate_id'  => $gate->id,
            'start_at' => '2026-06-15 08:00:00',
            'end_at'   => '2026-06-15 12:00:00',
        ]);

        $response = $this->apiGet('/api/v1/gates/unavailabilities?to=2026-05-31T23:59:59Z');

        $response->assertOk()
            ->assertJsonCount(1, 'data');
    }

    // ── Filtering: combined ──────────────────────────────────────────

    public function test_filters_by_gate_id_and_date_range(): void
    {
        $gate = Gate::factory()->create();
        $otherGate = Gate::factory()->create();

        // Matching: correct gate + in range
        GateUnavailability::factory()->create([
            'gate_id'  => $gate->id,
            'start_at' => '2026-05-10 08:00:00',
            'end_at'   => '2026-05-10 12:00:00',
        ]);
        // Matching: correct gate + in range
        GateUnavailability::factory()->create([
            'gate_id'  => $gate->id,
            'start_at' => '2026-05-15 14:00:00',
            'end_at'   => '2026-05-15 16:30:00',
        ]);
        // Wrong gate
        GateUnavailability::factory()->create([
            'gate_id'  => $otherGate->id,
            'start_at' => '2026-05-12 08:00:00',
            'end_at'   => '2026-05-12 10:00:00',
        ]);
        // Out of range
        GateUnavailability::factory()->create([
            'gate_id'  => $gate->id,
            'start_at' => '2026-06-01 08:00:00',
            'end_at'   => '2026-06-01 10:00:00',
        ]);

        $response = $this->apiGet(
            "/api/v1/gates/unavailabilities?gate_id={$gate->id}&from=2026-05-01T00:00:00Z&to=2026-05-31T23:59:59Z"
        );

        $response->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ── Validation ───────────────────────────────────────────────────

    public function test_rejects_invalid_gate_id(): void
    {
        $this->apiGet('/api/v1/gates/unavailabilities?gate_id=abc')
            ->assertStatus(422)
            ->assertJsonValidationErrors('gate_id');
    }

    public function test_rejects_invalid_from_date(): void
    {
        $this->apiGet('/api/v1/gates/unavailabilities?from=not-a-date')
            ->assertStatus(422)
            ->assertJsonValidationErrors('from');
    }

    public function test_rejects_to_before_from(): void
    {
        $this->apiGet('/api/v1/gates/unavailabilities?from=2026-05-31&to=2026-05-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors('to');
    }

    // ── Ordering ─────────────────────────────────────────────────────

    public function test_results_are_ordered_by_start_at(): void
    {
        $gate = Gate::factory()->create();

        GateUnavailability::factory()->create([
            'gate_id'  => $gate->id,
            'start_at' => '2026-05-15 14:00:00',
            'end_at'   => '2026-05-15 16:00:00',
        ]);
        GateUnavailability::factory()->create([
            'gate_id'  => $gate->id,
            'start_at' => '2026-05-10 08:00:00',
            'end_at'   => '2026-05-10 10:00:00',
        ]);

        $response = $this->apiGet('/api/v1/gates/unavailabilities');

        $response->assertOk();

        $data = $response->json('data');
        $this->assertTrue($data[0]['start_at'] < $data[1]['start_at']);
    }

    // ── POST: create unavailability ──────────────────────────────────

    public function test_can_create_unavailability(): void
    {
        $gate = Gate::factory()->create();

        $response = $this->apiPost('/api/v1/gates/unavailabilities', [
            'gate_id'  => $gate->id,
            'start_at' => '2026-05-10T08:00:00Z',
            'end_at'   => '2026-05-10T12:00:00Z',
            'reason'   => 'Maintenance',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.gate_id', $gate->id)
            ->assertJsonPath('data.reason', 'Maintenance')
            ->assertJsonStructure([
                'data' => ['id', 'gate_id', 'start_at', 'end_at', 'reason', 'created_at', 'updated_at'],
            ]);

        $this->assertDatabaseHas('gate_unavailabilities', [
            'gate_id' => $gate->id,
            'reason'  => 'Maintenance',
        ]);
    }

    public function test_can_create_unavailability_without_reason(): void
    {
        $gate = Gate::factory()->create();

        $response = $this->apiPost('/api/v1/gates/unavailabilities', [
            'gate_id'  => $gate->id,
            'start_at' => '2026-05-10T08:00:00Z',
            'end_at'   => '2026-05-10T12:00:00Z',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.reason', null);
    }

    public function test_can_create_unavailability_with_null_reason(): void
    {
        $gate = Gate::factory()->create();

        $response = $this->apiPost('/api/v1/gates/unavailabilities', [
            'gate_id'  => $gate->id,
            'start_at' => '2026-05-10T08:00:00Z',
            'end_at'   => '2026-05-10T12:00:00Z',
            'reason'   => null,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.reason', null);
    }

    public function test_create_requires_all_mandatory_fields(): void
    {
        $this->apiPost('/api/v1/gates/unavailabilities', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['gate_id', 'start_at', 'end_at']);
    }

    public function test_create_rejects_nonexistent_gate(): void
    {
        $this->apiPost('/api/v1/gates/unavailabilities', [
            'gate_id'  => 9999,
            'start_at' => '2026-05-10T08:00:00Z',
            'end_at'   => '2026-05-10T12:00:00Z',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('gate_id');
    }

    public function test_create_rejects_end_before_start(): void
    {
        $gate = Gate::factory()->create();

        $this->apiPost('/api/v1/gates/unavailabilities', [
            'gate_id'  => $gate->id,
            'start_at' => '2026-05-10T12:00:00Z',
            'end_at'   => '2026-05-10T08:00:00Z',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('end_at');
    }

    public function test_create_requires_api_key(): void
    {
        $this->postJson('/api/v1/gates/unavailabilities', [
            'gate_id'  => 1,
            'start_at' => '2026-05-10T08:00:00Z',
            'end_at'   => '2026-05-10T12:00:00Z',
        ])->assertStatus(401);
    }
}
