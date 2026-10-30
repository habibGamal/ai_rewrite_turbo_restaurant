<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('platform:sync')->everyMinute()->withoutOverlapping(5)->runInBackground();
Schedule::command('platform:heartbeat')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('platform:prune')->dailyAt('04:00');
