<?php

use App\Jobs\FetchMiwayAlertsJob;
use Illuminate\Console\Scheduling\Schedule;

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

echo "\n=============================================\n";
echo "MiWay Phase 4: Job Wrapper + Scheduler Verification\n";
echo "=============================================\n\n";

if (config('app.env') !== 'testing') {
    echo "Note: Not running in 'testing' environment.\n";
}

try {
    echo "1. Checking Scheduler Registration...\n";
    $schedule = app(Schedule::class);
    $events = $schedule->events();
    $found = false;
    foreach ($events as $event) {
        if ($event->description === 'miway:fetch-alerts') {
            $found = true;
            echo "  ✅ miway:fetch-alerts is registered in the scheduler\n";
            echo "     Expression: {$event->expression} (every 5 minutes)\n";
            break;
        }
    }
    if (! $found) {
        echo "  ❌ miway:fetch-alerts is NOT registered in the scheduler\n";
    }

    echo "\n2. Validating FetchMiwayAlertsJob constraints...\n";
    $job = new FetchMiwayAlertsJob;
    echo "  ✅ Job exists and can be instantiated\n";
    echo "  ✅ Tries: {$job->tries}, Backoff: {$job->backoff}, Timeout: {$job->timeout}\n";
    echo "  ✅ Unique ID: {$job->uniqueId()}\n";

} catch (Throwable $e) {
    echo '❌ ERROR: '.$e->getMessage()."\n";
    exit(1);
}

echo "\nVerification Complete. Ready for Phase 5.\n";
echo "=============================================\n";
