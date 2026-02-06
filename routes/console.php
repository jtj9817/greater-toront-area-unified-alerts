<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('fire:fetch-incidents')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('police:fetch-calls')->everyTenMinutes();
Schedule::command('transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('go-transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping();
