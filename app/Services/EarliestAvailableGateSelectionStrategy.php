<?php

namespace App\Services;

use App\Models\Gate;
use DateTimeInterface;
use Illuminate\Support\Collection;

class EarliestAvailableGateSelectionStrategy implements GateSelectionStrategyInterface
{
    public function getOrderedGates(DateTimeInterface $flightStart = null): Collection
    {
        // find the Gates that will become available the earliest, close to the flight stare
        return Gate::query()
            ->withMax('allocations', 'occupied_until')
            ->get()
            ->sortBy(function ($gate) use ($flightStart) {

                $lastOccupied = $gate->allocations_max_occupied_until;

                if (!$lastOccupied) {
                    return PHP_INT_MAX;
                }

                $gap = $flightStart->getTimestamp() - strtotime($lastOccupied);

                return $gap >= 0 ? $gap : PHP_INT_MAX;
            })
            ->values();
    }

    public function onGateAllocated($gate): void
    {
        // not applicable
    }
}
