<?php

namespace App\Services;

use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

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
     * Process unallocated flights and assign them to gates

     * @param int $limit
     * @return array
     */
    public function assignUnallocatedFlights(int $limit = 50): array
    {
        if ($limit <= 0) {
            throw new InvalidArgumentException('Limit must be greater than 0.');
        }

        $flights = $this->getUnallocatedFlights($limit);

        $stats = [
            'processed' => 0,
            'assigned' => 0,
            'unassigned' => 0,
            'errors' => [],
        ];

        foreach ($flights as $flight) {
            $stats['processed']++;

            try {
                $gate = $this->allocateFlightToGate($flight);

                if ($gate) {
                    $stats['assigned']++;
                } else {
                    $stats['unassigned']++;
                }
            } catch (Exception $e) {
                Log::error("Failed to allocate flight {$flight->id}: " . $e->getMessage());
                $stats['errors'][] = $flight->id;
            }
        }

        return $stats;
    }

    /**
     * Allocate a flight to a gate
     *
     * @param Flight $flight
     * @return Gate|null
     */
    private function allocateFlightToGate(Flight $flight): ?Gate
    {
        if (!$flight->first_seen_at) {
            Log::warning('gates.allocate.missing_first_seen_at', [
                'flight_id' => $flight->id,
            ]);
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

                Log::info('gates.allocate.success', [
                    'flight_id' => $flight->id,
                    'gate_id'   => $gate->id,
                ]);

                return $gate;
            }
        }

        Log::info('gates.allocate.no_gate_available', [
            'flight_id' => $flight->id,
        ]);

        return null;
    }

    /**
     * Fetch flights waiting for allocation
     *
     * @param int $limit
     * @return Collection
     */
    private function getUnallocatedFlights(int $limit): Collection
    {
        // @todo consider locking for update to avoid race conditions
        return Flight::query()
            ->doesntHave('gateAllocation')
            ->whereNotNull('first_seen_at')
            ->orderBy('first_seen_at')
            ->limit($limit)
            ->get(['id', 'first_seen_at']);

        // no need to left join on the table :)
    }
}
