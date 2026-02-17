<?php

namespace App\Console\Commands;

use App\Services\GateAllocation\GateAllocationReportService;
use Illuminate\Console\Command;

class GateAllocationReport extends Command
{
    protected $signature = 'app:gate-allocation-report';
    protected $description = 'Validate gate allocations and report statistics';

    public function handle(GateAllocationReportService $service): void
    {
        $report = $service->generate();

        $this->info('Gate allocation report generated');
        $this->line(json_encode($report));

    }
}
