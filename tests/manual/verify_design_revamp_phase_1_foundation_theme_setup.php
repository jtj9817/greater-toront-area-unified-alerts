<?php

/**
 * Manual Test: UI Design Revamp - Phase 1 Foundation & Theme Setup
 * Generated: 2026-03-04
 * Purpose:
 * - Verify Phase 1 theme foundation deliverables for Prototype Two.
 * - Confirm Public Sans font loading in the app shell.
 * - Confirm prototype tokens/utilities are present in app.css.
 * - Confirm GTA Alerts theme overrides are scoped via `.gta-alerts-theme`.
 * - Optionally run command gates (format/types).
 *
 * Usage:
 *   php tests/manual/verify_design_revamp_phase_1_foundation_theme_setup.php
 *
 * Optional env vars:
 *   RUN_COMMAND_GATES=0  Skip format/types command gates.
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

$testRunId = 'design_revamp_phase_1_foundation_theme_setup_'.Carbon::now()->format('Y_m_d_His');
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
    logInfo('=== Starting Manual Test: UI Design Revamp Phase 1 Foundation & Theme Setup ===');
    logInfo('Boot context', [
        'app_env' => app()->environment(),
        'run_command_gates' => $shouldRunCommandGates,
    ]);

    logInfo('Phase 1: Verify required files and shell font wiring');

    $appBlade = readFileContents('resources/views/app.blade.php');
    assertContainsText(
        'family=Public+Sans:wght@300;400;500;600;700;800;900&display=swap',
        $appBlade,
        'Public Sans font link is loaded in app shell'
    );
    assertContainsText(
        'family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap',
        $appBlade,
        'Material Symbols link remains loaded'
    );

    logInfo('Phase 2: Verify theme token and utility integration');

    $appCss = readFileContents('resources/css/app.css');
    foreach ([
        '--color-brand-dark: #1a1a1a;',
        '--color-warning: #ffff00;',
        '--color-critical: #cc0000;',
        '--color-panel-light: #ffffff;',
        '.gta-alerts-theme {',
        '--primary: #ff7f00;',
        '--primary-foreground: #ffffff;',
        '--font-sans:',
        '--font-display:',
        '.custom-scrollbar::-webkit-scrollbar {',
        '.scrollbar-hide::-webkit-scrollbar {',
        '.brutalist-border {',
        '.panel-shadow {',
        '.incident-table th {',
        '.incident-table td {',
        '.expandable-row {',
        'tr.active-row {',
    ] as $needle) {
        assertContainsText($needle, $appCss, "app.css contains expected artifact: {$needle}");
    }

    logInfo('Phase 3: Verify GTA Alerts theme scoping root remains present');

    $gtaApp = readFileContents('resources/js/features/gta-alerts/App.tsx');
    assertContainsText(
        'className="gta-alerts-theme relative flex h-screen w-full overflow-hidden bg-background-dark font-display text-white"',
        $gtaApp,
        'GTA Alerts wrapper applies scoped theme root class'
    );

    if ($shouldRunCommandGates) {
        logInfo('Phase 4: Execute command gates for formatting and types');

        $formatResult = runCommand('CI=true pnpm run format:check', 'format check');
        assertTrue(
            $formatResult['exit_code'] === 0,
            'format check passes',
            ['exit_code' => $formatResult['exit_code']]
        );

        $typesResult = runCommand('CI=true pnpm run types', 'type check');
        assertTrue(
            $typesResult['exit_code'] === 0,
            'type check passes',
            ['exit_code' => $typesResult['exit_code']]
        );
    } else {
        logInfo('Phase 4: Command gates skipped via RUN_COMMAND_GATES=0');
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
