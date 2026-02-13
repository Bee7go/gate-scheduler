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
        private ?GateSelectionStrategyInterface $gateSelectionStrategy = null
    ) {
        $this->gateSelectionStrategy = $this->getGateSelectionStrategy();
    }

    // @todo asta se poate muta intr-un binding
    private function getGateSelectionStrategy(): GateSelectionStrategyInterface
    {
        $allocationStrategy = config('services.gates.allocation_strategy');
        Log::info('------Gate allocation strategy: ' . $allocationStrategy);
        return match ($allocationStrategy) {
            'least_used' => new LeastUsedGateSelectionStrategy(),
            'round_robin' => new RoundRobinGateSelectionStrategy(),
            default => new GreedyGateSelectionStrategy(),
        };
    }
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
