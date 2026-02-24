<?php

/**
 * Manual Test: SQL Export Pipeline - Phase 1 Export Command Implementation
 * Generated: 2026-02-24
 *
 * Purpose:
 * - Verify `db:export-sql` default behavior and SQL structure.
 * - Verify options: `--tables`, `--chunk`, `--compress`, `--no-header`.
 * - Verify header presence and omission.
 * - Verify `NULL` handling and quote escaping.
 *
 * Run:
 * - ./vendor/bin/sail php tests/manual/verify_sql_export_pipeline_phase_1_export_command_implementation.php
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

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$testRunId = 'sql_export_pipeline_phase_1_export_command_implementation_'.Carbon::now()->format('Y_m_d_His');
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

function assertNotContainsManual(string $needle, string $haystack, string $label): void
{
    assertTrueManual(! str_contains($haystack, $needle), $label, ['needle' => $needle]);
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

/**
 * @param  array<string, mixed>  $options
 * @return array{exit_code: int, output: string}
 */
function runExportCommandManual(array $options = []): array
{
    $exitCode = Artisan::call('db:export-sql', $options);

    return [
        'exit_code' => $exitCode,
        'output' => Artisan::output(),
    ];
}

function readFileContentsManual(string $path): string
{
    $contents = file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException("Failed to read file: {$path}");
    }

    return $contents;
}

function readGzipContentsManual(string $path): string
{
    $encoded = file_get_contents($path);

    if ($encoded === false) {
        throw new RuntimeException("Failed to read gzip file: {$path}");
    }

    $decoded = gzdecode($encoded);

    if ($decoded === false) {
        throw new RuntimeException("Failed to decode gzip file: {$path}");
    }

    return $decoded;
}

$exitCode = 0;
$txStarted = false;
$defaultOutputPath = storage_path('app/alert-export.sql');
$defaultOutputBackupPath = $workingDir.'/alert-export.sql.backup';
$defaultOutputBackedUp = false;

