<?php

namespace App\Services;

use App\Models\Gate;
use DateTimeInterface;
use Illuminate\Support\Collection;

interface GateSelectionStrategyInterface
{
    public function getOrderedGates(DateTimeInterface $flightStart = null): Collection;

    public function onGateAllocated(Gate $gate): void;

}
