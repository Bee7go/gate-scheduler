<?php

namespace App\Services\Flights;

use App\Services\GateAllocation\GateAllocatorService;
use Illuminate\Support\Facades\Log;

class FlightSyncService
{
    public function __construct(
        private readonly OpenSkyService $openSkyService,
        private readonly GateAllocatorService $gateAllocator
    ) {}

    public function sync(): array
    {
        $airport = config('services.opensky.airport_icao');

        $summary = [
            'arrivals_fetched' => 0,
            'departures_fetched' => 0,
            'allocation' => [],
        ];

        // fetch arrivals
        $arrivals = $this->openSkyService->fetchFlights($airport, 'arrival') ?? [];
        $summary['arrivals_fetched'] = count($arrivals);

        $this->openSkyService->storeFlights($arrivals, $airport, 'arrival');

        // fetch departures
        $departures = $this->openSkyService->fetchFlights($airport, 'departure') ?? [];
        $summary['departures_fetched'] = count($departures);

        $this->openSkyService->storeFlights($departures, $airport, 'departure');

        // allocate gates
        $summary['allocation'] = $this->gateAllocator->assignUnallocatedFlights();

        Log::info('flight.sync.completed', $summary);

        return $summary;
    }
}
