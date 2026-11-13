<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\SyncInProgressException;
use App\Http\Controllers\Controller;
use App\Services\Flights\FlightSyncService;
use Illuminate\Http\JsonResponse;

class SyncController extends Controller
{
    public function store(FlightSyncService $flightSyncService): JsonResponse
    {
        try {
            $summary = $flightSyncService->sync('manual');
        } catch (SyncInProgressException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $summary]);
    }
}
