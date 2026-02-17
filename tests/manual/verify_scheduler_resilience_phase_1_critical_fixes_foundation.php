<?php

/**
 * Manual Test: Scheduler Resilience - Phase 1 Critical Fixes & Foundation
 * Generated: 2026-02-17
 *
 * Purpose:
 * - Verify scheduled fetch commands use short withoutOverlapping expiries (minutes, not 24h defaults)
 * - Verify queue depth monitor is scheduled
 *
 * Run (Sail):
 * - ./vendor/bin/sail php tests/manual/verify_scheduler_resilience_phase_1_critical_fixes_foundation.php
 */

require __DIR__.'/../../vendor/autoload.php';

// Default manual verification runs to testing so Laravel loads `.env.testing`.
// Preserve an explicitly provided APP_ENV value if the caller set one.
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}

// Some manual test environments do not provide APP_KEY in `.env.testing`.
// Use a deterministic testing-only fallback so app boot does not fail.
$manualTestEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null);
if ($manualTestEnv === 'testing' && (getenv('APP_KEY') === false || getenv('APP_KEY') === '')) {
    $fallbackAppKey = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    putenv("APP_KEY={$fallbackAppKey}");
    $_ENV['APP_KEY'] = $fallbackAppKey;
    $_SERVER['APP_KEY'] = $fallbackAppKey;
}

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution.
if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALLOW_ROOT_MANUAL_TESTS') !== '1') {
    fwrite(STDERR, "Error: Do not run manual tests as root. Use `./vendor/bin/sail shell` (or `./vendor/bin/sail php ...`).\n");
    fwrite(STDERR, "If you really need root, re-run with ALLOW_ROOT_MANUAL_TESTS=1 (not recommended).\n");
    exit(1);
}

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing.\n");
}

umask(002);

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;

$testRunId = 'scheduler_resilience_phase_1_critical_fixes_foundation_'.Carbon::now()->format('Y_m_d_His');
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");
$logDir = dirname($logFile);

if (! is_dir($logDir) && ! mkdir($logDir, 0775, true) && ! is_dir($logDir)) {
    fwrite(STDERR, "Error: Failed to create log directory: {$logDir}\n");
    exit(1);
}

if (! file_exists($logFile) && @touch($logFile) === false) {
    fwrite(STDERR, "Error: Failed to create log file: {$logFile}\n");
    exit(1);
}

@chmod($logFile, 0664);

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

function logInfo(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->info($msg, $ctx);
    $suffix = $ctx === [] ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES);
    echo "[INFO] {$msg}{$suffix}\n";
}

function logError(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->error($msg, $ctx);
    $suffix = $ctx === [] ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES);
    echo "[ERROR] {$msg}{$suffix}\n";
}

function assertTrue(bool $condition, string $label, array $ctx = []): void
{
    if (! $condition) {
        $message = "Assertion failed: {$label}.";
        logError($message, $ctx);
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

try {
    logInfo('=== Starting Manual Test: Scheduler Resilience Phase 1 ===', [
        'app_env' => app()->environment(),
    ]);

    $schedule = app(Schedule::class);
    $events = collect($schedule->events());

    $expectedFetchCommands = [
        'fire:fetch-incidents' => 10,
        'police:fetch-calls' => 10,
        'transit:fetch-alerts' => 10,
        'go-transit:fetch-alerts' => 10,
    ];

    foreach ($expectedFetchCommands as $eventName => $expiresAtMinutes) {
        $event = $events->first(function ($event) use ($eventName) {
            return is_string($event->description) && $event->description === $eventName;
        });

        assertTrue($event !== null, "scheduled event exists for {$eventName}");
        assertTrue((bool) $event->withoutOverlapping, "{$eventName} has withoutOverlapping enabled");
        assertTrue((int) $event->expiresAt === $expiresAtMinutes, "{$eventName} expiresAt is {$expiresAtMinutes} minutes", [
            'actual_expiresAt' => $event->expiresAt,
        ]);
    }

    $queueDepthEvent = $events->first(function ($event) {
        return is_string($event->description) && $event->description === 'monitor:queue-depth';
    });

    assertTrue($queueDepthEvent !== null, 'queue depth monitor scheduled');
    assertTrue((bool) $queueDepthEvent->withoutOverlapping, 'queue depth monitor has withoutOverlapping enabled');

    logInfo('Manual verification reminders (non-destructive)', [
        'schedule_list' => './vendor/bin/sail artisan schedule:list',
        'scheduler_heartbeat' => './vendor/bin/sail artisan scheduler:run-and-log',
        'logs' => 'tail -f storage/logs/laravel.log',
    ]);

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    echo "\nFull logs at: {$logFileRelative}\n";
}
