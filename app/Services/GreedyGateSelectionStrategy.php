<?php

namespace App\Services;

use App\Models\Gate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class GreedyGateSelectionStrategy implements GateSelectionStrategyInterface
{
    public function getOrderedGates(): Collection
    {
        Log::info('Selecting gates by greedy');

        return Gate::orderBy('code')->get();
    }

    public function onGateAllocated(Gate $gate): void
    {
        // not applicable
        // @todo but could use for logging
    }
}
