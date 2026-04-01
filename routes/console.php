<?php

use App\Jobs\GenerateGateAllocationReportJob;
use App\Jobs\SyncFlightsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::job(new SyncFlightsJob())
    ->everyTwoMinutes()
    ->withoutOverlapping(10);

Schedule::job(new GenerateGateAllocationReportJob())
    ->everyThreeMinutes()
    ->withoutOverlapping(10);