try {
    logInfo('=== Starting Manual Test: SQL Export Pipeline Phase 1 ===', [
        'app_env' => app()->environment(),
        'db_connection' => config('database.default'),
    ]);

    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Database connection failed. Start Sail testing profile and run this script via scripts/run-manual-test.sh.',
            previous: $e
        );
    }

    if (! is_dir($workingDir) && ! mkdir($workingDir, 0775, true) && ! is_dir($workingDir)) {
        throw new RuntimeException("Unable to create working directory: {$workingDir}");
    }

    if (file_exists($defaultOutputPath)) {
        $defaultContents = file_get_contents($defaultOutputPath);
        assertTrueManual($defaultContents !== false, 'existing default export file is readable before backup');

        assertTrueManual(
            file_put_contents($defaultOutputBackupPath, $defaultContents) !== false,
            'existing default export file is backed up'
        );

        $defaultOutputBackedUp = true;
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('Step 1: Seed deterministic Phase 1 dataset into testing DB transaction.');

    DB::table('fire_incidents')->delete();
    DB::table('police_calls')->delete();
    DB::table('transit_alerts')->delete();
    DB::table('go_transit_alerts')->delete();

    FireIncident::factory()->create([
        'event_num' => 'F26070001',
        'event_type' => "O'HARA",
        'prime_street' => null,
        'cross_streets' => "Queen's Quay & King's",
        'dispatch_time' => CarbonImmutable::parse('2026-02-24 01:23:45', 'UTC'),
        'alarm_level' => 3,
        'is_active' => false,
        'units_dispatched' => "P1,'P2'",
    ]);

    FireIncident::factory()->create([
        'event_num' => 'F26070002',
        'event_type' => 'FIRE',
    ]);

    FireIncident::factory()->create([
        'event_num' => 'F26070003',
        'event_type' => 'ALRM',
    ]);

    PoliceCall::factory()->create([
        'object_id' => 710001,
        'call_type_code' => 'THEFT',
        'call_type' => "THEFT FROM AUTO'S",
    ]);

    TransitAlert::factory()->create([
        'external_id' => 'api:phase1:manual:0001',
        'description' => "Service change near Queen's Park",
    ]);

    GoTransitAlert::factory()->create([
        'external_id' => 'notif:LW:TDELAY:PHASE1001',
        'message_subject' => "Lakeshore West delay's advisory",
    ]);

    assertTrueManual(FireIncident::count() === 3, 'exactly 3 fire incidents seeded');
    assertTrueManual(PoliceCall::count() === 1, 'exactly 1 police call seeded');
    assertTrueManual(TransitAlert::count() === 1, 'exactly 1 transit alert seeded');
    assertTrueManual(GoTransitAlert::count() === 1, 'exactly 1 GO Transit alert seeded');

    logInfo('Step 2: Run default db:export-sql and verify standard SQL structure.');

    $defaultExport = runExportCommandManual();

    assertTrueManual($defaultExport['exit_code'] === 0, 'default db:export-sql exits successfully', [
        'output' => $defaultExport['output'],
    ]);
    assertTrueManual(file_exists($defaultOutputPath), 'default output file exists', ['path' => $defaultOutputPath]);

    $defaultSql = readFileContentsManual($defaultOutputPath);

    assertContainsManual("SET client_encoding = 'UTF8';", $defaultSql, 'default export includes client encoding header');
    assertContainsManual("SET TIME ZONE 'UTC';", $defaultSql, 'default export includes timezone header');
    assertContainsManual('INSERT INTO "fire_incidents"', $defaultSql, 'default export includes fire table insert');
    assertContainsManual('INSERT INTO "police_calls"', $defaultSql, 'default export includes police table insert');
    assertContainsManual('INSERT INTO "transit_alerts"', $defaultSql, 'default export includes transit table insert');
    assertContainsManual('INSERT INTO "go_transit_alerts"', $defaultSql, 'default export includes GO table insert');
    assertContainsManual('ON CONFLICT (id) DO NOTHING;', $defaultSql, 'default export includes Postgres UPSERT clause');
    assertContainsManual("pg_get_serial_sequence('fire_incidents', 'id')", $defaultSql, 'fire sequence reset statement exists');
    assertContainsManual("pg_get_serial_sequence('police_calls', 'id')", $defaultSql, 'police sequence reset statement exists');
    assertContainsManual("pg_get_serial_sequence('transit_alerts', 'id')", $defaultSql, 'transit sequence reset statement exists');
    assertContainsManual("pg_get_serial_sequence('go_transit_alerts', 'id')", $defaultSql, 'GO sequence reset statement exists');
    assertNotContainsManual('`', $defaultSql, 'default export does not use MySQL-style backticks');
    assertContainsManual("'O''HARA'", $defaultSql, 'single quote escaping for event_type is correct');
    assertContainsManual("'Queen''s Quay & King''s'", $defaultSql, 'single quote escaping for cross streets is correct');
    assertContainsManual("'P1,''P2'''", $defaultSql, 'single quote escaping for units_dispatched is correct');
    assertContainsManual('NULL', $defaultSql, 'NULL literal is emitted for null fields');
    assertNotContainsManual("'NULL'", $defaultSql, 'NULL is not emitted as a quoted string');
    assertContainsManual('FALSE', $defaultSql, 'boolean false is emitted as FALSE literal');

    logInfo('Step 3: Verify --tables + --chunk + --no-header options.');

    $filteredOutputPath = $workingDir.'/phase1-fire-only.sql';

    $filteredExport = runExportCommandManual([
        '--output' => $filteredOutputPath,
        '--tables' => 'fire_incidents',
        '--chunk' => 2,
        '--no-header' => true,
    ]);

    assertTrueManual($filteredExport['exit_code'] === 0, 'filtered export exits successfully', [
        'output' => $filteredExport['output'],
    ]);
    assertTrueManual(file_exists($filteredOutputPath), 'filtered output file exists', ['path' => $filteredOutputPath]);

    $filteredSql = readFileContentsManual($filteredOutputPath);

    assertTrueManual(
        substr_count($filteredSql, 'INSERT INTO "fire_incidents"') === 2,
        'chunking with --chunk=2 yields two fire INSERT batches for three rows',
        ['insert_count' => substr_count($filteredSql, 'INSERT INTO "fire_incidents"')]
    );
    assertNotContainsManual('INSERT INTO "transit_alerts"', $filteredSql, 'filtered export excludes non-selected tables');
    assertNotContainsManual('SET client_encoding', $filteredSql, 'filtered export omits header when --no-header is used');
    assertNotContainsManual('SET TIME ZONE', $filteredSql, 'filtered export omits timezone header when --no-header is used');

    logInfo('Step 4: Verify --compress writes gzip output (.gz) and content is decodable.');

    $compressedBasePath = $workingDir.'/phase1-compressed.sql';
    $compressedPath = $compressedBasePath.'.gz';

    $compressedExport = runExportCommandManual([
        '--output' => $compressedBasePath,
        '--tables' => 'fire_incidents',
        '--compress' => true,
    ]);

    assertTrueManual($compressedExport['exit_code'] === 0, 'compressed export exits successfully', [
        'output' => $compressedExport['output'],
    ]);
    assertTrueManual(file_exists($compressedPath), 'compressed output file exists', ['path' => $compressedPath]);

    $compressedSql = readGzipContentsManual($compressedPath);

    assertContainsManual('INSERT INTO "fire_incidents"', $compressedSql, 'compressed export contains fire table insert after decode');
    assertContainsManual('ON CONFLICT (id) DO NOTHING;', $compressedSql, 'compressed export keeps UPSERT syntax');

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;

    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    if ($txStarted) {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                logInfo('Transaction rolled back (testing DB unchanged).');
            }
        } catch (Throwable $rollbackException) {
            logError('Rollback failed', ['message' => $rollbackException->getMessage()]);
        }
    }

    if ($defaultOutputBackedUp) {
        try {
            $backupContents = file_get_contents($defaultOutputBackupPath);
            if ($backupContents !== false) {
                file_put_contents($defaultOutputPath, $backupContents);
                logInfo('Restored pre-existing default export file.');
            }
        } catch (Throwable $restoreException) {
            logError('Failed to restore default export backup', ['message' => $restoreException->getMessage()]);
        }
    } else {
        @unlink($defaultOutputPath);
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
