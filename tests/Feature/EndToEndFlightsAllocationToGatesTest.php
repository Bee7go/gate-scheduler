<?php

namespace Tests\Feature;

use App\Models\Flight;
use App\Models\Gate;
use App\Models\GateAllocation;
use App\Models\GateUnavailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;


class EndToEndFlightsAllocationToGatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_end_to_end_fetch_store_and_allocate_flights_via_artisan_commands(): void
    {
        // create 3 gates, one is in maintenance
        $g1 = Gate::factory()->create();

        GateUnavailability::factory()
            ->withinPeriod('2025-01-10 00:00:00', '2025-01-11 23:59:59', $g1->id)
            ->create();

        Gate::factory()->count(2)->create();

        // mocked http responses
        Http::fake([
            'https://auth.opensky.test/oauth/token' => Http::response(['access_token' => 'fake-token'], 200),

            'https://opensky-network.org/api/flights/arrival*' => Http::response([
                [
                    'icao24' => 'abc123',
                    'firstSeen' => 1736510400, // 2025-01-10 12:00:00
                    'lastSeen' => 1736512200,
                ],
            ], 200),

            'https://opensky-network.org/api/flights/departure*' => Http::response([
                [
                    'icao24' => 'def456',
                    'firstSeen' => 1736511000, // 2025-01-10 12:10:00
                    'lastSeen' => 1736512800,
                ],
            ], 200),
        ]);

        // fetch flights via artisan command
        $this->artisan('app:sync-flights')->assertExitCode(0);

        // check flights persisted
        $this->assertSame(2, Flight::query()->count());

        // allocations persisted
        $allocations = GateAllocation::query()->get();
        $this->assertCount(2, $allocations);

        // check gate in maintenance was not used
        $this->assertFalse($allocations->contains(fn ($a) => $a->gate_id === $g1->id));

        // check flights were allocated on different gates
        $this->assertNotSame($allocations[0]->gate_id, $allocations[1]->gate_id);
    }
}
