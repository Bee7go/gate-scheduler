<?php

namespace App\Console\Commands;

use App\Jobs\GenerateGateAllocationReportJob;
use App\Services\GateAllocation\GateAllocationReportService;
use Illuminate\Console\Command;

class GateAllocationReport extends Command
{
    protected $signature = 'app:gate-allocation-report {--now : Run immediately in-process instead of dispatching to queue}';
    protected $description = 'Dispatch gate allocation reporting to the queue (or run inline with --now)';

    public function handle(GateAllocationReportService $service): void
    {
        if (!$this->option('now')) {
            GenerateGateAllocationReportJob::dispatch();

            $this->info('Gate allocation report job dispatched to queue');

            return;
        }

        $report = $service->generate();

        $this->info('Gate allocation report generated');
        $this->line(json_encode($report));
    }
}
