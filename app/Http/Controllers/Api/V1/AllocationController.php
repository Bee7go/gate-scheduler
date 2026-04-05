<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAllocationsRequest;
use App\Models\GateAllocation;
use Illuminate\Http\JsonResponse;

class AllocationController extends Controller
{
    public function index(ListAllocationsRequest $request): JsonResponse
    {
        $query = GateAllocation::with(['gate', 'flight']);

        if ($request->filled('gate_code')) {
            $query->whereHas('gate', fn ($q) => $q->where('code', $request->input('gate_code')));
        }

        if ($request->filled('occupied_from')) {
            $query->where('occupied_from', '>=', $request->input('occupied_from'));
        }

        if ($request->filled('occupied_until')) {
            $query->where('occupied_until', '<=', $request->input('occupied_until'));
        }

        $perPage = $request->integer('per_page', 15);

        $allocations = $query->orderBy('occupied_from')->paginate($perPage);

        return response()->json([
            'current_page' => $allocations->currentPage(),
            'data'         => $allocations->items(),
            'last_page'    => $allocations->lastPage(),
            'total'        => $allocations->total(),
        ]);
    }
}
