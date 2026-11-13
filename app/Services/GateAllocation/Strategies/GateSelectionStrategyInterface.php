<?php

namespace App\Services\GateAllocation\Strategies;

use App\Models\Gate;
use DateTimeInterface;
use Illuminate\Support\Collection;

interface GateSelectionStrategyInterface
{
    /**
     * Return all gates ordered by allocation preference for a flight
     * starting at the given time.
     */
    public function getOrderedGates(DateTimeInterface $flightStart): Collection;

    public function onGateAllocated(Gate $gate): void;
}
