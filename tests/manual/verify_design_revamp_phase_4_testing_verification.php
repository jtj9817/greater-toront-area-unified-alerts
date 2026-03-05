<?php

/**
 * Manual Test: UI Design Revamp - Phase 4 Testing & Verification
 * Generated: 2026-03-05
 *
 * Purpose:
 * - Verify the executable Phase 4 verification assets exist and remain aligned:
 *   - `tests/e2e/design-revamp-phase-4.spec.ts`
 *   - `docs/runbooks/design-revamp-phase-4-verification.md`
 *   - Phase 4 findings tickets (`FEED-016`, `FEED-017`)
 *   - Playwright artifact evidence from the 2026-03-05 verification run
 * - Optionally execute the narrowest automated regression for the Phase 4 spec.
 *
 * Usage:
 *   php tests/manual/verify_design_revamp_phase_4_testing_verification.php
 *
 * Optional env vars:
 *   RUN_COMMAND_GATES=0  Skip running the targeted Vitest verification spec.
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

$testRunId = 'design_revamp_phase_4_testing_verification_'.Carbon::now()->format('Y_m_d_His');
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

$runCommandGates = getenv('RUN_COMMAND_GATES');
$shouldRunCommandGates = $runCommandGates === false ? true : $runCommandGates !== '0';

$exitCode = 0;

try {
    logInfo('=== Starting Manual Test: UI Design Revamp Phase 4 Testing & Verification ===');
    logInfo('Boot context', [
        'app_env' => app()->environment(),
        'run_command_gates' => $shouldRunCommandGates,
    ]);

    logInfo('Phase 1: Verify executable verification spec and runbook');

    $phase4Spec = readFileContents('tests/e2e/design-revamp-phase-4.spec.ts');
    foreach ([
        "id: 'shell-desktop-parity'",
        "id: 'feed-table-toggle-contract'",
        "id: 'table-interaction-contract'",
        "id: 'feed-card-status-parity'",
        "id: 'responsive-mobile-parity'",
        "engine: 'Vitest + jsdom'",
        "screen.getByRole('button', { name: 'Refresh feed' })",
    ] as $needle) {
        assertContainsText($needle, $phase4Spec, "Phase 4 spec contains expected scenario detail: {$needle}");
    }

    $phase4Runbook = readFileContents('docs/runbooks/design-revamp-phase-4-verification.md');
    foreach ([
        'http://localhost:8080/',
        'pnpm run format:check',
        'pnpm run lint:check',
        'pnpm run types',
        'pnpm run quality:check',
        './vendor/bin/sail artisan test',
        'artifacts/playwright/design-revamp-phase4-20260305-*',
    ] as $needle) {
        assertContainsText($needle, $phase4Runbook, "Phase 4 runbook contains expected instruction: {$needle}");
    }

    logInfo('Phase 2: Verify findings tickets and artifact evidence');

    $feed016 = readFileContents('docs/tickets/FEED-016-design-revamp-phase-4-verification-findings.md');
    foreach ([
        'status: Closed',
        'artifacts/playwright/design-revamp-phase4-desktop-home.png',
        'artifacts/playwright/design-revamp-phase4-mobile-cleared-view.png',
        'pnpm run lint:check` (pass)',
    ] as $needle) {
        assertContainsText($needle, $feed016, "FEED-016 contains expected verification evidence: {$needle}");
    }

    $feed017 = readFileContents('docs/tickets/FEED-017-design-revamp-phase-4-quality-gate-failures.md');
    foreach ([
        'status: Closed',
        'pnpm run format:check` -> PASS',
        'pnpm run quality:check` -> PASS',
        'composer test` -> PASS',
        'SceneIntelTimeline',
    ] as $needle) {
        assertContainsText($needle, $feed017, "FEED-017 contains expected quality-gate evidence: {$needle}");
    }

    foreach ([
        'artifacts/playwright/design-revamp-phase4-20260305-desktop-feed-1440.png',
        'artifacts/playwright/design-revamp-phase4-20260305-desktop-table.png',
        'artifacts/playwright/design-revamp-phase4-20260305-mobile-drawer-closed.png',
        'artifacts/playwright/design-revamp-phase4-20260305-quality-check.log',
        'artifacts/playwright/design-revamp-phase4-20260305-sail-artisan-test.log',
        'artifacts/playwright/design-revamp-phase4-20260305-gates-detailed.log',
    ] as $relativePath) {
        readFileContents($relativePath);
    }

    logInfo('Phase 3: Verify archived plan evidence pointers remain intact');

    $planDoc = readFileContents('conductor/archive/design_revamp_20260303/plan.md');
    foreach ([
        "Task: Conductor - User Manual Verification 'Phase 4: Testing & Verification'",
        'tests/e2e/design-revamp-phase-4.spec.ts',
        'docs/tickets/FEED-017-design-revamp-phase-4-quality-gate-failures.md',
        'artifacts/playwright/design-revamp-phase4-20260305-*',
    ] as $needle) {
        assertContainsText($needle, $planDoc, "Plan includes expected Phase 4 evidence reference: {$needle}");
    }

    if ($shouldRunCommandGates) {
        logInfo('Phase 4: Execute the narrowest Phase 4 regression command');

        $testResult = runCommand(
            'CI=true LARAVEL_BYPASS_ENV_CHECK=1 pnpm exec vitest run tests/e2e/design-revamp-phase-4.spec.ts',
            'phase 4 targeted vitest verification spec'
        );
        assertTrue(
            $testResult['exit_code'] === 0,
            'phase 4 targeted vitest verification spec passes',
            ['exit_code' => $testResult['exit_code']]
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
