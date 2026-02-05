<?php

/**
 * Manual Test: TTC Transit Integration - Phase 1 Persistence Layer
 * Generated: 2026-02-05
 * Purpose: Verify `transit_alerts` persistence primitives (schema, indexes, model, factory states).
 */

require __DIR__.'/../../vendor/autoload.php';

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

// Manual tests can delete data; only allow the dedicated testing database.
$expectedDatabase = 'gta_alerts_testing';
$connection = config('database.default');
$currentDatabase = config("database.connections.{$connection}.database");

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing. Destructive test operations are disabled outside the testing environment and cannot be overridden.\n");
}

if ($currentDatabase !== $expectedDatabase) {
    exit("Error: Manual tests must use the '{$expectedDatabase}' database (current: {$currentDatabase}). Destructive test operations are disabled and cannot be overridden.\n");
}

umask(002);

use App\Models\TransitAlert;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

$testRunId = 'ttc_phase_1_persistence_'.Carbon::now()->format('Y_m_d_His');
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

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

function assertTrue(bool $condition, string $label, array $ctx = []): void
{
    if (! $condition) {
        $message = "Assertion failed: {$label}.";
        logError($message, $ctx);
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function assertEqual(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        $message = "Assertion failed for {$label}.";
        logError($message, ['expected' => $expected, 'actual' => $actual]);
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function hasIndex(array $indexRows, string $columns, bool $unique): bool
{
    foreach ($indexRows as $row) {
        $isUnique = ((int) $row->non_unique) === 0;
        if ($row->columns === $columns && $isUnique === $unique) {
            return true;
        }
    }

    return false;
}

$exitCode = 0;
$txStarted = false;

try {
    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        throw new RuntimeException(
            "Database connection failed. If you're using Sail, ensure Docker is running and execute: ./vendor/bin/sail php tests/manual/verify_ttc_phase_1_persistence.php",
            previous: $e
        );
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: TTC Transit Phase 1 Persistence ===');

    logInfo('Phase 1: Schema and index verification');
    assertTrue(Schema::hasTable('transit_alerts'), 'transit_alerts table exists');

    $requiredColumns = [
        'id',
        'external_id',
        'source_feed',
        'alert_type',
        'route_type',
        'route',
        'title',
        'description',
        'severity',
        'effect',
        'cause',
        'active_period_start',
        'active_period_end',
        'direction',
        'stop_start',
        'stop_end',
        'url',
        'is_active',
        'feed_updated_at',
        'created_at',
        'updated_at',
    ];

    foreach ($requiredColumns as $column) {
        assertTrue(
            Schema::hasColumn('transit_alerts', $column),
            "column exists: {$column}"
        );
    }

    $columnTypeRows = DB::select("
        SELECT column_name, data_type
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'transit_alerts'
          AND column_name IN ('description', 'feed_updated_at')
    ");

    $columnTypes = [];
    foreach ($columnTypeRows as $row) {
        $columnTypes[$row->column_name] = strtolower((string) $row->data_type);
    }

    assertEqual($columnTypes['description'] ?? null, 'mediumtext', 'description uses mediumText');
    assertEqual($columnTypes['feed_updated_at'] ?? null, 'timestamp', 'feed_updated_at uses timestamp');

    $indexRows = DB::select("
        SELECT
            index_name,
            non_unique,
            GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'transit_alerts'
        GROUP BY index_name, non_unique
    ");

    assertTrue(hasIndex($indexRows, 'external_id', true), 'unique index on external_id');
    assertTrue(hasIndex($indexRows, 'is_active,active_period_start', false), 'composite index on is_active+active_period_start');
    assertTrue(hasIndex($indexRows, 'feed_updated_at', false), 'index on feed_updated_at');
    assertTrue(hasIndex($indexRows, 'source_feed', false), 'index on source_feed');
    assertTrue(hasIndex($indexRows, 'route_type', false), 'index on route_type');

    logInfo('Phase 2: TransitAlert model verification');

    $alert = new TransitAlert([
        'active_period_start' => '2026-02-05 11:00:00',
        'active_period_end' => '2026-02-05 12:00:00',
        'feed_updated_at' => '2026-02-05 10:59:00',
        'is_active' => 1,
    ]);

    $fillable = $alert->getFillable();
    assertTrue(in_array('external_id', $fillable, true), 'fillable includes external_id');
    assertTrue(in_array('source_feed', $fillable, true), 'fillable includes source_feed');
    assertTrue(in_array('feed_updated_at', $fillable, true), 'fillable includes feed_updated_at');

    assertTrue($alert->active_period_start instanceof DateTimeInterface, 'active_period_start cast to datetime');
    assertTrue($alert->active_period_end instanceof DateTimeInterface, 'active_period_end cast to datetime');
    assertTrue($alert->feed_updated_at instanceof DateTimeInterface, 'feed_updated_at cast to datetime');
    assertTrue($alert->is_active === true, 'is_active cast to boolean');

    logInfo('Phase 3: Factory states, scope, and unique-key behavior');

    $inactiveSample = TransitAlert::factory()->inactive()->make();
    assertTrue($inactiveSample->is_active === false, 'factory state inactive sets is_active=false');

    $subwaySample = TransitAlert::factory()->subway()->make();
    assertEqual($subwaySample->route_type, 'Subway', 'factory subway sets route_type');
    assertEqual($subwaySample->source_feed, 'live-api', 'factory subway sets source_feed');
    assertTrue(str_starts_with((string) $subwaySample->external_id, 'api:'), 'factory subway uses api: external_id');

    $elevatorSample = TransitAlert::factory()->elevator()->make();
    assertEqual($elevatorSample->route_type, 'Elevator', 'factory elevator sets route_type');
    assertEqual($elevatorSample->effect, 'ACCESSIBILITY_ISSUE', 'factory elevator sets effect');

    $sxaSample = TransitAlert::factory()->sxa()->make();
    assertEqual($sxaSample->source_feed, 'sxa', 'factory sxa sets source_feed');
    assertTrue(str_starts_with((string) $sxaSample->external_id, 'sxa:'), 'factory sxa uses sxa: external_id');

    TransitAlert::query()->delete();
    TransitAlert::factory()->subway()->create(['is_active' => true]);
    TransitAlert::factory()->elevator()->create(['is_active' => true]);
    TransitAlert::factory()->inactive()->sxa()->create();
    assertEqual(TransitAlert::active()->count(), 2, 'active() scope returns active rows only');

    TransitAlert::factory()->create(['external_id' => 'api:manual-unique-001']);
    $uniqueViolationDetected = false;

    try {
        TransitAlert::factory()->create(['external_id' => 'api:manual-unique-001']);
    } catch (QueryException) {
        $uniqueViolationDetected = true;
    }

    assertTrue($uniqueViolationDetected, 'unique external_id constraint rejects duplicates');

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
                logInfo('Transaction rolled back (Database preserved).');
            }
        } catch (Throwable) {
        }
    }

    logInfo('=== Test Run Finished ===');

    if ($exitCode === 0) {
        echo "\nResult: PASS\nLogs at: {$logFileRelative}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFileRelative}\n";
    }

    exit($exitCode);
}
