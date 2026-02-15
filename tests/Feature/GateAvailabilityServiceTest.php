<?php

namespace Tests\Feature;

use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateUnavailability;
use App\Services\GateAvailabilityService;
use DateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GateAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private GateAvailabilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GateAvailabilityService();
    }

    public function test_gate_is_not_available_during_unavailability_window(): void
    {
        // create a gate
        $gate = Gate::factory()->create();

        // gate is unavailable from 10.01.2025 until 11.01.2025
        GateUnavailability::factory()
            ->withinPeriod('2025-01-10 00:00:00', '2025-01-11 23:59:59', $gate->id)
            ->create();

        // check gate is not available between 10.01.2025 12:00 and 10.01.2025 13:30
        $from = new DateTime('2025-01-10 12:00:00');
        $until = new DateTime('2025-01-10 13:30:00');

        $isGateAvailable = $this->service->isGateAvailable($gate->id, $from, $until);
        $this->assertFalse($isGateAvailable, 'Gate should not be available during unavailability window');
    }

    public function test_gate_is_available_outside_unavailability_window(): void
    {
        // create a gate
        $gate = Gate::factory()->create();

        // gate is unavailable from 10.01.2025 until 11.01.2025
        GateUnavailability::factory()
            ->withinPeriod('2025-01-10 00:00:00', '2025-01-11 23:59:59', $gate->id)
            ->create();

        // check gate is available between 12.01.2025 12:00 and 12.01.2025 13:30
        $from = new DateTime('2025-01-12 12:00:00');
        $until = new DateTime('2025-01-12 13:30:00');

        $isGateAvailable = $this->service->isGateAvailable($gate->id, $from, $until);
        $this->assertTrue($isGateAvailable, "Gate should be available outside of unavailability window");
    }

    public function test_gate_is_not_available_when_allocation_conflict_exists(): void
    {
        $gate = Gate::factory()->create();

        // existing allocation: 10:00 -> 11:30
        GateAllocation::factory()->create([
            'gate_id' => $gate->id,
            'occupied_from' => now()->setTime(10, 0),
            'occupied_until' => now()->setTime(11, 30),
        ]);

        // new flight overlaps: 10:30 -> 11:00
        $from = now()->setTime(10, 30);
        $until = now()->setTime(11, 0);

        $available = $this->service->isGateAvailable(
            $gate->id,
            $from,
            $until
        );

        $this->assertFalse($available, "Gate should not be available because of existing allocation");
    }

    public function test_gate_is_available_when_no_allocations_exist(): void
    {
        $gate = Gate::factory()->create();

        $from = now();
        $until = now()->addMinutes(90);

        $available = $this->service->isGateAvailable(
            $gate->id,
            $from,
            $until
        );

        $this->assertTrue($available, "Gate should be available because no allocations exist");
    }

    public function test_gate_is_available_when_flight_starts_at_unavailability_end()
    {
        $gate = Gate::factory()->create();

        GateUnavailability::factory()
            ->withinPeriod('2026-02-14 12:00', '2026-02-14 14:00', $gate->id)
            ->create();

        $from = new DateTime('2026-02-14 14:00:00');
        $until = new DateTime('2026-02-14 15:30:00');

        $this->assertTrue($this->service->isGateAvailable($gate->id, $from, $until));
    }

    public function test_gate_is_not_available_when_flight_starts_at_unavailability_start()
    {
        $gate = Gate::factory()->create();

        GateUnavailability::factory()
            ->withinPeriod('2026-02-14 12:00', '2026-02-14 14:00', $gate->id)
            ->create();

        $from = new DateTime('2026-02-14 12:00:00');
        $until = new DateTime('2026-02-14 13:30:00');

        $this->assertFalse($this->service->isGateAvailable($gate->id, $from, $until));
    }

}
