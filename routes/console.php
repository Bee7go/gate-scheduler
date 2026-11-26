<?php

use App\Jobs\GenerateGateAllocationReportJob;
use App\Jobs\SyncFlightsJob;
use App\Models\AccessToken;
use App\Models\IdempotencyKey;
use App\Models\SyncRun;
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

// Data retention (documented in docs/DEPLOYMENT.md): idempotency records
// hold encrypted response bodies and drop when their replay window closes;
// expired bearer tokens leave after a short grace period; sync-run history
// is kept for 90 days by default.
Schedule::command('model:prune', ['--model' => [AccessToken::class, IdempotencyKey::class, SyncRun::class]])
    ->dailyAt('03:30')
    ->withoutOverlapping();
