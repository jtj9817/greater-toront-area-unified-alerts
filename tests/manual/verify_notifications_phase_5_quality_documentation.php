<?php

/**
 * Manual Test: Notifications - Phase 4 Quality Assurance & Documentation
 * Generated: 2026-02-11
 * Purpose: Verify quality/documentation deliverables:
 * - Integration test file exists with expected test count
 * - Backend docs exist with current schema/API details
 * - Maintenance docs include pruning policy
 * - docs/README.md references notification-system
 * - CLAUDE.md references notification system
 * - All notification feature tests pass
 */

require __DIR__.'/../../vendor/autoload.php';

if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}

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

if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALLOW_ROOT_MANUAL_TESTS') !== '1') {
    fwrite(STDERR, "Error: Do not run manual tests as root. Use `./vendor/bin/sail shell` (or `./vendor/bin/sail php ...`).\n");
    fwrite(STDERR, "If you really need root, re-run with ALLOW_ROOT_MANUAL_TESTS=1 (not recommended).\n");
    exit(1);
}

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing. Destructive test operations are disabled outside the testing environment and cannot be overridden.\n");
}

umask(002);

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

$testRunId = 'notifications_phase_5_quality_documentation_'.Carbon::now()->format('Y_m_d_His');
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

if (! @chmod($logFile, 0664)) {
    fwrite(STDERR, "Warning: Failed to set permissions on log file: {$logFile}\n");
}

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

function assertContainsText(string $needle, string $haystack, string $label): void
{
    assertTrue(str_contains($haystack, $needle), $label, ['needle' => $needle]);
}

function readFileContents(string $relativePath): string
{
    $absolutePath = base_path($relativePath);
    assertTrue(file_exists($absolutePath), "file exists: {$relativePath}");
    $contents = file_get_contents($absolutePath);
    assertTrue(is_string($contents), "file is readable: {$relativePath}");

    return $contents;
}

/**
 * @return array{exit_code: int|null, output: string}
 */
function runCommand(string $command, string $label): array
{
    logInfo("Running command: {$label}", ['command' => $command]);

    $process = new Process(['bash', '-lc', $command], base_path());
    $process->setTimeout(null);

    $output = '';
    $process->run(function (string $type, string $buffer) use (&$output): void {
        $output .= $buffer;
        echo $buffer;
    });

    $exitCode = $process->getExitCode();

    Log::channel('manual_test')->info("Command output: {$label}", [
        'exit_code' => $exitCode,
        'output' => $output,
    ]);

    return [
        'exit_code' => $exitCode,
        'output' => $output,
    ];
}

$exitCode = 0;

try {
    logInfo('=== Manual Test: Notifications Phase 4 Quality Assurance & Documentation ===');

    // Phase 1: Verify artifact existence and content

    logInfo('Phase 1: Verify deliverable artifacts exist with expected content');

    // Integration test file
    $integrationTestContents = readFileContents('tests/Feature/Notifications/NotificationSystemIntegrationTest.php');
    assertContainsText('matching alert flows through dispatch', $integrationTestContents, 'integration test contains matching alert flow test');
    assertContainsText('non-matching geofence alert does not dispatch', $integrationTestContents, 'integration test contains non-matching geofence test');
    assertContainsText('digest user receives daily digest entry', $integrationTestContents, 'integration test contains digest user test');

    $testCount = preg_match_all('/\btest\s*\(/', $integrationTestContents);
    assertTrue($testCount >= 3, 'integration test file contains at least 3 tests', ['count' => $testCount]);

    // Architecture documentation
    $notificationDocContents = readFileContents('docs/backend/notification-system.md');
    assertContainsText('## Overview', $notificationDocContents, 'notification docs contains Overview section');
    assertContainsText('## Architecture', $notificationDocContents, 'notification docs contains Architecture section');
    assertContainsText('## Database Schema', $notificationDocContents, 'notification docs contains Database Schema section');
    assertContainsText('## Matching Engine', $notificationDocContents, 'notification docs contains Matching Engine section');
    assertContainsText('## Delivery Pipeline', $notificationDocContents, 'notification docs contains Delivery Pipeline section');
    assertContainsText('## Daily Digest', $notificationDocContents, 'notification docs contains Daily Digest section');
    assertContainsText('## API Endpoints', $notificationDocContents, 'notification docs contains API Endpoints section');
    assertContainsText('## File Reference', $notificationDocContents, 'notification docs contains File Reference section');
    assertContainsText('saved_places', $notificationDocContents, 'notification docs reference saved_places schema');
    assertContainsText('subscriptions', $notificationDocContents, 'notification docs reference subscriptions');
    assertContainsText('/notifications/inbox/read-all', $notificationDocContents, 'notification docs include read-all inbox endpoint');
    assertContainsText('docs/backend/maintenance.md', $notificationDocContents, 'notification docs link pruning maintenance doc');

    $maintenanceDocContents = readFileContents('docs/backend/maintenance.md');
    assertContainsText('# Backend Maintenance', $maintenanceDocContents, 'maintenance doc contains title');
    assertContainsText('notifications:prune', $maintenanceDocContents, 'maintenance doc references prune command');
    assertContainsText('30 days', $maintenanceDocContents, 'maintenance doc includes retention period');

    // docs/README.md references
    $readmeContents = readFileContents('docs/README.md');
    assertContainsText('notification-system.md', $readmeContents, 'docs/README.md references notification-system.md');
    assertContainsText('maintenance.md', $readmeContents, 'docs/README.md references maintenance.md');
    assertContainsText('In-App Notifications', $readmeContents, 'docs/README.md contains In-App Notifications in status table');

    // CLAUDE.md references
    $claudeContents = readFileContents('CLAUDE.md');
    assertContainsText('In-App Notification System', $claudeContents, 'CLAUDE.md references In-App Notification System');
    assertContainsText('NotificationMatcher', $claudeContents, 'CLAUDE.md references NotificationMatcher');
    assertContainsText('notification-system.md', $claudeContents, 'CLAUDE.md references notification-system.md in architecture docs');

    // Phase 2: Run integration test suite

    logInfo('Phase 2: Execute notification system integration tests');

    $integrationTests = runCommand(
        'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array php artisan test --filter=NotificationSystemIntegrationTest',
        'Notification system integration test suite'
    );
    assertTrue(
        $integrationTests['exit_code'] === 0,
        'notification system integration tests pass',
        ['exit_code' => $integrationTests['exit_code']]
    );

    // Phase 3: Run all notification feature tests

    logInfo('Phase 3: Execute all notification feature test suites');

    $allNotificationTests = runCommand(
        'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array php artisan test tests/Feature/Notifications/',
        'All notification feature test suites'
    );
    assertTrue(
        $allNotificationTests['exit_code'] === 0,
        'all notification feature tests pass',
        ['exit_code' => $allNotificationTests['exit_code']]
    );

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
} finally {
    logInfo('Manual test log file', ['path' => $logFileRelative]);
}

exit($exitCode);
