<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('app:sync-flights')
    ->everyTwoMinutes()
    ->withoutOverlapping(10)
    ->runInBackground();

Schedule::command('app:gate-allocation-report')
    ->everyThreeMinutes() #everyFiveMinutes
    ->withoutOverlapping(10)
    ->runInBackground();
