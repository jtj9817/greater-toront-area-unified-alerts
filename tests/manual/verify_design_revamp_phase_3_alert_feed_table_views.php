<?php

/**
 * Manual Test: UI Design Revamp - Phase 3 Alert Feed & Table Views
 * Generated: 2026-03-04
 * Purpose:
 * - Verify feed/table toggle alignment with prototype labels and behavior.
 * - Verify prototype-style table with expandable summary rows.
 * - Verify prototype-style feed cards with active/cleared visual states.
 * - Optionally run targeted frontend command gates.
 *
 * Usage:
 *   php tests/manual/verify_design_revamp_phase_3_alert_feed_table_views.php
 *
 * Optional env vars:
 *   RUN_COMMAND_GATES=0  Skip command gates.
 */

require __DIR__.'/../../vendor/autoload.php';

use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

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
$kernel = $app->make(ConsoleKernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALLOW_ROOT_MANUAL_TESTS') !== '1') {
    fwrite(STDERR, "Error: Do not run manual tests as root. Use `./vendor/bin/sail php ...`.\n");
    fwrite(STDERR, "If absolutely needed, set ALLOW_ROOT_MANUAL_TESTS=1.\n");
    exit(1);
}

umask(002);

$testRunId = 'design_revamp_phase_3_alert_feed_table_views_'.Carbon::now()->format('Y_m_d_His');
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

$runCommandGates = getenv('RUN_COMMAND_GATES');
$shouldRunCommandGates = $runCommandGates === false ? true : $runCommandGates !== '0';

$exitCode = 0;

try {
    logInfo('=== Starting Manual Test: UI Design Revamp Phase 3 Alert Feed & Table Views ===');
    logInfo('Boot context', [
        'app_env' => app()->environment(),
        'run_command_gates' => $shouldRunCommandGates,
    ]);

    logInfo('Phase 1: Verify required Phase 3 files are present');

    readFileContents('resources/js/features/gta-alerts/components/FeedView.tsx');
    readFileContents('resources/js/features/gta-alerts/components/AlertTableView.tsx');
    readFileContents('resources/js/features/gta-alerts/components/AlertCard.tsx');
    readFileContents('resources/js/features/gta-alerts/components/FeedView.test.tsx');
    readFileContents('resources/js/features/gta-alerts/components/AlertTableView.test.tsx');

    logInfo('Phase 2: Verify Feed/Table view toggle implementation in FeedView');

    $feedViewTsx = readFileContents('resources/js/features/gta-alerts/components/FeedView.tsx');

    foreach ([
        "const [viewMode, setViewMode] = useState<'feed' | 'table'>('feed');",
        'aria-label="Feed view"',
        'aria-label="Table view"',
        "setViewMode('feed')",
        "setViewMode('table')",
        'Feed',
        'Table',
        "viewMode === 'feed' ? (",
        '<AlertTableView',
    ] as $needle) {
        assertContainsText($needle, $feedViewTsx, "FeedView.tsx contains expected Phase 3 artifact: {$needle}");
    }

    logInfo('Phase 3: Verify expandable prototype-style table implementation');

    $tableViewTsx = readFileContents('resources/js/features/gta-alerts/components/AlertTableView.tsx');

    foreach ([
        'const [expandedRowId, setExpandedRowId] = useState<string | null>(null);',
        'incident-table',
        'expandable-row',
        'active-row',
        'Incident Summary',
        'Expand',
        'Collapse',
        'event.stopPropagation();',
        'View Details',
    ] as $needle) {
        assertContainsText($needle, $tableViewTsx, "AlertTableView.tsx contains expected Phase 3 artifact: {$needle}");
    }

    logInfo('Phase 4: Verify prototype-style feed card implementation and active/cleared treatment');

    $alertCardTsx = readFileContents('resources/js/features/gta-alerts/components/AlertCard.tsx');

    foreach ([
        'const isActive = alert.isActive;',
        'bg-panel-light',
        'panel-shadow',
        "{isActive ? 'Active' : 'Cleared'}",
        'Incident Summary',
        'Event #{eventReference}',
    ] as $needle) {
        assertContainsText($needle, $alertCardTsx, "AlertCard.tsx contains expected Phase 3 artifact: {$needle}");
    }

    logInfo('Phase 5: Verify tests were updated for phase 3 behavior');

    $feedViewTestTsx = readFileContents('resources/js/features/gta-alerts/components/FeedView.test.tsx');
    $tableViewTestTsx = readFileContents('resources/js/features/gta-alerts/components/AlertTableView.test.tsx');

    foreach ([
        'renders view mode toggle (Feed/Table)',
        "name: 'Table view'",
        "name: 'Feed view'",
    ] as $needle) {
        assertContainsText($needle, $feedViewTestTsx, "FeedView.test.tsx contains expected update: {$needle}");
    }

    foreach ([
        "describe('AlertTableView'",
        'expands and collapses summary rows from the expand affordance',
        'keeps incident selection for details on row click',
    ] as $needle) {
        assertContainsText($needle, $tableViewTestTsx, "AlertTableView.test.tsx contains expected update: {$needle}");
    }

    if ($shouldRunCommandGates) {
        logInfo('Phase 6: Execute targeted frontend command gates');

        $formatResult = runCommand(
            'CI=true pnpm exec prettier --check resources/js/features/gta-alerts/components/FeedView.tsx resources/js/features/gta-alerts/components/AlertTableView.tsx resources/js/features/gta-alerts/components/AlertCard.tsx resources/js/features/gta-alerts/components/FeedView.test.tsx resources/js/features/gta-alerts/components/AlertTableView.test.tsx resources/js/features/gta-alerts/components/AlertCard.test.tsx',
            'phase 3 prettier check'
        );
        assertTrue(
            $formatResult['exit_code'] === 0,
            'phase 3 prettier check passes',
            ['exit_code' => $formatResult['exit_code']]
        );

        $testResult = runCommand(
            'CI=true LARAVEL_BYPASS_ENV_CHECK=1 pnpm run test -- resources/js/features/gta-alerts/components/FeedView.test.tsx resources/js/features/gta-alerts/components/AlertTableView.test.tsx resources/js/features/gta-alerts/components/AlertCard.test.tsx',
            'phase 3 targeted vitest suite'
        );
        assertTrue(
            $testResult['exit_code'] === 0,
            'phase 3 targeted vitest suite passes',
            ['exit_code' => $testResult['exit_code']]
        );
    } else {
        logInfo('Phase 6: Command gates skipped via RUN_COMMAND_GATES=0');
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
