<?php

namespace App\Services\GateAllocation\Strategies;

use InvalidArgumentException;

class GateSelectionStrategyFactory
{
    private array $map = [
        'greedy' => GreedyGateSelectionStrategy::class,
        'least_used' => LeastUsedGateSelectionStrategy::class,
        'round_robin' => RoundRobinGateSelectionStrategy::class,
        'earliest_available' => EarliestAvailableGateSelectionStrategy::class,
    ];

    public function make(?string $strategyKey = null): GateSelectionStrategyInterface
    {
        $key = $strategyKey ?: 'greedy';

        $class = $this->map[$key] ?? null;
        if (!$class) {
            throw new InvalidArgumentException("Unknown gate allocation strategy [{$key}].");
        }

        return new $class();
    }
}
