<?php

namespace App\Services\GateAllocation\Strategies;

use App\Models\Gate;
use DateTimeInterface;
use Illuminate\Support\Collection;

interface GateSelectionStrategyInterface
{
    public function getOrderedGates(DateTimeInterface $flightStart = null): Collection;

    public function onGateAllocated(Gate $gate): void;

}
