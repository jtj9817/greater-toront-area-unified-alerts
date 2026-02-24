<?php

/**
 * Manual Test: SQL Export Pipeline - Phase 2 Import Command Implementation
 * Generated: 2026-02-24
 *
 * Purpose:
 * - Verify `db:import-sql` safety rails and execution behavior.
 * - Verify refusal in testing without `--allow-testing`.
 * - Verify `.sql.gz` rejection guidance.
 * - Verify `--dry-run` DDL rejection and sqlite dry-run compatibility.
 * - Verify confirmation behavior vs `--force`.
 * - Verify `psql` process invocation details and missing binary handling.
 *
 * Run:
 * - ./vendor/bin/sail php tests/manual/verify_sql_export_pipeline_phase_2_import_command_implementation.php
 */

require __DIR__.'/../../vendor/autoload.php';

// Default manual verification runs to testing so Laravel loads `.env.testing`.
// Preserve an explicitly provided APP_ENV value if the caller set one.
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}

// Some manual test environments do not provide APP_KEY in `.env.testing`.
// Use a deterministic testing-only fallback so app boot does not fail.
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

// Prevent production execution.
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
use Illuminate\Process\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

$testRunId = 'sql_export_pipeline_phase_2_import_command_implementation_'.Carbon::now()->format('Y_m_d_His');
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");
$logDir = dirname($logFile);
$workingDir = storage_path("app/private/manual_tests/{$testRunId}");

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

// Route app warnings/errors under test to this manual log file.
config(['logging.default' => 'manual_test']);

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

function writeSqlFixtureManual(string $path, string $contents): void
{
    $written = file_put_contents($path, $contents);

    if ($written === false) {
        throw new RuntimeException("Failed to write SQL fixture file: {$path}");
    }
}

function configureImportPostgresConnectionManual(string $database = 'gta_alerts'): void
{
    config()->set('database.default', 'pgsql');
    config()->set('database.connections.pgsql', [
        'driver' => 'pgsql',
        'host' => 'db.internal',
        'port' => '5432',
        'database' => $database,
        'username' => 'alerts_user',
        'password' => 'super-secret',
    ]);
}

function configureImportSqliteConnectionManual(): void
{
    config()->set('database.default', 'sqlite');
    config()->set('database.connections.sqlite', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
    ]);
}

/**
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function runWithTimeoutManual(callable $callback, int $timeoutSeconds, string $label): mixed
{
    if ($timeoutSeconds <= 0) {
        return $callback();
    }

    if (! function_exists('pcntl_alarm') || ! function_exists('pcntl_signal') || ! defined('SIGALRM')) {
        static $warnedNoSignalSupport = false;

        if (! $warnedNoSignalSupport) {
            logInfo('Timeout guard skipped: pcntl/SIGALRM is unavailable in this PHP runtime.', [
                'label' => $label,
                'requested_timeout_seconds' => $timeoutSeconds,
            ]);
            $warnedNoSignalSupport = true;
        }

        return $callback();
    }

    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function () use ($timeoutSeconds, $label): void {
        throw new RuntimeException("Timeout after {$timeoutSeconds}s while {$label}.");
    });
    pcntl_alarm($timeoutSeconds);

    try {
        return $callback();
    } finally {
        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
    }
}

/**
 * @param  array<string, mixed>  $options
 * @param  int|null  $timeoutSeconds Optional override. Default timeout is applied for --force paths.
 * @return array{exit_code: int, output: string}
 */
function runImportCommandManual(array $options, ?int $timeoutSeconds = null): array
{
    $isForced = (bool) ($options['--force'] ?? false);
    $effectiveTimeout = $timeoutSeconds ?? ($isForced ? 20 : 0);
    $label = $isForced
        ? 'running db:import-sql --force'
        : 'running db:import-sql';

    return runWithTimeoutManual(static function () use ($options): array {
        $exitCode = Artisan::call('db:import-sql', $options);

        return [
            'exit_code' => $exitCode,
            'output' => Artisan::output(),
        ];
    }, $effectiveTimeout, $label);
}

$exitCode = 0;
$originalDefaultConnection = config('database.default');
$originalPgsqlConnection = config('database.connections.pgsql');
$originalSqliteConnection = config('database.connections.sqlite');

