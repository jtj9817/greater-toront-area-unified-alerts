<?php

/**
 * Manual Test: Phase 5 Quality Gate & Finalization
 * Generated: 2026-02-03
 * Purpose: Run the final quality gates for the Unified Alerts Architecture track:
 * - Backend tests + coverage gates (>= 90% on unified alerts related code)
 * - Pint (check mode)
 * - Frontend format/lint/types/tests + coverage gates (>= 90% on gta-alerts feature)
 * - Dependency audits (composer + pnpm)
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution
if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

$testRunId = 'phase_5_quality_gate_'.Carbon::now()->format('Y_m_d_His');
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

if (! is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

function logInfo(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->info($msg, $ctx);
    echo "[INFO] {$msg}\n";
}

function logError(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->error($msg, $ctx);
    echo "[ERROR] {$msg}\n";
}

function runCommand(string $label, string $command): void
{
    logInfo("Running: {$label}", ['command' => $command]);

    $process = new Process(['bash', '-lc', $command], base_path());
    $process->setTimeout(null);

    $outputBuffer = '';

    $process->run(function (string $type, string $output) use (&$outputBuffer): void {
        $outputBuffer .= $output;
        echo $output;
    });

    if (! $process->isSuccessful()) {
        logError("Command failed: {$label}", [
            'exit_code' => $process->getExitCode(),
            'output_tail' => mb_substr($outputBuffer, -5000),
        ]);

        throw new RuntimeException("Phase 5 quality gate failed at: {$label}");
    }

    logInfo("Command succeeded: {$label}", ['exit_code' => $process->getExitCode()]);
}

$exitCode = 0;

try {
    logInfo('=== Starting Manual Test: Phase 5 Quality Gate & Finalization ===');

    logInfo('Step 1: Pint (check mode)');
    runCommand('pint --test', './vendor/bin/pint --test');

    logInfo('Step 2: Backend tests (no coverage)');
    runCommand('php artisan test', 'php artisan test');

    logInfo('Step 3: Backend coverage gates (unified alerts related code only)');
    runCommand(
        'pest coverage (app/Services/Alerts)',
        'XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --min=90 --coverage-filter app/Services/Alerts'
    );
    runCommand(
        'pest coverage (app/Http/Controllers)',
        'XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --min=90 --coverage-filter app/Http/Controllers'
    );
    runCommand(
        'pest coverage (app/Http/Resources)',
        'XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --min=90 --coverage-filter app/Http/Resources'
    );

    logInfo('Step 4: Frontend quality checks');
    runCommand('corepack enable (best effort)', 'corepack enable || true');
    runCommand('prettier (check)', 'pnpm run format:check');
    runCommand('eslint (check)', 'pnpm run lint:check');
    runCommand('tsc (noEmit)', 'pnpm run types');
    runCommand('vitest', 'pnpm run test');
    runCommand('vitest coverage (>= 90% gta-alerts)', 'pnpm run coverage');

    logInfo('Step 5: Dependency audits');
    runCommand('composer audit', 'composer audit');
    runCommand('pnpm audit (high+)', 'pnpm audit --audit-level high');

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    logInfo('=== Test Run Finished ===');

    if ($exitCode === 0) {
        echo "\nResult: PASS\nLogs at: {$logFileRelative}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFileRelative}\n";
    }

    exit($exitCode);
}
