<?php

namespace App\Services\Flights;

use App\Exceptions\SyncInProgressException;
use App\Models\SyncRun;
use App\Services\GateAllocation\GateAllocatorService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class FlightSyncService
{
    public const LOCK_KEY = 'sync-flights';

    /**
     * Comfortably longer than the job timeout (120s) so the lock can never
     * expire while a sync is still legally running.
     */
    public const LOCK_SECONDS = 300;

    public function __construct(
        private readonly OpenSkyService $openSkyService,
        private readonly GateAllocatorService $gateAllocator
    ) {}

    /**
     * Run a full sync guarded by a cache lock so concurrent entry points
     * (API, queued job, console command) can never overlap.
     *
     * @throws SyncInProgressException when another sync holds the lock
     */
    public function sync(string $trigger = 'manual'): array
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_SECONDS);

        if (! $lock->get()) {
            throw new SyncInProgressException;
        }

        try {
            return $this->runSync($trigger);
        } finally {
            $lock->release();
        }
    }

    private function runSync(string $trigger): array
    {
        $airport = config('services.opensky.airport_icao');
        $syncRun = SyncRun::create([
            'trigger' => $trigger,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $arrivals = $this->openSkyService->fetchFlightsWithStatus($airport, 'arrival');
            $departures = $this->openSkyService->fetchFlightsWithStatus($airport, 'departure');

            $summary = [
                'arrivals_fetched' => count($arrivals->flights),
                'departures_fetched' => count($departures->flights),
                'allocation' => [],
            ];

            if ($arrivals->isAvailable()) {
                $this->openSkyService->storeFlights($arrivals->flights, $airport, 'arrival');
            }

            if ($departures->isAvailable()) {
                $this->openSkyService->storeFlights($departures->flights, $airport, 'departure');
            }

            $summary['allocation'] = $this->gateAllocator->assignUnallocatedFlights();

            $status = $this->resolveStatus($arrivals, $departures);
            $syncRun->update([
                'status' => $status,
                'arrivals_source' => $arrivals->source,
                'departures_source' => $departures->source,
                'arrivals_fetched' => $summary['arrivals_fetched'],
                'departures_fetched' => $summary['departures_fetched'],
                'allocation_summary' => $summary['allocation'],
                'failure_reason' => $this->resolveFailureReason($arrivals, $departures),
                'finished_at' => now(),
            ]);

            Log::info('flight.sync.completed', [...$summary, 'status' => $status]);

            return $summary;
        } catch (Throwable $exception) {
            $syncRun->update([
                'status' => 'failed',
                'failure_reason' => 'unexpected_error',
                'finished_at' => now(),
            ]);

            throw $exception;
        }
    }

    private function resolveStatus(FlightFetchResult $arrivals, FlightFetchResult $departures): string
    {
        if (! $arrivals->isAvailable() || ! $departures->isAvailable()) {
            return 'failed';
        }

        if ($arrivals->source === FlightFetchResult::SOURCE_FALLBACK
            || $departures->source === FlightFetchResult::SOURCE_FALLBACK) {
            return 'degraded';
        }

        return 'completed';
    }

    private function resolveFailureReason(FlightFetchResult $arrivals, FlightFetchResult $departures): ?string
    {
        $reasons = [];

        foreach (['arrivals' => $arrivals, 'departures' => $departures] as $direction => $result) {
            if ($result->source !== FlightFetchResult::SOURCE_LIVE && $result->reason) {
                $reasons[] = "{$direction}_{$result->reason}";
            }
        }

        return $reasons === [] ? null : implode(',', $reasons);
    }
}
