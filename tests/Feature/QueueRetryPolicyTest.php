<?php

namespace Tests\Feature;

use App\Jobs\GenerateGateAllocationReportJob;
use App\Jobs\SyncFlightsJob;
use App\Models\Gate;
use App\Services\Flights\OpenSkyCircuitBreaker;
use App\Services\Flights\FlightSyncService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class QueueRetryPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_flights_command_dispatches_single_try_job(): void
    {
        Queue::fake();

        $this->artisan('app:sync-flights')->assertExitCode(0);

        Queue::assertPushed(SyncFlightsJob::class, function (SyncFlightsJob $job) {
            return $job->tries === 1;
        });
    }

    public function test_gate_report_command_dispatches_single_try_job(): void
    {
        Queue::fake();

        $this->artisan('app:gate-allocation-report')->assertExitCode(0);

        Queue::assertPushed(GenerateGateAllocationReportJob::class, function (GenerateGateAllocationReportJob $job) {
            return $job->tries === 1;
        });
    }

    public function test_sync_flights_job_fetches_and_allocates_when_processed(): void
    {
        Gate::factory()->count(2)->create();

        Http::fake([
            'https://auth.opensky.test/oauth/token' => Http::response(['access_token' => 'fake-token'], 200),
            'https://opensky-network.org/api/flights/arrival*' => Http::response([
                ['icao24' => 'TEST123', 'firstSeen' => 1736510400, 'lastSeen' => 1736512200],
            ], 200),
            'https://opensky-network.org/api/flights/departure*' => Http::response([
                ['icao24' => 'TEST456', 'firstSeen' => 1736511000, 'lastSeen' => 1736512800],
            ], 200),
        ]);

        $job = new SyncFlightsJob();
        $job->handle(app(FlightSyncService::class));

        $this->assertDatabaseHas('flights', ['icao24' => 'TEST123']);
        $this->assertDatabaseHas('flights', ['icao24' => 'TEST456']);
        $this->assertDatabaseCount('gate_allocations', 2);
    }

    public function test_job_retry_configuration_is_single_try(): void
    {
        $syncJob = new SyncFlightsJob();
        $reportJob = new GenerateGateAllocationReportJob();

        $this->assertSame(1, $syncJob->tries);
        $this->assertSame([30, 120, 300], $syncJob->backoff);

        $this->assertSame(1, $reportJob->tries);
        $this->assertSame([30, 120, 300], $reportJob->backoff);
    }

    public function test_sync_flights_job_logs_failure(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('queue.job.sync_flights.failed', Mockery::on(function (array $context): bool {
                return ($context['message'] ?? null) === 'OpenSky API timeout';
            }));

        $job = new SyncFlightsJob();
        $exception = new \Exception('OpenSky API timeout');

        $job->failed($exception);
    }

    public function test_job_skips_live_flight_calls_when_circuit_is_open(): void
    {
        config()->set('services.opensky.breaker_failure_threshold', 1);

        $breaker = new OpenSkyCircuitBreaker();
        $breaker->recordFailure('EHAM', 'arrival');
        $breaker->recordFailure('EHAM', 'departure');

        Gate::factory()->count(2)->create();

        Http::fake([
            'https://auth.opensky.test/oauth/token' => Http::response(['access_token' => 'fake-token'], 200),
            'https://opensky-network.org/api/flights/arrival*' => Http::response([], 200),
            'https://opensky-network.org/api/flights/departure*' => Http::response([], 200),
        ]);

        $start = microtime(true);

        $job = new SyncFlightsJob();
        $job->handle(app(FlightSyncService::class));

        $durationSeconds = microtime(true) - $start;

        $this->assertLessThan(1, $durationSeconds);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/api/flights/'));
    }

    public function test_scheduler_lists_sync_and_report_jobs(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('App\\Jobs\\SyncFlightsJob')
            ->expectsOutputToContain('App\\Jobs\\GenerateGateAllocationReportJob')
            ->assertExitCode(0);
    }

    public function test_exhausted_job_is_recorded_in_failed_jobs_table(): void
    {
        config()->set('queue.default', 'database');

        $mock = Mockery::mock(FlightSyncService::class);
        $mock->shouldReceive('sync')->once()->andThrow(new \RuntimeException('boom'));
        $this->app->instance(FlightSyncService::class, $mock);

        SyncFlightsJob::dispatch();

        $this->artisan('queue:work', ['--once' => true, '--tries' => 1])->assertExitCode(0);

        $this->assertDatabaseCount('failed_jobs', 1);
    }
}
