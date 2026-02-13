<?php

namespace App\Services;

use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GateAllocatorService
{
    /**
     * Create a new class instance.
     */
    public function __construct(
        private readonly GateAvailabilityService $availabilityService,
        private readonly GateSelectionStrategyInterface $gateSelectionStrategy
    ) {
    }

    /**
     * @param int $limit
     * @return array
     */
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

    /**
     * @param Flight $flight
     * @return Gate|null
     */
    private function allocateFlightToGate(Flight $flight): ?Gate
    {
        if (!$flight->first_seen_at) {
            Log::warning('gates.allocate.missing_first_seen_at', ['flightId' => $flight->id]);
            return null;
        }
        $from = $flight->first_seen_at;
        $until = (clone $from)->addMinutes((int)config('services.gates.occupation_minutes', 90));

        $gates = $this->gateSelectionStrategy->getOrderedGates();

        foreach ($gates as $gate) {
            if ($this->availabilityService->isGateAvailable($gate->id, $from, $until)) {
                GateAllocation::create([
                    'gate_id' => $gate->id,
                    'flight_id' => $flight->id,
                    'occupied_from' => $from,
                    'occupied_until' => $until,
                ]);

                $this->gateSelectionStrategy->onGateAllocated($gate);

                return $gate;
            }
        }

        // no gate was found available
        return null;
    }

    /**
     * @param int $limit
     * @return Collection
     */
    private function getUnallocatedFlights(int $limit): Collection
    {
        return Flight::query()
            ->doesntHave('gateAllocation')
            ->orderBy('first_seen_at')
            ->limit($limit)
            ->get(['id', 'first_seen_at']);

        // no need to left join on the table :)
    }
}
