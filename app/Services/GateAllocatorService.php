<?php

namespace App\Services;

use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use Illuminate\Support\Collection;

class GateAllocatorService
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        // @todo asta ar putea fi abstract astfel incat sa putem folosi orice tip de serviciu pt a stabili daca un gate e available
        private readonly GateAvailabilityService $availabilityService
    ) {}

    public function assignUnallocatedFlights(int $limit = 50): array
    {
        $assigned = 0;
        $unassigned = 0;

        $flights = $this->getUnallocatedFlights($limit);

        foreach ($flights as $flight) {
            $gate = $this->allocateFlightToGate($flight);
            if ($gate) {
                $assigned++;
            } else {
                $unassigned++;
            }
        }

        return [
            'processed' => $flights->count(),
            'assigned' => $assigned,
            'unassigned' => $unassigned
        ];
    }

    // @todo could be private
    public function allocateFlightToGate(Flight $flight): ?Gate
    {
        $from = $flight->first_seen_at;
        $until = (clone $from)->addMinutes(config('gates.occupation_minutes', 90));

        $gates = Gate::orderBy('code')->get();

        foreach ($gates as $gate) {
            if ($this->availabilityService->isGateAvailable($gate->id, $from, $until)) {
                GateAllocation::create([
                    'gate_id' => $gate->id,
                    'flight_id' => $flight->id,
                    'occupied_from' => $from,
                    'occupied_until' => $until,
                ]);

                return $gate;
            }
        }

        return null;
    }


    private function getUnallocatedFlights(int $limit): Collection
    {
        return Flight::query()
            ->leftJoin('gate_allocations', 'gate_allocations.flight_id', '=', 'flights.id')
            ->whereNull('gate_allocations.flight_id')
            ->orderBy('flights.first_seen_at')
            ->limit($limit)
            ->select('flights.id', 'flights.first_seen_at')
            ->get();
    }
}
