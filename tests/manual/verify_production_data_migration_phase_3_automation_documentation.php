<?php

/**
 * Manual Test: Production Data Migration - Phase 3 Automation & Documentation
 * Generated: 2026-02-08
 * Purpose: Verify automation script behavior and deployment documentation coverage.
 */

require __DIR__.'/../../vendor/autoload.php';

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';
$_SERVER['APP_ENV'] = 'testing';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALLOW_ROOT_MANUAL_TESTS') !== '1') {
    fwrite(STDERR, "Error: Do not run manual tests as root. Use ./vendor/bin/sail shell (or ./vendor/bin/sail php ...).\n");
    fwrite(STDERR, "If you really need root, re-run with ALLOW_ROOT_MANUAL_TESTS=1 (not recommended).\n");
    exit(1);
}

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing.\n");
}

$expectedConnection = 'mysql';
$connection = config('database.default');

if ($connection !== $expectedConnection) {
    exit("Error: Manual tests must use '{$expectedConnection}' connection (current: {$connection}).\n");
}

$expectedDatabase = env('TEST_DB_DATABASE', 'gta_alerts_testing');
$currentDatabase = config("database.connections.{$connection}.database");

if ($currentDatabase !== $expectedDatabase) {
    exit("Error: Manual tests must use testing DB '{$expectedDatabase}' (current: {$currentDatabase}).\n");
}

$expectedHost = 'mysql-testing';
$currentHost = config("database.connections.{$connection}.host");

if ($currentHost !== $expectedHost) {
    exit("Error: Manual tests must target testing DB host '{$expectedHost}' (current: {$currentHost}).\n");
}

umask(002);

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$testRunId = 'production_data_migration_phase_3_'.Carbon::now()->format('Y_m_d_His');
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");
$workingDir = storage_path("app/private/manual_tests/{$testRunId}");
$repoRoot = realpath(__DIR__.'/../..');

$logDir = dirname($logFile);
if (! is_dir($logDir)) {
    mkdir($logDir, 0775, true);
}

if (! file_exists($logFile)) {
    touch($logFile);
    chmod($logFile, 0664);
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

function assertTrueManual(bool $condition, string $label, array $ctx = []): void
{
    if (! $condition) {
        logError("Assertion failed: {$label}", $ctx);
        throw new RuntimeException("Assertion failed: {$label}");
    }

    logInfo("Assertion passed: {$label}");
}

function assertContainsManual(string $needle, string $haystack, string $label): void
{
    assertTrueManual(str_contains($haystack, $needle), $label, ['needle' => $needle]);
}

function deleteDirectoryRecursivelyManual(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $entries = glob($directory.'/*');

    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if (is_dir($entry)) {
                deleteDirectoryRecursivelyManual($entry);
            } else {
                @unlink($entry);
            }
        }
    }

    @rmdir($directory);
}

function runCommandManual(string $command, ?string $cwd = null): array
{
    $output = [];
    $exitCode = 0;

    if ($cwd !== null) {
        $command = 'cd '.escapeshellarg($cwd).' && '.$command;
    }

    exec($command.' 2>&1', $output, $exitCode);

    return [
        'output' => implode("\n", $output),
        'exit_code' => $exitCode,
    ];
}

$exitCode = 0;
$transactionStarted = false;

try {
    if ($repoRoot === false) {
        throw new RuntimeException('Unable to resolve repository root path.');
    }

    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Database connection failed. Start Sail with testing profile and run this script via scripts/run-manual-test.sh.',
            previous: $e
        );
    }

    if (! is_dir($workingDir)) {
        mkdir($workingDir, 0775, true);
    }

    DB::beginTransaction();
    $transactionStarted = true;

    logInfo('=== Starting Manual Test: Production Data Migration Phase 3 ===');

    logInfo('Step 1: Validate Phase 3 artifacts exist.');

    $automationScriptPath = $repoRoot.'/scripts/generate-production-seed.sh';
    $deploymentDocPath = $repoRoot.'/docs/deployment/production-seeding.md';

    assertTrueManual(file_exists($automationScriptPath), 'automation script exists');
    assertTrueManual(is_executable($automationScriptPath), 'automation script is executable');
    assertTrueManual(file_exists($deploymentDocPath), 'deployment documentation exists');

    $docContents = file_get_contents($deploymentDocPath);
    assertTrueManual($docContents !== false, 'deployment document is readable');
    assertContainsManual('## Step 3: Run In Laravel Forge', $docContents, 'Forge instructions are documented');
    assertContainsManual('## Security Warnings', $docContents, 'security warnings are documented');
    assertContainsManual('## Troubleshooting', $docContents, 'troubleshooting is documented');

    logInfo('Step 2: Build a minimal dataset for seeder export.');

    FireIncident::factory()->create();
    PoliceCall::factory()->create();
    TransitAlert::factory()->create();
    GoTransitAlert::factory()->create();

    assertTrueManual(FireIncident::count() >= 1, 'fire incidents available');
    assertTrueManual(PoliceCall::count() >= 1, 'police calls available');
    assertTrueManual(TransitAlert::count() >= 1, 'transit alerts available');
    assertTrueManual(GoTransitAlert::count() >= 1, 'go transit alerts available');

    logInfo('Step 3: Verify automation script help output.');

    $helpResult = runCommandManual('bash scripts/generate-production-seed.sh --help', $repoRoot);

    assertTrueManual($helpResult['exit_code'] === 0, 'automation script --help exits successfully', ['output' => $helpResult['output']]);
    assertContainsManual('--path <path>', $helpResult['output'], 'help includes --path option');
    assertContainsManual('--stage', $helpResult['output'], 'help includes git staging option');

    logInfo('Step 4: Execute automation script end-to-end (non-interactive).');

    $outputSeederPath = $workingDir.'/ProductionDataSeeder.php';

    $scriptResult = runCommandManual(
        'bash scripts/generate-production-seed.sh --no-sail --path '.escapeshellarg($outputSeederPath).' --chunk 1 --max-bytes 10485760',
        $repoRoot
    );

    assertTrueManual($scriptResult['exit_code'] === 0, 'automation script exits successfully', ['output' => $scriptResult['output']]);
    assertContainsManual('Production seed generation workflow completed successfully.', $scriptResult['output'], 'automation script completion message emitted');
    assertTrueManual(file_exists($outputSeederPath), 'automation script generated main seeder file');

    $verifyExitCode = Artisan::call('db:verify-production-seed', ['--path' => $outputSeederPath]);
    $verifyOutput = Artisan::output();

    assertTrueManual($verifyExitCode === 0, 'generated seeder passes explicit verify command', ['output' => $verifyOutput]);

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;

    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    if ($transactionStarted) {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                logInfo('Transaction rolled back (testing DB preserved).');
            }
        } catch (Throwable $rollbackException) {
            logError('Rollback failed', ['message' => $rollbackException->getMessage()]);
        }
    }

    deleteDirectoryRecursivelyManual($workingDir);

    logInfo('=== Test Run Finished ===');

    if ($exitCode === 0) {
        echo "\nResult: PASS\nLogs at: {$logFileRelative}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFileRelative}\n";
    }

    exit($exitCode);
}
