<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateUnavailability;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $now = now();

        try {
            DB::connection()->getPdo();

            $lastFlightSyncedAt = Flight::max('updated_at');

            return response()->json([
                'data' => [
                    'status'   => 'healthy',
                    'database' => [
                        'status' => 'ok',
                    ],
                    'sync' => [
                        'last_synced_at' => $lastFlightSyncedAt
                            ? \Carbon\Carbon::parse($lastFlightSyncedAt)->toIso8601String()
                            : null,
                    ],
                    'flights' => [
                        'total' => Flight::count(),
                    ],
                    'gates' => [
                        'total'              => Gate::count(),
                        'active_allocations' => GateAllocation::where('occupied_from', '<=', $now)
                            ->where('occupied_until', '>=', $now)
                            ->count(),
                        'active_unavailabilities' => GateUnavailability::where('start_at', '<=', $now)
                            ->where('end_at', '>=', $now)
                            ->count(),
                    ],
                    'checked_at' => $now->toIso8601String(),
                ],
            ]);
        } catch (\Throwable) {
            return response()->json([
                'data' => [
                    'status'   => 'degraded',
                    'database' => [
                        'status' => 'unreachable',
                    ],
                    'sync' => [
                        'last_synced_at' => null,
                    ],
                    'flights' => [
                        'total' => null,
                    ],
                    'gates' => [
                        'total'                   => null,
                        'active_allocations'      => null,
                        'active_unavailabilities' => null,
                    ],
                    'checked_at' => $now->toIso8601String(),
                ],
            ], 503);
        }
    }
}
