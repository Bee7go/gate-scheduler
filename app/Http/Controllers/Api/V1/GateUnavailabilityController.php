<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListGateUnavailabilitiesRequest;
use App\Http\Requests\Api\V1\StoreGateUnavailabilityRequest;
use App\Models\GateUnavailability;
use Illuminate\Http\JsonResponse;

class GateUnavailabilityController extends Controller
{
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

        $unavailabilities = $query->orderBy('start_at')->get();

        return response()->json(['data' => $unavailabilities]);
    }

    public function store(StoreGateUnavailabilityRequest $request): JsonResponse
    {
        $unavailability = GateUnavailability::create($request->validated());

        return response()->json(['data' => $unavailability], 201);
    }
}
