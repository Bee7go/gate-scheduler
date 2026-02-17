<?php

namespace App\Services;

use App\Models\Gate;
use DateTimeInterface;
use Illuminate\Support\Collection;

class GreedyGateSelectionStrategy implements GateSelectionStrategyInterface
{
    public function getOrderedGates(DateTimeInterface $flightStart = null): Collection
    {
        return Gate::orderBy('code')->get();
    }

    public function onGateAllocated(Gate $gate): void
    {
        // not applicable
    }
}
