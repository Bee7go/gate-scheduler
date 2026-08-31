<?php

namespace App\Jobs;

use App\Services\GateAllocation\GateAllocationReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class GenerateGateAllocationReportJob implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The maximum number of seconds the job may run before it is terminated.
     */
    public int $timeout = 120;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120];

    public function handle(GateAllocationReportService $service): void
    {
        $report = $service->generate();

        Log::info('queue.job.gate_allocation_report.completed', [
            'report' => $report,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('queue.job.gate_allocation_report.failed', [
            'message' => $exception?->getMessage(),
        ]);
    }
}
