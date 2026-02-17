<?php

namespace App\Services\GateAllocation;

use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateUnavailability;
use Illuminate\Support\Facades\Log;

class GateAllocationReportService
{
    public function generate(): array
    {
        $now = now();

        // total gates
        $totalGates = Gate::query()->count();

        // gates unavailable right now
        $blockedGateIds = GateUnavailability::query()
            ->where('start_at', '<=', $now)
            ->where('end_at', '>=', $now)
            ->pluck('gate_id')
            ->unique();

        $blockedGates = $blockedGateIds->count();

        // allocations active right now
        $occupiedGates = GateAllocation::query()
            ->where('occupied_from', '<=', $now)
            ->where('occupied_until', '>=', $now)
            ->count('gate_id');

        $freeGates = max(0, $totalGates - $blockedGates - $occupiedGates);

        $allocatedFlightsTotal = GateAllocation::query()->distinct('flight_id')->count('flight_id');
        $flightsTotal = Flight::query()->count();

        $unallocatedFlights = max(0, $flightsTotal - $allocatedFlightsTotal);

        $exceptions = [];

        // invalid intervals allocated
        $invalidIntervals = GateAllocation::query()
            ->whereColumn('occupied_until', '<=', 'occupied_from')
            ->count();

        if ($invalidIntervals > 0) {
            $exceptions[] = [
                'type' => 'invalid_interval',
                'count' => $invalidIntervals,
            ];
        }

        // active allocations on blocked gates
        $activeOnBlocked = GateAllocation::query()
            ->whereIn('gate_id', $blockedGateIds)
            ->where('occupied_from', '<=', $now)
            ->where('occupied_until', '>=', $now)
            ->count();

        if ($activeOnBlocked > 0) {
            $exceptions[] = [
                'type' => 'active_allocation_on_blocked_gate',
                'count' => $activeOnBlocked,
            ];
        }

        // overlaps on the same gate (any time, not only active)
        $overlaps = GateAllocation::query()
            ->from('gate_allocations as a')
            ->join('gate_allocations as b', function ($join) {
                $join->on('a.gate_id', '=', 'b.gate_id')
                    ->whereColumn('a.id', '<', 'b.id')
                    ->whereColumn('a.occupied_from', '<', 'b.occupied_until')
                    ->whereColumn('a.occupied_until', '>', 'b.occupied_from');
            })
            ->count();

        if ($overlaps > 0) {
            $exceptions[] = [
                'type' => 'overlapping_allocations_same_gate',
                'count' => $overlaps,
            ];
        }

        $report = [
            'timestamp' => $now->toDateTimeString(),
            'stats' => [
                'flights_total' => $flightsTotal,
                'allocated_flights_total' => $allocatedFlightsTotal,
                'unallocated_flights_total' => $unallocatedFlights,

                'gates_total' => $totalGates,
                'gates_blocked_now' => $blockedGates,
                'gates_occupied_now' => $occupiedGates,
                'gates_free_now' => $freeGates,
            ],
            'exceptions' => $exceptions,
        ];

        Log::info('allocation.report.completed', $report);

        return $report;
    }
}
