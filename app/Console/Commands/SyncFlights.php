<?php

namespace App\Console\Commands;

use App\Jobs\SyncFlightsJob;
use App\Services\Flights\FlightSyncService;
use Illuminate\Console\Command;

class SyncFlights extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:sync-flights {--now : Run immediately in-process instead of dispatching to queue}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch flight synchronization to the queue (or run inline with --now)';

    /**
     * Execute the console command.
     */
    public function handle(FlightSyncService $flightSyncService): void
    {
        if (!$this->option('now')) {
            SyncFlightsJob::dispatch();

            $this->info('Flight sync job dispatched to queue');

            return;
        }

        $result = $flightSyncService->sync();

        $this->info('Flights synced successfully');
        $this->line(json_encode($result));
    }
}
