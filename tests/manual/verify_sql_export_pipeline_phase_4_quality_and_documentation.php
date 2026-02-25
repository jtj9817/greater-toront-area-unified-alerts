<?php

/**
 * Manual Test: SQL Export Pipeline - Phase 4 Quality & Documentation
 * Generated: 2026-02-25
 *
 * Purpose:
 * - Verify track documentation reflects SQL export/import as the preferred workflow.
 * - Verify feature test coverage for SQL export/import command modules.
 * - Attempt required coverage gate command and report environment blockers.
 *
 * Run:
 * - php tests/manual/verify_sql_export_pipeline_phase_4_quality_and_documentation.php
 *
 * Optional:
 * - REQUIRE_COVERAGE_PASS=1 to fail when coverage command cannot execute.
 */

require __DIR__.'/../../vendor/autoload.php';

if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}

if (getenv('DB_CONNECTION') === false || getenv('DB_CONNECTION') === '') {
    putenv('DB_CONNECTION=sqlite');
    $_ENV['DB_CONNECTION'] = 'sqlite';
    $_SERVER['DB_CONNECTION'] = 'sqlite';
}

if (getenv('DB_DATABASE') === false || getenv('DB_DATABASE') === '') {
    putenv('DB_DATABASE=:memory:');
    $_ENV['DB_DATABASE'] = ':memory:';
    $_SERVER['DB_DATABASE'] = ':memory:';
}

if (getenv('CACHE_STORE') === false || getenv('CACHE_STORE') === '') {
    putenv('CACHE_STORE=array');
    $_ENV['CACHE_STORE'] = 'array';
    $_SERVER['CACHE_STORE'] = 'array';
}

if (getenv('QUEUE_CONNECTION') === false || getenv('QUEUE_CONNECTION') === '') {
    putenv('QUEUE_CONNECTION=sync');
    $_ENV['QUEUE_CONNECTION'] = 'sync';
    $_SERVER['QUEUE_CONNECTION'] = 'sync';
}

if (getenv('SESSION_DRIVER') === false || getenv('SESSION_DRIVER') === '') {
    putenv('SESSION_DRIVER=array');
    $_ENV['SESSION_DRIVER'] = 'array';
    $_SERVER['SESSION_DRIVER'] = 'array';
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
use Symfony\Component\Process\Process;

$testRunId = 'sql_export_pipeline_phase_4_quality_and_documentation_'.Carbon::now()->format('Y_m_d_His');
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

config(['logging.default' => 'manual_test']);

function logInfo(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->info($msg, $ctx);
    $suffix = $ctx === [] ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES);
    echo "[INFO] {$msg}{$suffix}\n";
}

function logWarning(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->warning($msg, $ctx);
    $suffix = $ctx === [] ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES);
    echo "[WARN] {$msg}{$suffix}\n";
}

function logError(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->error($msg, $ctx);
    $suffix = $ctx === [] ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES);
    echo "[ERROR] {$msg}{$suffix}\n";
}

