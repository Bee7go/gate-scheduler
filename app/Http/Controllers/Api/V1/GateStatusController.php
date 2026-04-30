<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\GateStatusRequest;
use App\Models\Gate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;

class GateStatusController extends Controller
{
    public function index(GateStatusRequest $request): JsonResponse
    {
        $at = $request->filled('at')
            ? Carbon::parse($request->input('at'))
            : now();

        $query = Gate::query()
            ->with([
                'allocations' => fn ($q) => $q
                    ->where('occupied_from', '<=', $at)
                    ->where('occupied_until', '>', $at)
                    ->with('flight'),
                'unavailabilities' => fn ($q) => $q
                    ->where('start_at', '<=', $at)
                    ->where('end_at', '>', $at),
            ]);

        if ($request->filled('gate_code')) {
            $query->where('code', $request->input('gate_code'));
        }

        $gates = $query->orderBy('code')->get();

        $data = $gates->map(function (Gate $gate) {
            $allocation = $gate->allocations->first();
            $unavailability = $gate->unavailabilities->first();

            if ($allocation) {
                $flight = $allocation->flight;

                return [
                    'gate_id'        => $gate->id,
                    'gate_code'      => $gate->code,
                    'status'         => 'occupied',
                    'occupied_until' => $allocation->occupied_until->toISOString(),
                    'flight'         => [
                        'id'        => $flight->id,
                        'icao24'    => $flight->icao24,
                        'direction' => $flight->direction,
                    ],
                ];
            }

            if ($unavailability) {
                return [
                    'gate_id'        => $gate->id,
                    'gate_code'      => $gate->code,
                    'status'         => 'maintenance',
                    'occupied_until' => null,
                    'flight'         => null,
                ];
            }

            return [
                'gate_id'        => $gate->id,
                'gate_code'      => $gate->code,
                'status'         => 'free',
                'occupied_until' => null,
                'flight'         => null,
            ];
        });

        return response()->json(['data' => $data]);
    }
}
