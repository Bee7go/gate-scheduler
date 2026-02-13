<?php

namespace App\Services;

use App\Models\Gate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class RoundRobinGateSelectionStrategy implements GateSelectionStrategyInterface
{
    private const CACHE_KEY = 'gates.round_robin.last_gate_id';

    public function getOrderedGates(): Collection
    {
        Log::info('Selecting gates by round robin');

        $gates = Gate::orderBy('code')->get();
        if ($gates->isEmpty()) {
            return $gates;
        }

        $lastGateId = Cache::get(self::CACHE_KEY);
        if (!$lastGateId) {
            return $gates;
        }

        $idx = $gates->search(fn (Gate $g) => (int) $g->id === (int) $lastGateId);
        if ($idx === false) {
            return $gates;
        }

        return $gates->slice($idx + 1)->values()
            ->concat($gates->slice(0, $idx + 1)->values());
    }

    public function onGateAllocated(Gate $gate): void
    {
        Cache::put(self::CACHE_KEY, $gate->id, now()->addHours(12));
    }
}
