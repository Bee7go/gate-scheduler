<?php

namespace App\Console\Commands;

use App\Services\GateAllocatorService;
use Illuminate\Console\Command;

class AllocateGates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:allocate-gates';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $allocator = app(GateAllocatorService::class);
        $allocator->assignUnallocatedFlights();

        $this->info('Gate allocation completed.');
    }
}
