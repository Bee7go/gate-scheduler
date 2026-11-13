<?php

namespace App\Services\GateAllocation\Strategies;

use App\Models\Gate;
use DateTimeInterface;
use Illuminate\Support\Collection;

class EarliestAvailableGateSelectionStrategy implements GateSelectionStrategyInterface
{
    public function getOrderedGates(DateTimeInterface $flightStart): Collection
    {
        // Prefer the gates that become free closest before the flight starts.
        return Gate::query()
            ->withMax('allocations', 'occupied_until')
            ->get()
            ->sortBy(function (Gate $gate) use ($flightStart) {
                $lastOccupied = $gate->allocations_max_occupied_until;

                if (! $lastOccupied) {
                    return PHP_INT_MAX;
                }

                $gap = $flightStart->getTimestamp() - strtotime($lastOccupied);

                return $gap >= 0 ? $gap : PHP_INT_MAX;
            })
            ->values();
    }

    public function onGateAllocated(Gate $gate): void
    {
        // not applicable
    }
}
