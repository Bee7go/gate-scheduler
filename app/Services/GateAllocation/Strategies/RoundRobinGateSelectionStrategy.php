<?php

namespace App\Services\GateAllocation\Strategies;

use App\Models\Gate;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class RoundRobinGateSelectionStrategy implements GateSelectionStrategyInterface
{
    private const CACHE_KEY = 'gates.round_robin.last_gate_id';

    public function getOrderedGates(DateTimeInterface $flightStart = null): Collection
    {
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
