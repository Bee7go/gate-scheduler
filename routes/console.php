<?php

use App\Jobs\GenerateGateAllocationReportJob;
use App\Jobs\SyncFlightsJob;
use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new SyncFlightsJob)
    ->everyTwoMinutes()
    ->withoutOverlapping(10);

Schedule::job(new GenerateGateAllocationReportJob)
    ->everyThreeMinutes()
    ->withoutOverlapping(10);

Schedule::command('queue:prune-failed --hours=336')
    ->dailyAt('03:15')
    ->withoutOverlapping();

// Expired idempotency records hold encrypted response bodies; drop them
// as soon as their replay window closes.
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])
    ->dailyAt('03:30')
    ->withoutOverlapping();
