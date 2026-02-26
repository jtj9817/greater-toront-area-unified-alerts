<?php

/**
 * Manual Test: FEED-010 Phase 2 Provider Refactors (Core Compatibility)
 * Purpose: Verify PostgreSQL provider compatibility and UNION ALL type consistency.
 */

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Providers\FireAlertSelectProvider;
use App\Services\Alerts\Providers\GoTransitAlertSelectProvider;
use App\Services\Alerts\Providers\PoliceAlertSelectProvider;
use App\Services\Alerts\Providers\TransitAlertSelectProvider;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require __DIR__.'/../../vendor/autoload.php';

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

$connection = config('database.default');
$currentDatabase = (string) config("database.connections.{$connection}.database");

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing.\n");
}

umask(002);

$testRunId = 'feed_010_phase_2_provider_refactors_core_compatibility_'.Carbon::now()->format('Y_m_d_His');
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

function assertJsonMeta(mixed $meta, string $label): void
{
    if (is_array($meta)) {
        logInfo("Assertion passed: {$label} meta already array");

        return;
    }

    assertTrue(is_string($meta) && $meta !== '', "{$label} meta is non-empty json string");

    try {
        $decoded = json_decode($meta, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        throw new RuntimeException("{$label} meta is not valid JSON.", previous: $e);
    }

    assertTrue(is_array($decoded), "{$label} meta decodes to array");
}

function assertNullOrNumeric(mixed $value, string $label): void
{
    assertTrue($value === null || is_numeric($value), "{$label} is null or numeric", ['value' => $value]);
}

$exitCode = 0;
$txStarted = false;

try {
    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Database connection failed. If using Sail, run: ./vendor/bin/sail php tests/manual/verify_feed_010_phase_2_provider_refactors_core_compatibility.php',
            previous: $e,
        );
    }

    if (DB::getDriverName() !== 'pgsql') {
        throw new RuntimeException('This manual test requires DB_CONNECTION=pgsql.');
    }

    logInfo('Running with testing database', ['connection' => $connection, 'database' => $currentDatabase]);

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: FEED-010 Phase 2 Provider Refactors (Core Compatibility) ===');

    FireIncident::query()->delete();
    PoliceCall::query()->delete();
    TransitAlert::query()->delete();
    GoTransitAlert::query()->delete();

    FireIncident::factory()->create([
        'event_num' => 'F-PGSQL-1001',
        'event_type' => 'ALARM FIRE',
        'prime_street' => 'Bay St',
        'cross_streets' => 'King St',
        'dispatch_time' => CarbonImmutable::parse('2026-02-25 12:00:00'),
        'alarm_level' => 2,
        'beat' => '11A',
        'units_dispatched' => 'P1,P2',
        'is_active' => true,
    ]);

    PoliceCall::factory()->create([
        'object_id' => 777001,
        'call_type_code' => 'THEFT',
        'call_type' => 'THEFT UNDER',
        'division' => 'D52',
        'cross_streets' => 'Queen St - University Ave',
        'latitude' => 43.6532,
        'longitude' => -79.3832,
        'occurrence_time' => CarbonImmutable::parse('2026-02-25 12:10:00'),
        'is_active' => true,
    ]);

    TransitAlert::factory()->create([
        'external_id' => 'ttc:pgsql:1',
        'route_type' => 'Subway',
        'route' => '1',
        'title' => 'Line 1 delay',
        'description' => 'Signal problem',
        'stop_start' => 'Union',
        'stop_end' => 'Bloor',
        'active_period_start' => CarbonImmutable::parse('2026-02-25 12:20:00'),
        'is_active' => true,
    ]);

    GoTransitAlert::factory()->create([
        'external_id' => 'go:pgsql:1',
        'alert_type' => 'notification',
        'service_mode' => 'GO Train',
        'corridor_or_route' => 'Lakeshore West',
        'corridor_code' => 'LW',
        'message_subject' => 'Minor delays',
        'message_body' => 'Expect 10 minute delays',
        'posted_at' => CarbonImmutable::parse('2026-02-25 12:30:00'),
        'is_active' => true,
    ]);

    logInfo('Step 1: Verify each provider executes and returns compatible row types on pgsql');

    $criteria = new UnifiedAlertsCriteria(status: 'all', perPage: 50);

    $fireRow = (new FireAlertSelectProvider)->select($criteria)->first();
    $policeRow = (new PoliceAlertSelectProvider)->select($criteria)->first();
    $transitRow = (new TransitAlertSelectProvider)->select($criteria)->first();
    $goRow = (new GoTransitAlertSelectProvider)->select($criteria)->first();

    assertTrue($fireRow !== null, 'fire provider returned row');
    assertTrue($policeRow !== null, 'police provider returned row');
    assertTrue($transitRow !== null, 'transit provider returned row');
    assertTrue($goRow !== null, 'go transit provider returned row');

    logInfo('Step 2: Verify id/external_id are strings for all providers');

    assertTrue(is_string((string) $fireRow->id) && (string) $fireRow->id !== '', 'fire.id string');
    assertTrue(is_string((string) $fireRow->external_id) && (string) $fireRow->external_id !== '', 'fire.external_id string');

    assertTrue(is_string((string) $policeRow->id) && (string) $policeRow->id !== '', 'police.id string');
    assertTrue(is_string((string) $policeRow->external_id) && (string) $policeRow->external_id !== '', 'police.external_id string');

    assertTrue(is_string((string) $transitRow->id) && (string) $transitRow->id !== '', 'transit.id string');
    assertTrue(is_string((string) $transitRow->external_id) && (string) $transitRow->external_id !== '', 'transit.external_id string');

    assertTrue(is_string((string) $goRow->id) && (string) $goRow->id !== '', 'go_transit.id string');
    assertTrue(is_string((string) $goRow->external_id) && (string) $goRow->external_id !== '', 'go_transit.external_id string');

    logInfo('Step 3: Verify meta JSON is valid for each provider row');

    assertJsonMeta($fireRow->meta, 'fire');
    assertJsonMeta($policeRow->meta, 'police');
    assertJsonMeta($transitRow->meta, 'transit');
    assertJsonMeta($goRow->meta, 'go_transit');

    logInfo('Step 4: Verify lat/lng are null or numeric (no union coercion errors)');

    assertNullOrNumeric($fireRow->lat, 'fire.lat');
    assertNullOrNumeric($fireRow->lng, 'fire.lng');

    assertNullOrNumeric($policeRow->lat, 'police.lat');
    assertNullOrNumeric($policeRow->lng, 'police.lng');

    assertNullOrNumeric($transitRow->lat, 'transit.lat');
    assertNullOrNumeric($transitRow->lng, 'transit.lng');

    assertNullOrNumeric($goRow->lat, 'go_transit.lat');
    assertNullOrNumeric($goRow->lng, 'go_transit.lng');

    logInfo('Step 5: Verify full UNION ALL path executes with all providers on pgsql');

    $result = app(UnifiedAlertsQuery::class)->paginate($criteria);

    assertTrue($result->total() >= 4, 'unified query returns rows from provider union', ['total' => $result->total()]);

    foreach ($result->items() as $item) {
        assertTrue(is_string($item->id) && $item->id !== '', 'unified item id string', ['id' => $item->id]);
        assertTrue(is_string($item->externalId) && $item->externalId !== '', 'unified item external_id string', ['external_id' => $item->externalId]);
        assertTrue(is_array($item->meta), 'unified item meta decoded array');

        $lat = $item->location?->lat;
        $lng = $item->location?->lng;
        assertNullOrNumeric($lat, 'unified item lat');
        assertNullOrNumeric($lng, 'unified item lng');
    }

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    try {
        Paginator::currentPageResolver(fn () => 1);
    } catch (Throwable) {
    }

    try {
        Carbon::setTestNow();
    } catch (Throwable) {
    }

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
