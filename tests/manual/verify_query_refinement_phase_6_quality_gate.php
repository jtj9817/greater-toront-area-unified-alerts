<?php

/**
 * Manual Test: Query Refinement 20260203 - Phase 6 Quality Gate & Finalization
 * Generated: 2026-02-05
 * Purpose: Run final quality gates for the query refinement track:
 * - Pint style compliance
 * - >=90% coverage for new/modified Unified Alerts files
 * - Security audits (composer + pnpm)
 *
 * Command gates run by default. Set SKIP_COMMAND_GATES=1 or
 * RUN_COMMAND_GATES=0 to skip. Use SKIP_COVERAGE=1 or SKIP_AUDITS=1
 * to skip subsets if needed.
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

$testRunId = 'query_refinement_phase_6_quality_gate_'.Carbon::now()->format('Y_m_d_His');
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

function runCommand(string $label, string $command, array $env = []): void
{
    logInfo("Running: {$label}", ['command' => $command]);

    $process = new Process(['bash', '-lc', $command], base_path());
    $process->setTimeout(null);

    $outputBuffer = '';

    $process->run(function (string $type, string $output) use (&$outputBuffer): void {
        $outputBuffer .= $output;
        echo $output;
    }, $env);

    if (! $process->isSuccessful()) {
        logError("Command failed: {$label}", [
            'exit_code' => $process->getExitCode(),
            'output_tail' => mb_substr($outputBuffer, -5000),
        ]);

        throw new RuntimeException("Phase 6 quality gate failed at: {$label}");
    }

    logInfo("Command succeeded: {$label}", ['exit_code' => $process->getExitCode()]);
}

$exitCode = 0;

try {
    logInfo('=== Starting Manual Test: Query Refinement Phase 6 (Quality Gate) ===');

    $runCommandGates = getenv('RUN_COMMAND_GATES');
    $skipCommandGates = getenv('SKIP_COMMAND_GATES');

    if ($skipCommandGates === '1' || $runCommandGates === '0') {
        logInfo('Skipping command-based gates (set SKIP_COMMAND_GATES=1 or RUN_COMMAND_GATES=0).');
        logInfo('Targets: pint, coverage (>=90%), composer audit, pnpm audit.');
        logInfo('Use SKIP_COVERAGE=1 or SKIP_AUDITS=1 to skip subsets.');
        logInfo('=== Manual Test Completed Successfully ===');

        return;
    }

    logInfo('Step 1: Pint style compliance');
    runCommand('pint --test', './vendor/bin/pint --test');

    if (getenv('SKIP_COVERAGE') === '1') {
        logInfo('Skipping coverage checks because SKIP_COVERAGE=1');
    } else {
        $coverageDriverAvailable = extension_loaded('xdebug') || extension_loaded('pcov');

        if (! $coverageDriverAvailable) {
            logError('Coverage driver not available (xdebug/pcov missing).');
            logError('Install xdebug/pcov or re-run with SKIP_COVERAGE=1 to bypass.');
            throw new RuntimeException('Coverage driver missing.');
        }

        logInfo('Step 2: Coverage checks for Unified Alerts touchpoints (>=90%)');

        $pestTestingEnv = [
            // Ensure child Pest processes run in the "testing" environment even if this
            // manual runner bootstraps Laravel with a local `.env` (which can leak APP_ENV
            // and other variables into subprocesses via putenv()).
            'APP_ENV' => 'testing',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'BCRYPT_ROUNDS' => '4',
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ];

        $coverageTargets = [
            ['label' => 'UnifiedAlertMapper', 'path' => 'app/Services/Alerts/Mappers/UnifiedAlertMapper.php'],
            ['label' => 'UnifiedAlertsQuery', 'path' => 'app/Services/Alerts/UnifiedAlertsQuery.php'],
            ['label' => 'UnifiedAlertsCriteria', 'path' => 'app/Services/Alerts/DTOs/UnifiedAlertsCriteria.php'],
            ['label' => 'AlertStatus', 'path' => 'app/Enums/AlertStatus.php'],
            ['label' => 'AlertSource', 'path' => 'app/Enums/AlertSource.php'],
            ['label' => 'AlertId', 'path' => 'app/Services/Alerts/DTOs/AlertId.php'],
        ];

        foreach ($coverageTargets as $target) {
            runCommand(
                "pest coverage ({$target['label']})",
                "XDEBUG_MODE=coverage ./vendor/bin/pest --coverage --min=90 --coverage-filter {$target['path']}",
                $pestTestingEnv
            );
        }
    }

    if (getenv('SKIP_AUDITS') === '1') {
        logInfo('Skipping audits because SKIP_AUDITS=1');
    } else {
        logInfo('Step 3: Dependency audits');
        runCommand('composer audit', 'composer audit');
        runCommand('corepack enable (best effort)', 'corepack enable || true');
        runCommand('pnpm audit (high+)', 'pnpm audit --audit-level high');
    }

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
