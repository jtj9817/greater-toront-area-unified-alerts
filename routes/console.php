<?php

use App\Jobs\GenerateDailyDigestJob;
use App\Services\ScheduledFetchJobDispatcher;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// withoutOverlapping() expiry is in minutes; we use 10 to avoid 24-hour lockouts if the scheduler crashes.
Schedule::call(function (ScheduledFetchJobDispatcher $dispatcher): void {
    $dispatcher->dispatchFireIncidents();
})->name('fire:fetch-incidents')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::call(function (ScheduledFetchJobDispatcher $dispatcher): void {
    $dispatcher->dispatchPoliceCalls();
})->name('police:fetch-calls')->everyTenMinutes()->withoutOverlapping(10);
Schedule::call(function (ScheduledFetchJobDispatcher $dispatcher): void {
    $dispatcher->dispatchTransitAlerts();
})->name('transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::call(function (ScheduledFetchJobDispatcher $dispatcher): void {
    $dispatcher->dispatchGoTransitAlerts();
})->name('go-transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::call(function (ScheduledFetchJobDispatcher $dispatcher): void {
    $dispatcher->dispatchMiwayAlerts();
})->name('miway:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::call(function (ScheduledFetchJobDispatcher $dispatcher): void {
    $dispatcher->dispatchYrtAlerts();
})->name('yrt:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::call(function (ScheduledFetchJobDispatcher $dispatcher): void {
    $dispatcher->dispatchDrtAlerts();
})->name('drt:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::job(new GenerateDailyDigestJob)->dailyAt('00:10')->withoutOverlapping();
Schedule::command('notifications:prune')->daily()->withoutOverlapping();
Schedule::command('queue:prune-failed', ['--hours' => 168])->daily()->withoutOverlapping();
Schedule::command('model:prune', [
    '--model' => [\App\Models\IncidentUpdate::class],
])->daily()->withoutOverlapping();

Schedule::call(function (): void {
    try {
        $depth = Queue::size();
        $threshold = max(1, (int) config('queue.depth_alert_threshold', 100));
        $alertChannel = (string) config('logging.queue_depth_alert_channel', 'stack');

        if ($depth > $threshold) {
            Log::channel($alertChannel)->error('Queue depth exceeded threshold', [
                'depth' => $depth,
                'threshold' => $threshold,
                'queue_connection' => (string) config('queue.default'),
                'alert_channel' => $alertChannel,
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
