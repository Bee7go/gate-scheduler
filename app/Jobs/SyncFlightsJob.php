<?php

namespace App\Jobs;

use App\Services\Flights\FlightSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncFlightsJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 1;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];

    public function handle(FlightSyncService $flightSyncService): void
    {
        $summary = $flightSyncService->sync();

        Log::info('queue.job.sync_flights.completed', [
            'summary' => $summary,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('queue.job.sync_flights.failed', [
            'message' => $exception?->getMessage(),
        ]);
    }
}
