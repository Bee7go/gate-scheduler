<?php

namespace Tests\Unit;

use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateUnavailability;
use App\Services\GateAllocatorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GateAllocatorServiceTest extends TestCase
{
    use RefreshDatabase;

    private GateAllocatorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(GateAllocatorService::class);
    }

    public function test_assigns_flight_to_first_available_gate_skipping_unavailable_gate(): void
    {
        // create 2 gates, one is under maintenance
        $g1 = Gate::factory()->create();
        $g2 = Gate::factory()->create();

        GateUnavailability::factory()
            ->withinPeriod('2025-01-10 00:00:00', '2025-01-11 23:59:59', $g1->id)
            ->create();

        $flight = Flight::factory()->create([
            'first_seen_at' => '2025-01-10 12:00:00',
        ]);

        // the allocation should be made successfully and uses g2
        $result = $this->service->assignUnallocatedFlights();

        $this->assertSame(1, $result['processed']);
        $this->assertSame(1, $result['assigned']);
        $this->assertSame(0, $result['unassigned']);

        $allocation = GateAllocation::where('flight_id', $flight->id)->first();
        $this->assertNotNull($allocation);
        $this->assertSame($g2->id, $allocation->gate_id);

        $from = $flight->first_seen_at;
        $until = (clone $from)->addMinutes((int)config('services.gates.occupation_minutes', 90));

        $this->assertSame($from->format('Y-m-d H:i:s'), $allocation->occupied_from->format('Y-m-d H:i:s'));
        $this->assertSame($until->format('Y-m-d H:i:s'), $allocation->occupied_until->format('Y-m-d H:i:s'));
    }

    public function test_two_overlapping_flights_get_different_gates(): void
    {
        // create 2 gates
        $a1 = Gate::factory()->create();
        $a2 = Gate::factory()->create();

        // create 2 flights that overlap
        $f1 = Flight::factory()->create(['first_seen_at' => '2025-01-10 12:00:00']);
        $f2 = Flight::factory()->create(['first_seen_at' => '2025-01-10 12:10:00']);

        $result = $this->service->assignUnallocatedFlights();

        // check both flights had been allocated on different gates
        $this->assertSame(2, $result['processed']);
        $this->assertSame(2, $result['assigned']);
        $this->assertSame(0, $result['unassigned']);

        $alloc1 = GateAllocation::where('flight_id', $f1->id)->first();
        $alloc2 = GateAllocation::where('flight_id', $f2->id)->first();

        $this->assertNotSame($alloc1->gate_id, $alloc2->gate_id);

        $this->assertContains($alloc1->gate_id, [$a1->id, $a2->id]);
        $this->assertContains($alloc2->gate_id, [$a1->id, $a2->id]);
    }

    public function test_insufficient_gates_leaves_flight_unassigned(): void
    {
        // only one gate available
        $a1 = Gate::factory()->create();

        // create two flights
        $f1 = Flight::factory()->create();
        $f2 = Flight::factory()->create();

        $result = $this->service->assignUnallocatedFlights();

        // two flights had been processed, but only one is allocated
        $this->assertSame(2, $result['processed']);
        $this->assertSame(1, $result['assigned']);
        $this->assertSame(1, $result['unassigned']);

        // check only one allocation is saved in db
        $this->assertSame(1, GateAllocation::query()->count());

        $this->assertNull(GateAllocation::where('flight_id', $f2->id)->first());

        $allocation = GateAllocation::where('flight_id', $f1->id)->first();
        $this->assertNotNull($allocation);
        $this->assertSame($a1->id, $allocation->gate_id);
    }

    public function test_assign_is_idempotent_running_twice_does_not_duplicate_allocations(): void
    {
        $gate = Gate::factory()->create();

        $flight = Flight::factory()->create();

        // the first run assigns the flight
        $resultFirstRun = $this->service->assignUnallocatedFlights();

        $this->assertSame(1, $resultFirstRun['processed']);
        $this->assertSame(1, $resultFirstRun['assigned']);
        $this->assertSame(0, $resultFirstRun['unassigned']);

        $this->assertSame(1, GateAllocation::query()->count());

        $allocation = GateAllocation::where('flight_id', $flight->id)->first();
        $this->assertNotNull($allocation);
        $this->assertSame($gate->id, $allocation->gate_id);

        // the second run should not process the flight again as it has already been allocated
        $resultSecondRun = $this->service->assignUnallocatedFlights();

        $this->assertSame(0, $resultSecondRun['processed']);
        $this->assertSame(0, $resultSecondRun['assigned']);
        $this->assertSame(0, $resultSecondRun['unassigned']);

        $this->assertSame(1, GateAllocation::query()->count());

        // the allocated gate should be the same as before
        $allocation2 = GateAllocation::where('flight_id', $flight->id)->first();
        $this->assertSame($allocation->id, $allocation2->id);
        $this->assertSame($allocation->gate_id, $allocation2->gate_id);
    }

    public function test_assign_unallocated_flights_respects_limit(): void
    {
        // create 3 gates
        Gate::factory()->create();
        Gate::factory()->create();
        Gate::factory()->create();

        // create 3 flights that need to be allocated
        Flight::factory()->create();
        Flight::factory()->create();
        Flight::factory()->create();

        // process flights, set limit 2
        $result = $this->service->assignUnallocatedFlights(limit: 2);

        // only 2 flights should be allocated
        $this->assertSame(2, $result['processed']);
        $this->assertSame(2, $result['assigned']);
        $this->assertSame(0, $result['unassigned']);

        $this->assertSame(2, GateAllocation::query()->count());
    }
}
