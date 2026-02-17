<?php

namespace App\Services\GateAllocation\Strategies;

use App\Models\Gate;
use DateTimeInterface;
use Illuminate\Support\Collection;

class LeastUsedGateSelectionStrategy implements GateSelectionStrategyInterface
{
    public function getOrderedGates(DateTimeInterface $flightStart = null): Collection
    {
        return Gate::withCount('allocations')
            ->orderBy('allocations_count')
            ->get();
    }

    public function onGateAllocated(Gate $gate): void
    {
        // not applicable
    }
}
