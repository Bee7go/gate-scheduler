<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Concerns\RespondsWithPagination;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListAllocationsRequest;
use App\Http\Resources\Api\V1\GateAllocationResource;
use App\Models\GateAllocation;
use Illuminate\Http\JsonResponse;

class AllocationController extends Controller
{
    use RespondsWithPagination;

    /**
     * List allocations
     *
     * Flight-to-gate allocations with their gate and flight, filterable by
     * gate code and occupation window.
     */
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
            'data' => GateAllocationResource::collection($allocations->items()),
            'meta' => $this->paginationMeta($allocations),
        ]);
    }
}