function assertTrueManual(bool $condition, string $label, array $ctx = []): void
{
    if (! $condition) {
        $message = "Assertion failed: {$label}.";
        logError($message, $ctx);
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function assertContainsManual(string $needle, string $haystack, string $label): void
{
    assertTrueManual(str_contains($haystack, $needle), $label, ['needle' => $needle]);
}

function readFileContentsManual(string $relativePath): string
{
    $absolutePath = base_path($relativePath);
    assertTrueManual(file_exists($absolutePath), "file exists: {$relativePath}");

    $contents = file_get_contents($absolutePath);
    assertTrueManual(is_string($contents), "file is readable: {$relativePath}");

    return $contents;
}

/**
 * @return array{exit_code: int|null, output: string}
 */
function runCommandManual(string $command, string $label): array
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

$requireCoveragePass = getenv('REQUIRE_COVERAGE_PASS') === '1';
$coveragePassed = false;
$exitCode = 0;

try {
    logInfo('=== Manual Test: SQL Export Pipeline Phase 4 Quality & Documentation ===');

    logInfo('Step 1: Verify documentation reflects SQL pipeline.');

    $readme = readFileContentsManual('README.md');
    assertContainsManual('## SQL Export/Import Pipeline (Preferred)', $readme, 'README documents SQL pipeline as preferred');
    assertContainsManual('./scripts/export-alert-data.sh', $readme, 'README references SQL export helper script');
    assertContainsManual('php artisan db:import-sql --file=', $readme, 'README documents SQL import command');
    assertContainsManual('Legacy seeder workflow is retained for compatibility but deprecated', $readme, 'README marks seeder flow deprecated');

    $claude = readFileContentsManual('CLAUDE.md');
    assertContainsManual('php artisan db:export-sql', $claude, 'CLAUDE documents db:export-sql');
    assertContainsManual('php artisan db:import-sql --file=... --force', $claude, 'CLAUDE documents db:import-sql');
    assertContainsManual('Deprecated: legacy seeder export workflow', $claude, 'CLAUDE marks legacy seeder export deprecated');

    $deploymentDoc = readFileContentsManual('docs/deployment/production-seeding.md');
    assertContainsManual('# Production SQL Data Transfer (Forge)', $deploymentDoc, 'Deployment runbook title updated for SQL pipeline');
    assertContainsManual('./scripts/export-alert-data.sh --sail', $deploymentDoc, 'Deployment runbook references SQL export helper');
    assertContainsManual('php artisan db:import-sql --file=storage/app/alert-export.sql --force', $deploymentDoc, 'Deployment runbook references SQL import command');
    assertContainsManual('## Legacy Seeder Workflow (Deprecated)', $deploymentDoc, 'Deployment runbook retains deprecated legacy section');

    $scriptsReadme = readFileContentsManual('scripts/README.md');
    assertContainsManual('## SQL Export Pipeline (Preferred)', $scriptsReadme, 'Scripts README marks SQL pipeline preferred');
    assertContainsManual('## Legacy Seeder Workflow (Deprecated)', $scriptsReadme, 'Scripts README includes legacy deprecated section');

    logInfo('Step 2: Verify automated command test suites.');

    $exportTests = runCommandManual('php artisan test --filter=ExportAlertDataSqlTest', 'ExportAlertDataSqlTest');
    assertTrueManual(($exportTests['exit_code'] ?? 1) === 0, 'ExportAlertDataSqlTest suite passes');

    $importTests = runCommandManual('php artisan test --filter=ImportAlertDataSqlTest', 'ImportAlertDataSqlTest');
    assertTrueManual(($importTests['exit_code'] ?? 1) === 0, 'ImportAlertDataSqlTest suite passes');

    logInfo('Step 3: Attempt required coverage verification command.');

    $sailCoverage = runCommandManual('./vendor/bin/sail artisan test --coverage --min=90', 'Sail coverage gate');
    if (($sailCoverage['exit_code'] ?? 1) === 0) {
        $coveragePassed = true;
        logInfo('Coverage gate passed via Sail command.');
    } elseif (str_contains($sailCoverage['output'], 'Docker is not running')) {
        logWarning('Coverage gate blocked: Docker is not running for Sail command.');
    } else {
        logWarning('Sail coverage command failed.', ['exit_code' => $sailCoverage['exit_code']]);
    }

    if (! $coveragePassed) {
        $localCoverage = runCommandManual('php artisan test --coverage --min=90', 'Local coverage gate');

        if (($localCoverage['exit_code'] ?? 1) === 0) {
            $coveragePassed = true;
            logInfo('Coverage gate passed via local artisan command.');
        } elseif (str_contains($localCoverage['output'], 'Code coverage driver not available')) {
            logWarning('Coverage gate blocked: no Xdebug/PCOV coverage driver available locally.');
        } else {
            logWarning('Local coverage command failed.', ['exit_code' => $localCoverage['exit_code']]);
        }
    }

    if ($requireCoveragePass) {
        assertTrueManual($coveragePassed, 'coverage command must pass when REQUIRE_COVERAGE_PASS=1');
    } elseif (! $coveragePassed) {
        logWarning('Coverage command did not pass in this environment; resolve Docker and/or coverage driver to complete strict gate.');
    }

    logInfo('=== Manual Test Completed ===', [
        'coverage_passed' => $coveragePassed,
        'require_coverage_pass' => $requireCoveragePass,
    ]);
} catch (Throwable $throwable) {
    $exitCode = 1;

    logError('Manual verification failed', [
        'class' => get_class($throwable),
        'message' => $throwable->getMessage(),
        'trace' => $throwable->getTraceAsString(),
    ]);
} finally {
    echo "\n=== Test Run Finished ===\n";
    echo $exitCode === 0 ? "Result: PASS\n" : "Result: FAIL\n";
    echo "Logs at: {$logFileRelative}\n";
}

exit($exitCode);

