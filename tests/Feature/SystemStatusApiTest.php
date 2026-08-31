<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\SyncRun;
use App\Services\Flights\OpenSkyCircuitBreaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemStatusApiTest extends TestCase
{
    use RefreshDatabase;

    private string $plainKey = 'test-api-key-for-system-status';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.opensky.airport_icao' => 'EHAM']);
        ApiKey::create(['name' => 'Test Client', 'key' => hash('sha256', $this->plainKey)]);
    }

    public function test_requires_api_key(): void
    {
        $this->getJson('/api/v1/system/status')->assertStatus(401);
    }

    public function test_returns_safe_sync_and_circuit_breaker_status(): void
    {
        $completedRun = SyncRun::create([
            'trigger' => 'scheduled',
            'status' => 'completed',
            'arrivals_source' => 'live',
            'departures_source' => 'live',
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(9),
        ]);
        $failedRun = SyncRun::create([
            'trigger' => 'manual',
            'status' => 'failed',
            'arrivals_source' => 'unavailable',
            'departures_source' => 'live',
            'failure_reason' => 'arrivals_request_failed_after_retries',
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        config(['services.opensky.breaker_failure_threshold' => 1]);
        (new OpenSkyCircuitBreaker)->recordFailure('EHAM', 'arrival');

        $response = $this->getJson('/api/v1/system/status', ['X-Api-Key' => $this->plainKey]);

        $response->assertOk()
            ->assertJsonPath('data.sync.last_successful_at', $completedRun->finished_at->toISOString())
            ->assertJsonPath('data.sync.last_failed_at', $failedRun->finished_at->toISOString())
            ->assertJsonPath('data.sync.last_run.status', 'failed')
            ->assertJsonPath('data.sync.last_run.failure_reason', 'arrivals_request_failed_after_retries')
            ->assertJsonPath('data.opensky.arrivals.breaker_state', 'open')
            ->assertJsonPath('data.opensky.departures.breaker_state', 'closed')
            ->assertJsonMissing(['allocation_summary' => []])
            ->assertJsonMissing(['key' => hash('sha256', $this->plainKey)]);
    }

    public function test_reports_half_open_breaker_after_the_cooldown(): void
    {
        config([
            'services.opensky.breaker_failure_threshold' => 1,
            'services.opensky.breaker_cooldown_seconds' => 60,
        ]);
        (new OpenSkyCircuitBreaker)->recordFailure('EHAM', 'arrival');

        $this->travel(61)->seconds();

        $this->getJson('/api/v1/system/status', ['X-Api-Key' => $this->plainKey])
            ->assertOk()
            ->assertJsonPath('data.opensky.arrivals.breaker_state', 'half_open');
    }
}
