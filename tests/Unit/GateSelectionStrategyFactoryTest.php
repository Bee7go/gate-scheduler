<?php

namespace Tests\Unit;

use App\Services\GateAllocation\Strategies\EarliestAvailableGateSelectionStrategy;
use App\Services\GateAllocation\Strategies\GateSelectionStrategyFactory;
use App\Services\GateAllocation\Strategies\GreedyGateSelectionStrategy;
use App\Services\GateAllocation\Strategies\LeastUsedGateSelectionStrategy;
use App\Services\GateAllocation\Strategies\RoundRobinGateSelectionStrategy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class GateSelectionStrategyFactoryTest extends TestCase
{
    private GateSelectionStrategyFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new GateSelectionStrategyFactory;
    }

    public function test_makes_each_configured_strategy(): void
    {
        $this->assertInstanceOf(GreedyGateSelectionStrategy::class, $this->factory->make('greedy'));
        $this->assertInstanceOf(LeastUsedGateSelectionStrategy::class, $this->factory->make('least_used'));
        $this->assertInstanceOf(RoundRobinGateSelectionStrategy::class, $this->factory->make('round_robin'));
        $this->assertInstanceOf(EarliestAvailableGateSelectionStrategy::class, $this->factory->make('earliest_available'));
    }

    public function test_defaults_to_greedy_when_no_key_given(): void
    {
        $this->assertInstanceOf(GreedyGateSelectionStrategy::class, $this->factory->make(null));
        $this->assertInstanceOf(GreedyGateSelectionStrategy::class, $this->factory->make(''));
    }

    public function test_throws_on_unknown_strategy_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown gate allocation strategy [first_come].');

        $this->factory->make('first_come');
    }
}
