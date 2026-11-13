<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GateStatusRequest;
use App\Services\GateStatus\GateStatusService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class GateStatusController extends Controller
{
    public function index(GateStatusRequest $request, GateStatusService $gateStatusService): JsonResponse
    {
        $at = $request->filled('at')
            ? Carbon::parse($request->input('at'))
            : now();

        $data = $gateStatusService->statusAt($at, $request->input('gate_code'));

        return response()->json(['data' => $data]);
    }
}
