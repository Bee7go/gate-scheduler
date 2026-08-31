<?php

namespace Tests\Feature;

use App\Services\Flights\FlightFetchResult;
use App\Services\Flights\FlightSyncService;
use App\Services\Flights\OpenSkyService;
use App\Services\GateAllocation\GateAllocatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SyncObservabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_sync_records_completed_run(): void
    {
        [$openSky, $allocator] = $this->syncDependencies(
            new FlightFetchResult([], FlightFetchResult::SOURCE_LIVE),
            new FlightFetchResult([], FlightFetchResult::SOURCE_LIVE),
        );

        $summary = (new FlightSyncService($openSky, $allocator))->sync('command');

        $this->assertSame(0, $summary['arrivals_fetched']);
        $this->assertDatabaseHas('sync_runs', [
            'trigger' => 'command',
            'status' => 'completed',
            'arrivals_source' => 'live',
            'departures_source' => 'live',
            'failure_reason' => null,
        ]);
    }

    public function test_fallback_sync_records_degraded_run(): void
    {
        [$openSky, $allocator] = $this->syncDependencies(
            new FlightFetchResult([], FlightFetchResult::SOURCE_FALLBACK, 'circuit_breaker_open'),
            new FlightFetchResult([], FlightFetchResult::SOURCE_LIVE),
        );

        (new FlightSyncService($openSky, $allocator))->sync('scheduled');

        $this->assertDatabaseHas('sync_runs', [
            'trigger' => 'scheduled',
            'status' => 'degraded',
            'arrivals_source' => 'fallback',
            'departures_source' => 'live',
            'failure_reason' => 'arrivals_circuit_breaker_open',
        ]);
    }

    public function test_unavailable_provider_records_failed_run_without_storing_missing_direction(): void
    {
        $openSky = Mockery::mock(OpenSkyService::class);
        $openSky->shouldReceive('fetchFlightsWithStatus')
            ->with('EHAM', 'arrival')
            ->once()
            ->andReturn(new FlightFetchResult([], FlightFetchResult::SOURCE_UNAVAILABLE, 'request_failed_after_retries'));
        $openSky->shouldReceive('fetchFlightsWithStatus')
            ->with('EHAM', 'departure')
            ->once()
            ->andReturn(new FlightFetchResult([], FlightFetchResult::SOURCE_LIVE));
        $openSky->shouldReceive('storeFlights')
            ->with([], 'EHAM', 'departure')
            ->once();

        $allocator = Mockery::mock(GateAllocatorService::class);
        $allocator->shouldReceive('assignUnallocatedFlights')->once()->andReturn([]);

        (new FlightSyncService($openSky, $allocator))->sync('manual');

        $this->assertDatabaseHas('sync_runs', [
            'trigger' => 'manual',
            'status' => 'failed',
            'arrivals_source' => 'unavailable',
            'departures_source' => 'live',
            'failure_reason' => 'arrivals_request_failed_after_retries',
        ]);
    }

    public function test_unexpected_sync_error_is_recorded_before_it_is_rethrown(): void
    {
        $openSky = Mockery::mock(OpenSkyService::class);
        $openSky->shouldReceive('fetchFlightsWithStatus')
            ->with('EHAM', 'arrival')
            ->once()
            ->andThrow(new \RuntimeException('provider timeout'));
        $allocator = Mockery::mock(GateAllocatorService::class);

        try {
            (new FlightSyncService($openSky, $allocator))->sync('scheduled');
            $this->fail('Expected the sync exception to be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('provider timeout', $exception->getMessage());
        }

        $this->assertDatabaseHas('sync_runs', [
            'trigger' => 'scheduled',
            'status' => 'failed',
            'failure_reason' => 'unexpected_error',
        ]);
    }

    /**
     * @return array{0: OpenSkyService, 1: GateAllocatorService}
     */
    private function syncDependencies(FlightFetchResult $arrivals, FlightFetchResult $departures): array
    {
        $openSky = Mockery::mock(OpenSkyService::class);
        $openSky->shouldReceive('fetchFlightsWithStatus')
            ->with('EHAM', 'arrival')
            ->once()
            ->andReturn($arrivals);
        $openSky->shouldReceive('fetchFlightsWithStatus')
            ->with('EHAM', 'departure')
            ->once()
            ->andReturn($departures);
        $openSky->shouldReceive('storeFlights')
            ->with($arrivals->flights, 'EHAM', 'arrival')
            ->once();
        $openSky->shouldReceive('storeFlights')
            ->with($departures->flights, 'EHAM', 'departure')
            ->once();

        $allocator = Mockery::mock(GateAllocatorService::class);
        $allocator->shouldReceive('assignUnallocatedFlights')->once()->andReturn([]);

        return [$openSky, $allocator];
    }
}
