<?php

/**
 * Manual Test: DRT Phase 4 Queue Job Wrapper + Scheduler
 * Generated: 2026-04-03
 * Purpose: Verify FetchDrtAlertsJob command wrapper behavior, dispatcher uniqueness
 * semantics, and scheduler registration cadence/overlap settings.
 */

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Jobs\FetchDrtAlertsJob;
use App\Services\ScheduledFetchJobDispatcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

if (app()->environment('production')) {
    fwrite(STDERR, "Error: Cannot run manual tests in production.\n");
    exit(1);
}

$testRunId = 'drt_phase4_'.now()->format('Y_m_d_His');
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

if (! is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

function logInfo(string $message, array $context = []): void
{
    Log::channel('manual_test')->info($message, $context);
    echo "[INFO] {$message}\n";
}

function logError(string $message, array $context = []): void
{
    Log::channel('manual_test')->error($message, $context);
    echo "[ERROR] {$message}\n";
}

function assertTrue(bool $condition, string $message, array $context = []): void
{
    if (! $condition) {
        throw new RuntimeException('Assertion failed: '.$message.' '.json_encode($context, JSON_THROW_ON_ERROR));
    }
}

function queuedJobCount(string $jobClass): int
{
    $escapedDisplayName = str_replace('\\', '\\\\', $jobClass);
    $needle = "\"displayName\":\"{$escapedDisplayName}\"";

    return DB::table('jobs')
        ->where('payload', 'like', "%{$needle}%")
        ->count();
}

function deleteQueuedJobs(string $jobClass): void
{
    $escapedDisplayName = str_replace('\\', '\\\\', $jobClass);
    $needle = "\"displayName\":\"{$escapedDisplayName}\"";

    DB::table('jobs')
        ->where('payload', 'like', "%{$needle}%")
        ->delete();
}

try {
    DB::beginTransaction();

    config([
        'cache.default' => 'array',
        'queue.default' => 'database',
        'queue.unique_lock_store' => 'array',
    ]);

    app()->forgetInstance('cache');
    app()->forgetInstance('cache.store');
    app()->forgetInstance('queue');

    Cache::flush();
    Cache::store((string) config('queue.unique_lock_store'))->flush();
    deleteQueuedJobs(FetchDrtAlertsJob::class);

    logInfo("=== Starting Manual Test: {$testRunId} ===");
    logInfo('Phase 1: Verify job wrapper command behavior');

    Artisan::shouldReceive('call')
        ->once()
        ->with('drt:fetch-alerts')
        ->andReturn(0);

    (new FetchDrtAlertsJob)->handle();

    Artisan::shouldReceive('call')
        ->once()
        ->with('drt:fetch-alerts')
        ->andReturn(1);

    try {
        (new FetchDrtAlertsJob)->handle();
        assertTrue(false, 'job throws when command returns non-zero');
    } catch (RuntimeException $e) {
        assertTrue(
            str_contains($e->getMessage(), 'drt:fetch-alerts failed with exit code 1'),
            'job failure exception includes exit code',
            ['message' => $e->getMessage()]
        );
    }

    $job = new FetchDrtAlertsJob;
    $middleware = $job->middleware();

    assertTrue($job->tries === 3, 'job tries equals 3');
    assertTrue($job->backoff === 30, 'job backoff equals 30');
    assertTrue($job->timeout === 120, 'job timeout equals 120');
    assertTrue($job->uniqueFor === 600, 'job uniqueFor equals 600');
    assertTrue($job->uniqueId() === 'fetch-drt-alerts', 'job uniqueId matches expected');
    assertTrue(count($middleware) === 1, 'job middleware contains one entry');
    assertTrue(($middleware[0]->key ?? null) === 'fetch-drt-alerts', 'middleware key matches expected');
    assertTrue(($middleware[0]->releaseAfter ?? null) === 30, 'middleware releaseAfter equals 30');
    assertTrue(($middleware[0]->expiresAfter ?? null) === 600, 'middleware expiresAfter equals 600');

    logInfo('Phase 2: Verify dispatcher uniqueness and idempotent enqueue behavior');

    $dispatcher = app(ScheduledFetchJobDispatcher::class);
    $firstDispatch = $dispatcher->dispatchDrtAlerts();
    $secondDispatch = $dispatcher->dispatchDrtAlerts();

    assertTrue($firstDispatch === true, 'first dispatchDrtAlerts call enqueues job');
    assertTrue($secondDispatch === false, 'second dispatchDrtAlerts call is skipped');

    logInfo('Dispatcher idempotency verified from return values', [
        'first_dispatch' => $firstDispatch,
        'second_dispatch' => $secondDispatch,
        'observed_jobs_table_count' => queuedJobCount(FetchDrtAlertsJob::class),
    ]);

    logInfo('Phase 3: Verify scheduler cadence and overlap protection registration');

    $schedule = app(Schedule::class);
    $event = collect($schedule->events())->first(function ($event) {
        return is_string($event->description) && $event->description === 'drt:fetch-alerts';
    });

    assertTrue($event !== null, 'schedule contains drt:fetch-alerts event');
    assertTrue($event->expression === '*/5 * * * *', 'drt schedule runs every five minutes', [
        'expression' => $event->expression,
    ]);
    assertTrue($event->withoutOverlapping === true, 'drt schedule uses withoutOverlapping');
    assertTrue($event->expiresAt === 10, 'drt schedule overlap lock expiry is 10 minutes', [
        'expires_at' => $event->expiresAt,
    ]);

    logInfo('Manual verification assertions passed');
    logInfo('Phase 4: Cleanup');

    DB::rollBack();
    Mockery::close();

    logInfo('Database transaction rolled back');
    logInfo("=== Manual Test Completed: {$testRunId} ===");

    echo "\n✓ Manual verification passed. Log: {$logFile}\n";
    exit(0);
} catch (Throwable $e) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    Mockery::close();

    logError('Manual verification failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    echo "\n✗ Manual verification failed. Log: {$logFile}\n";
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
