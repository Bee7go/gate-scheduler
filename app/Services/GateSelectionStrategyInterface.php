<?php

namespace App\Services;

use App\Models\Gate;
use Illuminate\Support\Collection;

interface GateSelectionStrategyInterface
{
    public function getOrderedGates(): Collection;

    public function onGateAllocated(Gate $gate): void;

}
