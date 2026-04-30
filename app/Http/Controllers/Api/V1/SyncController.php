<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Flights\FlightSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class SyncController extends Controller
{
    public function store(FlightSyncService $flightSyncService): JsonResponse
    {
        $lock = Cache::lock('sync-flights', 120);

        if (!$lock->get()) {
            return response()->json([
                'message' => 'A sync is already in progress. Please try again later.',
            ], 409);
        }

        try {
            $summary = $flightSyncService->sync();

            return response()->json(['data' => $summary]);
        } finally {
            $lock->release();
        }
    }
}
