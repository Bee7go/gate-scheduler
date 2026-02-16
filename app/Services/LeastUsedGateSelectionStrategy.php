<?php

namespace App\Services;

use App\Models\Gate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class LeastUsedGateSelectionStrategy implements GateSelectionStrategyInterface
{
    public function getOrderedGates(): Collection
    {
        Log::info('Selecting gates by least used');
        return Gate::withCount('allocations')
            ->orderBy('allocations_count')
            ->get();
    }

    public function onGateAllocated(Gate $gate): void
    {
        // not applicable
    }
}
