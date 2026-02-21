<?php

/**
 * Manual Test: FEED-001 - Phase 5 Documentation
 * Generated: 2026-02-21
 *
 * Purpose:
 * - Verify FEED-001 Phase 5 documentation deliverables are present:
 *   - feed query params (`status`, `source`, `q`, `since`, `cursor`) and examples
 *   - infinite scroll cursor semantics and reset/no-more-results behavior
 *   - MySQL FULLTEXT + SQLite fallback expectations
 *   - removal of live client-side filtering in GTA Alerts docs
 *
 * Run:
 * - APP_ENV=testing ./vendor/bin/sail php tests/manual/verify_feed_001_phase_5_documentation.php
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
    exit("Error: Manual tests must run with APP_ENV=testing.\n");
}

umask(002);

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

$testRunId = 'feed_001_phase_5_documentation_'.Carbon::now()->format('Y_m_d_His');
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

$exitCode = 0;

try {
    logInfo('=== Manual Test: FEED-001 Phase 5 Documentation ===');

    logInfo('Phase 1: Verify root README documentation updates');
    $rootReadme = readFileContents('README.md');
    assertContainsText('## Feed Query Parameters', $rootReadme, 'README contains feed query params section');
    assertContainsText('`status`', $rootReadme, 'README documents status param');
    assertContainsText('`source`', $rootReadme, 'README documents source param');
    assertContainsText('`q`', $rootReadme, 'README documents q param');
    assertContainsText('`since`', $rootReadme, 'README documents since param');
    assertContainsText('`cursor`', $rootReadme, 'README documents cursor param');
    assertContainsText('/api/feed?', $rootReadme, 'README includes feed API example');

    logInfo('Phase 2: Verify backend architecture docs');
    $unifiedAlertsDoc = readFileContents('docs/backend/unified-alerts-system.md');
    assertContainsText('## Feed Query Parameters', $unifiedAlertsDoc, 'unified alerts doc includes feed param contract');
    assertContainsText('## Cursor Semantics and Infinite Scroll Guarantees', $unifiedAlertsDoc, 'unified alerts doc includes cursor semantics');
    assertContainsText('next_cursor', $unifiedAlertsDoc, 'unified alerts doc includes no-more-results semantics');
    assertContainsText('## Search Performance: MySQL FULLTEXT + SQLite Fallback', $unifiedAlertsDoc, 'unified alerts doc includes FULLTEXT/fallback contract');
    assertContainsText('MATCH (...) AGAINST', $unifiedAlertsDoc, 'unified alerts doc references MySQL FULLTEXT query behavior');
    assertContainsText('LIKE', $unifiedAlertsDoc, 'unified alerts doc references sqlite compatibility fallback');

    $dtosDoc = readFileContents('docs/backend/dtos.md');
    assertContainsText('## UnifiedAlertsCursor', $dtosDoc, 'DTO docs include cursor value object');
    assertContainsText('source', $dtosDoc, 'DTO docs include source criteria');
    assertContainsText('sinceCutoff', $dtosDoc, 'DTO docs include since cutoff criteria');

    logInfo('Phase 3: Verify frontend documentation updates');
    $alertServiceDoc = readFileContents('docs/frontend/alert-service.md');
    assertContainsText('## Live Feed Filtering Contract', $alertServiceDoc, 'AlertService docs include live feed contract section');
    assertContainsText('server-authoritative', $alertServiceDoc, 'AlertService docs state server-authoritative filtering');
    assertContainsText('searchDomainAlerts()', $alertServiceDoc, 'AlertService docs clarify live feed does not use client-side search helper');
    assertContainsText('useInfiniteScroll', $alertServiceDoc, 'AlertService docs reference infinite scroll hook');

    logInfo('Phase 4: Verify registry and agent-context docs');
    $docsReadme = readFileContents('docs/README.md');
    assertContainsText('Server-Side Feed Filters + Infinite Scroll (FEED-001)', $docsReadme, 'docs/README status row marks FEED-001 as implemented');
    assertContainsText('backend/unified-alerts-system.md', $docsReadme, 'docs/README points FEED-001 to backend docs');

    $claudeDoc = readFileContents('CLAUDE.md');
    assertContainsText('verify_feed_001_phase_5_documentation.php', $claudeDoc, 'CLAUDE manual testing command includes FEED-001 Phase 5 script');
    assertContainsText('cursorPaginate', $claudeDoc, 'CLAUDE query flow references cursor pagination');
    assertContainsText('/api/feed', $claudeDoc, 'CLAUDE routing includes feed API endpoint');
    assertContainsText('Server-filtered feed with cursor-based infinite scroll', $claudeDoc, 'CLAUDE frontend view description reflects FEED-001 behavior');

    logInfo('Phase 5: Summary');
    logInfo('All FEED-001 Phase 5 documentation assertions passed.');
} catch (Throwable $exception) {
    $exitCode = 1;
    logError('Manual verification failed', [
        'message' => $exception->getMessage(),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
    ]);
} finally {
    logInfo("=== Manual Test Complete: {$testRunId} ===", [
        'log_file' => $logFileRelative,
        'status' => $exitCode === 0 ? 'passed' : 'failed',
    ]);

    echo "\n";
    echo $exitCode === 0
        ? "✓ FEED-001 Phase 5 documentation verification passed.\n"
        : "✗ FEED-001 Phase 5 documentation verification failed.\n";
    echo "Log: {$logFileRelative}\n";
}

exit($exitCode);
