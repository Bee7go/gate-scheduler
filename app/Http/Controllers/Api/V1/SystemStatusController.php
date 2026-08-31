<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SyncRun;
use App\Services\Flights\OpenSkyCircuitBreaker;
use Illuminate\Http\JsonResponse;

class SystemStatusController extends Controller
{
    public function index(OpenSkyCircuitBreaker $circuitBreaker): JsonResponse
    {
        $airport = config('services.opensky.airport_icao');
        $lastRun = SyncRun::query()
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
        $lastSuccessfulRun = SyncRun::query()
            ->where('status', 'completed')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();
        $lastFailedRun = SyncRun::query()
            ->where('status', 'failed')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'data' => [
                'sync' => [
                    'last_successful_at' => $lastSuccessfulRun?->finished_at?->toISOString(),
                    'last_failed_at' => $lastFailedRun?->finished_at?->toISOString(),
                    'last_run' => $lastRun ? [
                        'status' => $lastRun->status,
                        'trigger' => $lastRun->trigger,
                        'started_at' => $lastRun->started_at?->toISOString(),
                        'finished_at' => $lastRun->finished_at?->toISOString(),
                        'arrivals_source' => $lastRun->arrivals_source,
                        'departures_source' => $lastRun->departures_source,
                        'failure_reason' => $lastRun->failure_reason,
                    ] : null,
                ],
                'opensky' => [
                    'arrivals' => ['breaker_state' => $circuitBreaker->state($airport, 'arrival')],
                    'departures' => ['breaker_state' => $circuitBreaker->state($airport, 'departure')],
                ],
            ],
        ]);
    }
}
