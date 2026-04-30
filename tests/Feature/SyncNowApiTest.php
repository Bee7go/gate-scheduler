<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Services\Flights\FlightSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\TestCase;

class SyncNowApiTest extends TestCase
{
    use RefreshDatabase;

    private string $plainKey = 'test-api-key-for-sync';

    protected function setUp(): void
    {
        parent::setUp();

        ApiKey::create([
            'name' => 'Test Client',
            'key'  => hash('sha256', $this->plainKey),
        ]);
    }

    private function apiPost(string $uri, array $data = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($uri, $data, ['X-Api-Key' => $this->plainKey]);
    }

    public function test_requires_api_key(): void
    {
        $this->postJson('/api/v1/system/sync-now')
            ->assertStatus(401);
    }

    public function test_triggers_sync_and_returns_summary(): void
    {
        $expectedSummary = [
            'arrivals_fetched'   => 5,
            'departures_fetched' => 3,
            'allocation'         => [
                'allocated'   => 4,
                'unallocated' => 1,
            ],
        ];

        $mock = Mockery::mock(FlightSyncService::class);
        $mock->shouldReceive('sync')->once()->andReturn($expectedSummary);
        $this->app->instance(FlightSyncService::class, $mock);

        $response = $this->apiPost('/api/v1/system/sync-now');

        $response->assertOk()
            ->assertJsonPath('data.arrivals_fetched', 5)
            ->assertJsonPath('data.departures_fetched', 3)
            ->assertJsonPath('data.allocation.allocated', 4);
    }

    public function test_returns_empty_summary_when_no_flights(): void
    {
        $mock = Mockery::mock(FlightSyncService::class);
        $mock->shouldReceive('sync')->once()->andReturn([
            'arrivals_fetched'   => 0,
            'departures_fetched' => 0,
            'allocation'         => [],
        ]);
        $this->app->instance(FlightSyncService::class, $mock);

        $response = $this->apiPost('/api/v1/system/sync-now');

        $response->assertOk()
            ->assertJsonPath('data.arrivals_fetched', 0)
            ->assertJsonPath('data.departures_fetched', 0);
    }

    public function test_returns_409_when_sync_already_in_progress(): void
    {
        $lock = Cache::lock('sync-flights', 120);
        $lock->get();

        try {
            $response = $this->apiPost('/api/v1/system/sync-now');

            $response->assertStatus(409)
                ->assertJsonPath('message', 'A sync is already in progress. Please try again later.');
        } finally {
            $lock->release();
        }
    }

    public function test_lock_is_released_after_sync_completes(): void
    {
        $mock = Mockery::mock(FlightSyncService::class);
        $mock->shouldReceive('sync')->once()->andReturn([
            'arrivals_fetched'   => 0,
            'departures_fetched' => 0,
            'allocation'         => [],
        ]);
        $this->app->instance(FlightSyncService::class, $mock);

        $this->apiPost('/api/v1/system/sync-now')->assertOk();

        // Move past rate limit window so we can test lock behavior in isolation
        $this->travel(3)->minutes();

        // Lock should be released — a second call should succeed
        $mock2 = Mockery::mock(FlightSyncService::class);
        $mock2->shouldReceive('sync')->once()->andReturn([
            'arrivals_fetched'   => 0,
            'departures_fetched' => 0,
            'allocation'         => [],
        ]);
        $this->app->instance(FlightSyncService::class, $mock2);

        $this->apiPost('/api/v1/system/sync-now')->assertOk();
    }

    public function test_rate_limited_to_one_request_per_two_minutes(): void
    {
        $mock = Mockery::mock(FlightSyncService::class);
        $mock->shouldReceive('sync')->once()->andReturn([
            'arrivals_fetched'   => 0,
            'departures_fetched' => 0,
            'allocation'         => [],
        ]);
        $this->app->instance(FlightSyncService::class, $mock);

        $this->apiPost('/api/v1/system/sync-now')->assertOk();

        $this->apiPost('/api/v1/system/sync-now')->assertStatus(429);
    }
}
