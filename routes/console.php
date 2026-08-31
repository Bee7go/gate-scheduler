<?php

use App\Jobs\GenerateGateAllocationReportJob;
use App\Jobs\SyncFlightsJob;
use Illuminate\Support\Facades\Schedule;

Schedule::job(new SyncFlightsJob)
    ->everyTwoMinutes()
    ->withoutOverlapping(10);

Schedule::job(new GenerateGateAllocationReportJob)
    ->everyThreeMinutes()
    ->withoutOverlapping(10);
