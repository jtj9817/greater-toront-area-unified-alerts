<?php

/**
 * Manual Test: UI Design Revamp - Phase 2 Global Layout Implementation
 * Generated: 2026-03-04
 * Purpose:
 * - Verify Phase 2 global layout deliverables for Prototype Two.
 * - Confirm prototype-style sidebar, header, and footer integration.
 * - Confirm GTA Alerts wrapper and scoped theme root remain intact.
 * - Confirm floating refresh action exists and preserves state/scroll.
 * - Optionally run targeted frontend command gates.
 *
 * Usage:
 *   php tests/manual/verify_design_revamp_phase_2_global_layout_implementation.php
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

$testRunId = 'design_revamp_phase_2_global_layout_implementation_'.Carbon::now()->format('Y_m_d_His');
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
    logInfo('=== Starting Manual Test: UI Design Revamp Phase 2 Global Layout Implementation ===');
    logInfo('Boot context', [
        'app_env' => app()->environment(),
        'run_command_gates' => $shouldRunCommandGates,
    ]);

    logInfo('Phase 1: Verify required Phase 2 files are present');

    readFileContents('resources/js/features/gta-alerts/App.tsx');
    readFileContents('resources/js/features/gta-alerts/components/Sidebar.tsx');
    readFileContents('resources/js/features/gta-alerts/components/Footer.tsx');

    logInfo('Phase 2: Verify App shell wrapper, header, and refresh behavior');

    $appTsx = readFileContents('resources/js/features/gta-alerts/App.tsx');

    foreach ([
        'className="gta-alerts-theme relative flex h-screen w-full overflow-hidden bg-background-dark font-sans text-white"',
        'className="z-50 flex-none border-b border-[#333333] bg-black"',
        'placeholder="Search alerts, streets, or categories..."',
        'aria-label="Open notification center"',
        'aria-label="Open settings"',
        '<Icon name="person"',
        '<Footer />',
        '<BottomNav',
        "currentView === 'feed' && (",
        'aria-label="Refresh feed"',
        "only: ['alerts', 'filters', 'latestFeedUpdatedAt']",
        'preserveState: true',
        'preserveScroll: true',
    ] as $needle) {
        assertContainsText($needle, $appTsx, "App.tsx contains expected Phase 2 artifact: {$needle}");
    }

    logInfo('Phase 3: Verify sidebar styling and behavior retention');

    $sidebarTsx = readFileContents('resources/js/features/gta-alerts/components/Sidebar.tsx');

    foreach ([
        'const navItems = [',
        "{ id: 'feed', name: 'Feed', icon: 'feed' }",
        'bg-black',
        'border-[#333333]',
        'const mobileTranslate = isMobileOpen',
        'onToggleCollapse',
        'onCloseMobile',
        'currentView === item.id',
        "title={isCollapsed ? 'Expand Sidebar' : 'Collapse Sidebar'}",
    ] as $needle) {
        assertContainsText($needle, $sidebarTsx, "Sidebar.tsx contains expected Phase 2 artifact: {$needle}");
    }

    logInfo('Phase 4: Verify footer content and coexistence intent');

    $footerTsx = readFileContents('resources/js/features/gta-alerts/components/Footer.tsx');
    foreach ([
        'Temp: 24 C | Humidity: 65% | Wind: 15km/h W',
        'Incident Archives',
        'Privacy Policy',
        'System Status',
        'md:flex',
        'hidden h-12',
    ] as $needle) {
        assertContainsText($needle, $footerTsx, "Footer.tsx contains expected artifact: {$needle}");
    }

    if ($shouldRunCommandGates) {
        logInfo('Phase 5: Execute targeted frontend command gates');

        $formatResult = runCommand(
            'CI=true pnpm exec prettier --check resources/js/features/gta-alerts/App.tsx resources/js/features/gta-alerts/App.test.tsx resources/js/features/gta-alerts/components/Sidebar.tsx resources/js/features/gta-alerts/components/Footer.tsx',
            'phase 2 prettier check'
        );
        assertTrue(
            $formatResult['exit_code'] === 0,
            'phase 2 prettier check passes',
            ['exit_code' => $formatResult['exit_code']]
        );

        $testResult = runCommand(
            'CI=true pnpm run test -- resources/js/features/gta-alerts/App.test.tsx resources/js/features/gta-alerts/App.url-state.test.tsx resources/js/features/gta-alerts/components/FeedView.test.tsx',
            'phase 2 targeted vitest suite'
        );
        assertTrue(
            $testResult['exit_code'] === 0,
            'phase 2 targeted vitest suite passes',
            ['exit_code' => $testResult['exit_code']]
        );
    } else {
        logInfo('Phase 5: Command gates skipped via RUN_COMMAND_GATES=0');
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
