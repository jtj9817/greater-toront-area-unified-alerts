<?php

use App\Jobs\GenerateDailyDigestJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// withoutOverlapping() expiry is in minutes; we use 10 to avoid 24-hour lockouts if the scheduler crashes.
Schedule::command('fire:fetch-incidents')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('police:fetch-calls')->everyTenMinutes()->withoutOverlapping(10);
Schedule::command('transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('go-transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::job(new GenerateDailyDigestJob)->dailyAt('00:10')->withoutOverlapping();
Schedule::command('notifications:prune')->daily()->withoutOverlapping();
Schedule::command('model:prune', [
    '--model' => [\App\Models\IncidentUpdate::class],
])->daily()->withoutOverlapping();

Schedule::call(function (): void {
    try {
        $depth = Queue::size();
        $threshold = 100;

        if ($depth > $threshold) {
            Log::error('Queue depth exceeded threshold', [
                'depth' => $depth,
                'threshold' => $threshold,
            ]);
        }
    } catch (\Throwable $e) {
        Log::error('Queue depth check failed', [
            'exception' => $e,
        ]);
    }
})
    ->name('monitor:queue-depth')
    ->everyFiveMinutes()
    ->withoutOverlapping(5);
