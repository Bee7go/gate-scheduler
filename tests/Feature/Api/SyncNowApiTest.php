<?php

namespace Tests\Feature\Api;

use App\Models\ApiKey;
use App\Services\Flights\FlightFetchResult;
use App\Services\Flights\FlightSyncService;
use App\Services\Flights\OpenSkyService;
use App\Services\GateAllocation\GateAllocatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
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
            'key' => hash('sha256', $this->plainKey),
        ]);
    }

    private function apiPost(string $uri, array $data = []): TestResponse
    {
        return $this->postJson($uri, $data, ['X-Api-Key' => $this->plainKey]);
    }

    public function test_requires_api_key(): void
    {
        $this->postJson('/api/v1/system/sync-now')
            ->assertStatus(401);
    }

    public function test_rate_limiter_key_never_contains_the_plaintext_api_key(): void
    {
        $apiKey = ApiKey::where('key', hash('sha256', $this->plainKey))->firstOrFail();

        $request = Request::create('/api/v1/system/sync-now', 'POST');
        $request->headers->set('X-Api-Key', $this->plainKey);
        $request->attributes->set('apiKey', $apiKey);

        $limit = RateLimiter::limiter('sync-now')($request);

        $this->assertSame('sync-now:'.$apiKey->id, $limit->key);
        $this->assertStringNotContainsString($this->plainKey, (string) $limit->key);
    }

    public function test_triggers_sync_and_returns_summary(): void
    {
        $expectedSummary = [
            'arrivals_fetched' => 5,
            'departures_fetched' => 3,
            'allocation' => [
                'allocated' => 4,
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
            'arrivals_fetched' => 0,
            'departures_fetched' => 0,
            'allocation' => [],
        ]);
        $this->app->instance(FlightSyncService::class, $mock);

        $response = $this->apiPost('/api/v1/system/sync-now');

        $response->assertOk()
            ->assertJsonPath('data.arrivals_fetched', 0)
            ->assertJsonPath('data.departures_fetched', 0);
    }

    public function test_returns_409_when_sync_already_in_progress(): void
    {
        $lock = Cache::lock(FlightSyncService::LOCK_KEY, FlightSyncService::LOCK_SECONDS);
        $lock->get();

        try {
            $response = $this->apiPost('/api/v1/system/sync-now');

            $response->assertStatus(409)
                ->assertJsonPath('code', 'sync_in_progress')
                ->assertJsonPath('message', 'A sync is already in progress. Please try again later.');
        } finally {
            $lock->release();
        }
    }

    public function test_lock_is_released_after_sync_completes(): void
    {
        // A real FlightSyncService (real lock logic) with mocked edges, so the
        // acquire/release path is genuinely exercised.
        config(['services.opensky.airport_icao' => 'EHAM']);

        $openSky = Mockery::mock(OpenSkyService::class);
        $openSky->shouldReceive('fetchFlightsWithStatus')->twice()->andReturn(
            new FlightFetchResult([], FlightFetchResult::SOURCE_LIVE)
        );
        $openSky->shouldReceive('storeFlights')->twice();

        $allocator = Mockery::mock(GateAllocatorService::class);
        $allocator->shouldReceive('assignUnallocatedFlights')->once()->andReturn([
            'processed' => 0, 'assigned' => 0, 'unassigned' => 0, 'errors' => [],
        ]);

        $this->app->instance(FlightSyncService::class, new FlightSyncService($openSky, $allocator));

        $this->apiPost('/api/v1/system/sync-now')->assertOk();

        $probe = Cache::lock(FlightSyncService::LOCK_KEY, 1);
        $this->assertTrue($probe->get(), 'The sync lock was not released after the sync completed.');
        $probe->release();
    }

    public function test_rate_limited_to_one_request_per_two_minutes(): void
    {
        $mock = Mockery::mock(FlightSyncService::class);
        $mock->shouldReceive('sync')->once()->andReturn([
            'arrivals_fetched' => 0,
            'departures_fetched' => 0,
            'allocation' => [],
        ]);
        $this->app->instance(FlightSyncService::class, $mock);

        $this->apiPost('/api/v1/system/sync-now')->assertOk();

        $this->apiPost('/api/v1/system/sync-now')->assertStatus(429);
    }
}