try {
    logInfo('=== Starting Manual Test: SQL Export Pipeline Phase 2 ===', [
        'app_env' => app()->environment(),
        'db_connection' => config('database.default'),
    ]);

    if (! is_dir($workingDir) && ! mkdir($workingDir, 0775, true) && ! is_dir($workingDir)) {
        throw new RuntimeException("Unable to create working directory: {$workingDir}");
    }

    $validSqlPath = $workingDir.'/import-valid.sql';
    $ddlSqlPath = $workingDir.'/import-ddl.sql';
    $gzipSqlPath = $workingDir.'/import-compressed.sql.gz';

    writeSqlFixtureManual($validSqlPath, implode("\n", [
        '-- GTA Alerts SQL Export',
        "SET client_encoding = 'UTF8';",
        'INSERT INTO "fire_incidents" ("id") VALUES (1) ON CONFLICT (id) DO NOTHING;',
        "SELECT setval(pg_get_serial_sequence('fire_incidents', 'id'), 1, true);",
        '',
    ]));

    writeSqlFixtureManual($ddlSqlPath, implode("\n", [
        '-- GTA Alerts SQL Export',
        "SET client_encoding = 'UTF8';",
        'INSERT INTO "fire_incidents" ("id") VALUES (1) ON CONFLICT (id) DO NOTHING;',
        'DROP TABLE fire_incidents;',
        '',
    ]));

    writeSqlFixtureManual($gzipSqlPath, 'not-a-real-gzip-but-extension-is-enough');

    logInfo('Step 1: Verify command refuses testing target unless --allow-testing is provided.');

    configureImportPostgresConnectionManual('gta_alerts');

    $refusalCalls = [];
    Process::fake(function ($process) use (&$refusalCalls) {
        $refusalCalls[] = $process;

        return Process::result(output: '', errorOutput: '', exitCode: 0);
    });

    $refusalResult = runImportCommandManual([
        '--file' => $validSqlPath,
        '--force' => true,
    ]);

    assertTrueManual($refusalResult['exit_code'] === 1, 'import without --allow-testing exits with failure', [
        'output' => $refusalResult['output'],
    ]);
    assertContainsManual(
        'Refusing to import while APP_ENV is testing. Re-run with --allow-testing to override.',
        $refusalResult['output'],
        'testing safeguard refusal message is shown'
    );
    assertTrueManual(count($refusalCalls) === 0, 'testing safeguard exits before spawning psql process');

    logInfo('Step 2: Verify command rejects .sql.gz files and prints decompression guidance.');

    configureImportPostgresConnectionManual('gta_alerts');

    $gzipCalls = [];
    Process::fake(function ($process) use (&$gzipCalls) {
        $gzipCalls[] = $process;

        return Process::result(output: '', errorOutput: '', exitCode: 0);
    });

    $gzipResult = runImportCommandManual([
        '--file' => $gzipSqlPath,
        '--force' => true,
        '--allow-testing' => true,
    ]);

    assertTrueManual($gzipResult['exit_code'] === 1, 'gzip input exits with failure', [
        'output' => $gzipResult['output'],
    ]);
    assertContainsManual(
        'Compressed SQL files are not supported by db:import-sql.',
        $gzipResult['output'],
        'gzip rejection message is shown'
    );
    assertContainsManual(
        "gunzip -c {$gzipSqlPath} | psql -h db.internal -p 5432 -U alerts_user -d gta_alerts",
        $gzipResult['output'],
        'gzip rejection includes exact decompression guidance'
    );
    assertTrueManual(count($gzipCalls) === 0, 'gzip rejection exits before spawning psql process');

    logInfo('Step 3: Verify --dry-run rejects DDL statements without launching psql.');

    configureImportPostgresConnectionManual('gta_alerts');

    $ddlCalls = [];
    Process::fake(function ($process) use (&$ddlCalls) {
        $ddlCalls[] = $process;

        return Process::result(output: '', errorOutput: '', exitCode: 0);
    });

    $ddlResult = runImportCommandManual([
        '--file' => $ddlSqlPath,
        '--dry-run' => true,
        '--allow-testing' => true,
    ]);

    assertTrueManual($ddlResult['exit_code'] === 1, 'dry-run with DDL exits with failure', [
        'output' => $ddlResult['output'],
    ]);
    assertContainsManual(
        'Dry-run failed: DDL statements are not allowed.',
        $ddlResult['output'],
        'dry-run DDL rejection message is shown'
    );
    assertTrueManual(count($ddlCalls) === 0, 'dry-run DDL rejection does not spawn psql process');

    logInfo('Step 4: Verify --dry-run works with sqlite config lacking Postgres network fields.');

    configureImportSqliteConnectionManual();

    $sqliteDryRunCalls = [];
    Process::fake(function ($process) use (&$sqliteDryRunCalls) {
        $sqliteDryRunCalls[] = $process;

        return Process::result(output: '', errorOutput: '', exitCode: 0);
    });

    $sqliteDryRunResult = runImportCommandManual([
        '--file' => $validSqlPath,
        '--dry-run' => true,
        '--allow-testing' => true,
    ]);

    assertTrueManual($sqliteDryRunResult['exit_code'] === 0, 'sqlite dry-run exits successfully', [
        'output' => $sqliteDryRunResult['output'],
    ]);
    assertContainsManual(
        'Dry-run validation passed.',
        $sqliteDryRunResult['output'],
        'sqlite dry-run success message is shown'
    );
    assertTrueManual(count($sqliteDryRunCalls) === 0, 'sqlite dry-run does not spawn psql process');

    logInfo('Step 5: Verify command aborts on confirmation path when --force is omitted.');

    configureImportPostgresConnectionManual('gta_alerts');

    $confirmationCalls = [];
    Process::fake(function ($process) use (&$confirmationCalls) {
        $confirmationCalls[] = $process;

        return Process::result(output: '', errorOutput: '', exitCode: 0);
    });

    $confirmationResult = runImportCommandManual([
        '--file' => $validSqlPath,
        '--allow-testing' => true,
    ]);

    assertTrueManual($confirmationResult['exit_code'] === 1, 'non-forced import exits with failure when confirmation is declined', [
        'output' => $confirmationResult['output'],
    ]);
    assertContainsManual(
        'Import aborted.',
        $confirmationResult['output'],
        'non-forced import shows abort message'
    );
    assertTrueManual(count($confirmationCalls) === 0, 'non-forced import aborts before spawning psql process');

    logInfo('Step 6: Verify --force execution invokes psql with expected command, env, and no timeout.');

    configureImportPostgresConnectionManual('gta_alerts');

    $forceCalls = [];
    Process::fake(function ($process) use (&$forceCalls) {
        $forceCalls[] = $process;

        return Process::result(output: 'imported', errorOutput: '', exitCode: 0);
    });

    $forcedResult = runImportCommandManual([
        '--file' => $validSqlPath,
        '--force' => true,
        '--allow-testing' => true,
    ]);

    assertTrueManual($forcedResult['exit_code'] === 0, 'forced import exits successfully', [
        'output' => $forcedResult['output'],
    ]);
    assertContainsManual(
        'SQL import completed successfully.',
        $forcedResult['output'],
        'forced import success message is shown'
    );
    assertTrueManual(count($forceCalls) === 1, 'forced import launches exactly one psql process');

    $forcedProcess = $forceCalls[0];
    $forcedCommand = is_array($forcedProcess->command ?? null) ? $forcedProcess->command : [];
    $forcedEnvironment = is_array($forcedProcess->environment ?? null) ? $forcedProcess->environment : [];
    $forcedTimeout = $forcedProcess->timeout ?? null;

    assertTrueManual($forcedCommand !== [], 'forced import captured a command array');
    assertTrueManual(($forcedCommand[0] ?? null) === 'psql', 'forced import command starts with psql');
    assertTrueManual(in_array('--host=db.internal', $forcedCommand, true), 'forced import includes host argument');
    assertTrueManual(in_array('--port=5432', $forcedCommand, true), 'forced import includes port argument');
    assertTrueManual(in_array('--username=alerts_user', $forcedCommand, true), 'forced import includes username argument');
    assertTrueManual(in_array('--dbname=gta_alerts', $forcedCommand, true), 'forced import includes database argument');
    assertTrueManual(in_array('--set=ON_ERROR_STOP=1', $forcedCommand, true), 'forced import enables ON_ERROR_STOP');
    assertTrueManual(in_array("--file={$validSqlPath}", $forcedCommand, true), 'forced import targets the selected SQL file');
    assertTrueManual($forcedTimeout === null, 'forced import process timeout is disabled via Process::forever');
    assertTrueManual(
        ($forcedEnvironment['PGPASSWORD'] ?? null) === 'super-secret',
        'forced import passes PGPASSWORD via environment'
    );

    logInfo('Step 7: Verify missing psql binary error handling.');

    configureImportPostgresConnectionManual('gta_alerts');

    $missingPsqlCalls = [];
    Process::fake(function ($process) use (&$missingPsqlCalls) {
        $missingPsqlCalls[] = $process;

        return Process::result(
            output: '',
            errorOutput: 'sh: psql: command not found',
            exitCode: 127,
        );
    });

    $missingPsqlResult = runImportCommandManual([
        '--file' => $validSqlPath,
        '--force' => true,
        '--allow-testing' => true,
    ]);

    assertTrueManual($missingPsqlResult['exit_code'] === 1, 'missing psql exits with failure', [
        'output' => $missingPsqlResult['output'],
    ]);
    assertContainsManual(
        'Import failed: psql CLI binary is required.',
        $missingPsqlResult['output'],
        'missing psql guidance message is shown'
    );
    assertTrueManual(count($missingPsqlCalls) === 1, 'missing psql scenario attempts exactly one process execution');

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;

    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    config()->set('database.default', $originalDefaultConnection);
    config()->set('database.connections.pgsql', $originalPgsqlConnection);
    config()->set('database.connections.sqlite', $originalSqliteConnection);
    Process::swap(new Factory());

    deleteDirectoryRecursivelyManual($workingDir);

    logInfo('=== Test Run Finished ===');

    if ($exitCode === 0) {
        echo "\nResult: PASS\nLogs at: {$logFileRelative}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFileRelative}\n";
    }

    exit($exitCode);
}
