<?php

/**
 * Manual Test: UI Design Revamp - Phase 5 Documentation & Track Closeout
 * Generated: 2026-03-05
 *
 * Purpose:
 * - Verify the design revamp track has been fully closed out after manual
 *   verification completion.
 * - Confirm the archived track artifacts, registry state, and ticket updates
 *   all point to the final archived location.
 *
 * Usage:
 *   php tests/manual/verify_design_revamp_phase_5_documentation_closeout.php
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
    fwrite(STDERR, "Error: Do not run manual tests as root. Use `./vendor/bin/sail php ...`.\n");
    fwrite(STDERR, "If absolutely needed, set ALLOW_ROOT_MANUAL_TESTS=1.\n");
    exit(1);
}

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing.\n");
}

umask(002);

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

$testRunId = 'design_revamp_phase_5_documentation_closeout_'.Carbon::now()->format('Y_m_d_His');
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
    logInfo('=== Starting Manual Test: UI Design Revamp Phase 5 Documentation & Track Closeout ===');

    logInfo('Phase 1: Verify the track is archived in the registry');

    $tracksDoc = readFileContents('conductor/tracks.md');
    assertTrue(
        ! str_contains($tracksDoc, '**Track: UI Design Revamp (Prototype Two)** (Phase 5 docs complete; archival blocked by pending manual verification tasks)'),
        'tracks registry no longer lists design revamp as an active blocked track'
    );
    assertContainsText(
        '**Track: UI Design Revamp (Prototype Two)**',
        $tracksDoc,
        'tracks registry still references the design revamp track'
    );
    assertContainsText(
        './archive/design_revamp_20260303/',
        $tracksDoc,
        'tracks registry links to the archived design revamp track'
    );

    logInfo('Phase 2: Verify archived track artifacts');

    foreach ([
        'conductor/archive/design_revamp_20260303/index.md',
        'conductor/archive/design_revamp_20260303/plan.md',
        'conductor/archive/design_revamp_20260303/spec.md',
        'conductor/archive/design_revamp_20260303/metadata.json',
        'conductor/archive/design_revamp_20260303/phase_5_closeout_report_20260305.md',
    ] as $relativePath) {
        readFileContents($relativePath);
    }

    $metadata = readFileContents('conductor/archive/design_revamp_20260303/metadata.json');
    foreach ([
        '"status": "archived"',
        '"archived_at": "2026-03-05T00:00:00Z"',
    ] as $needle) {
        assertContainsText($needle, $metadata, "Archived metadata contains expected value: {$needle}");
    }

    $archivedPlan = readFileContents('conductor/archive/design_revamp_20260303/plan.md');
    foreach ([
        "[x] Task: Conductor - User Manual Verification 'Phase 2: Global Layout Implementation'",
        "[x] Task: Conductor - User Manual Verification 'Phase 4: Testing & Verification'",
        "Task: Conductor - User Manual Verification 'Phase 5: Final Comprehensive Documentation & Track Closeout'",
        '[x] Sub-task: Update `conductor/tracks.md` registry status and move the track to archive when all gates and documentation requirements are complete.',
    ] as $needle) {
        assertContainsText($needle, $archivedPlan, "Archived plan reflects completed closeout state: {$needle}");
    }

    $closeoutReport = readFileContents('conductor/archive/design_revamp_20260303/phase_5_closeout_report_20260305.md');
    foreach ([
        'Manual verification blockers were closed on 2026-03-05.',
        'Track archival completed.',
        'conductor/archive/design_revamp_20260303/plan.md',
    ] as $needle) {
        assertContainsText($needle, $closeoutReport, "Closeout report contains expected archived-state note: {$needle}");
    }

    logInfo('Phase 3: Verify ticket and changelog follow-through');

    $feed018 = readFileContents('docs/tickets/FEED-018-design-revamp-review-findings.md');
    foreach ([
        'Status: Closed',
        'Phase 2, Phase 4, and Phase 5 manual verification tasks were executed and documented.',
        'conductor/archive/design_revamp_20260303/plan.md',
    ] as $needle) {
        assertContainsText($needle, $feed018, "FEED-018 documents final resolution state: {$needle}");
    }

    $changelog = readFileContents('docs/CHANGELOG.md');
    foreach ([
        'FEED-018 Design Revamp Review Closure',
        'verify_design_revamp_phase_4_testing_verification.php',
        'verify_design_revamp_phase_5_documentation_closeout.php',
        'conductor/archive/design_revamp_20260303/plan.md',
    ] as $needle) {
        assertContainsText($needle, $changelog, "Changelog records the design revamp closeout update: {$needle}");
    }

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
