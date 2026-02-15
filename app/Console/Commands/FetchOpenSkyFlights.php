<?php

namespace App\Console\Commands;

use App\Services\OpenSkyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class FetchOpenSkyFlights extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:fetch-open-sky-flights';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(OpenSkyService $openSkyService): void
    {
        $startedAt = microtime(true);

        $airport = config('services.opensky.airport_icao');

        $arrivalsFlights = $openSkyService->fetchFlights($airport, 'arrival') ?? [];
        $departuresFlights = $openSkyService->fetchFlights($airport, 'departure') ?? [];

        $openSkyService->storeFlights($arrivalsFlights, $airport, 'arrival');
        $openSkyService->storeFlights($departuresFlights, $airport, 'departure');

        $durationMs = (int)((microtime(true) - $startedAt) * 1000);
        $message = "OpenSky sync finished for airport $airport in duration={$durationMs}ms";

        $this->info($message);
        Log::info($message);
    }
}
