<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListGateUnavailabilitiesRequest;
use App\Http\Requests\Api\V1\StoreGateUnavailabilityRequest;
use App\Http\Resources\Api\V1\GateUnavailabilityResource;
use App\Models\GateUnavailability;
use Illuminate\Http\JsonResponse;

class GateUnavailabilityController extends Controller
{
    use RespondsWithPagination;

    /**
     * List gate unavailabilities
     *
     * Maintenance and blocking windows, filterable by gate and date range,
     * ordered by start time.
     */
    public function index(ListGateUnavailabilitiesRequest $request): JsonResponse
    {
        $query = GateUnavailability::query();

        if ($request->filled('gate_id')) {
            $query->where('gate_id', $request->integer('gate_id'));
        }

        if ($request->filled('from')) {
            $query->where('end_at', '>=', $request->input('from'));
        }

        if ($request->filled('to')) {
            $query->where('start_at', '<=', $request->input('to'));
        }

        $unavailabilities = $query->orderBy('start_at')
            ->paginate($request->integer('per_page', 15));

        return response()->json([
            'data' => GateUnavailabilityResource::collection($unavailabilities->items()),
            'meta' => $this->paginationMeta($unavailabilities),
        ]);
    }

    /**
     * Create a gate unavailability
     *
     * Marks a gate unavailable for a period (maintenance, cleaning, repairs).
     * The gate is skipped by the allocator while the window is active.
     */
    public function store(StoreGateUnavailabilityRequest $request): JsonResponse
    {
        $unavailability = GateUnavailability::create($request->validated());

        return response()->json(['data' => new GateUnavailabilityResource($unavailability)], 201);
    }
}
