<?php

namespace App\Console\Commands;

use App\Services\FlightSyncService;
use Illuminate\Console\Command;

class SyncFlights extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-flights';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle(FlightSyncService $flightSyncService): void
    {
        $result = $flightSyncService->sync();

        $this->info('Flights synced successfully');
        $this->line(json_encode($result));

    }
}
