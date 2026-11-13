<?php

namespace Tests\Feature\Services;

use App\Models\Gate;
use App\Models\GateAllocation;
use App\Services\GateAllocation\Strategies\EarliestAvailableGateSelectionStrategy;
use App\Services\GateAllocation\Strategies\GreedyGateSelectionStrategy;
use App\Services\GateAllocation\Strategies\LeastUsedGateSelectionStrategy;
use App\Services\GateAllocation\Strategies\RoundRobinGateSelectionStrategy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GateSelectionStrategiesTest extends TestCase
{
    use RefreshDatabase;

    public function test_greedy_orders_gates_by_code(): void
    {
        Gate::factory()->create(['code' => 'B2']);
        Gate::factory()->create(['code' => 'A1']);
        Gate::factory()->create(['code' => 'C3']);

        $ordered = (new GreedyGateSelectionStrategy)->getOrderedGates(now());

        $this->assertSame(['A1', 'B2', 'C3'], $ordered->pluck('code')->all());
    }

    public function test_least_used_prefers_gates_with_fewest_allocations(): void
    {
        $busy = Gate::factory()->create(['code' => 'A1']);
        $quiet = Gate::factory()->create(['code' => 'B2']);

        GateAllocation::factory()->count(2)->for($busy)->create();

        $ordered = (new LeastUsedGateSelectionStrategy)->getOrderedGates(now());

        $this->assertSame([$quiet->id, $busy->id], $ordered->pluck('id')->all());
    }

    public function test_round_robin_rotates_past_the_last_allocated_gate(): void
    {
        $gateA = Gate::factory()->create(['code' => 'A1']);
        $gateB = Gate::factory()->create(['code' => 'B2']);
        $gateC = Gate::factory()->create(['code' => 'C3']);

        $strategy = new RoundRobinGateSelectionStrategy;

        $this->assertSame(
            [$gateA->id, $gateB->id, $gateC->id],
            $strategy->getOrderedGates(now())->pluck('id')->all()
        );

        $strategy->onGateAllocated($gateB);

        $this->assertSame(
            [$gateC->id, $gateA->id, $gateB->id],
            $strategy->getOrderedGates(now())->pluck('id')->all()
        );
    }

    public function test_earliest_available_prefers_the_gate_freed_closest_before_the_flight(): void
    {
        $flightStart = now();

        $freedRecently = Gate::factory()->create(['code' => 'A1']);
        $freedLongAgo = Gate::factory()->create(['code' => 'B2']);
        $stillOccupied = Gate::factory()->create(['code' => 'C3']);

        GateAllocation::factory()->for($freedRecently)->create([
            'occupied_from' => $flightStart->copy()->subHours(2),
            'occupied_until' => $flightStart->copy()->subHour(),
        ]);
        GateAllocation::factory()->for($freedLongAgo)->create([
            'occupied_from' => $flightStart->copy()->subHours(6),
            'occupied_until' => $flightStart->copy()->subHours(5),
        ]);
        GateAllocation::factory()->for($stillOccupied)->create([
            'occupied_from' => $flightStart->copy()->subHour(),
            'occupied_until' => $flightStart->copy()->addHour(),
        ]);

        $ordered = (new EarliestAvailableGateSelectionStrategy)->getOrderedGates($flightStart);

        $this->assertSame($freedRecently->id, $ordered->first()->id);
        // A gate still occupied past the flight start sorts last.
        $this->assertContains($stillOccupied->id, $ordered->slice(1)->pluck('id')->all());
    }
}
