<?php

/**
 * Manual Test: FEED-001 - Phase 1 Backend Filters + Cursor Pagination
 * Generated: 2026-02-19
 *
 * Purpose:
 * - Verify UnifiedAlertsQuery applies server-side filters across the full dataset:
 *   - status (all|active|cleared)
 *   - source (fire|police|transit|go_transit)
 *   - since (30m|1h|3h|6h|12h)
 *   - q (sqlite fallback via LIKE; mysql via FULLTEXT MATCH providers)
 * - Verify cursor pagination batches deterministically with no duplicates.
 *
 * Run:
 * - ./vendor/bin/sail php tests/manual/verify_feed_001_phase_1_backend_filters_cursor_pagination.php
 *
 * Notes:
 * - This script is destructive (it deletes rows).
 * - SQLite runs inside a DB transaction and rolls back.
 * - MySQL does NOT use a wrapping transaction because InnoDB FULLTEXT search may not
 *   see uncommitted inserts; it performs explicit cleanup in `finally` instead.
 * - It must run with APP_ENV=testing and the dedicated testing database.
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

// Manual tests can delete data; only allow the dedicated testing database (no overrides).
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

use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use App\Rules\UnifiedAlertsCursorRule;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\DTOs\UnifiedAlertsCursor;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

$testRunId = 'feed_001_phase_1_backend_filters_cursor_pagination_'.Carbon::now()->format('Y_m_d_His');
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

// Route all app logs (warnings/errors under test) to this manual log file.
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

/**
 * @param  array<int, mixed>  $items
 * @return array<int, string>
 */
function pluckIds(array $items): array
{
    return collect($items)
        ->map(static fn ($item): string => (string) ($item->id ?? ''))
        ->values()
        ->all();
}

$exitCode = 0;
$txStarted = false;
$driverName = null;

try {
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        $connectionName = config('database.default');
        $connectionConfig = is_string($connectionName) ? (config("database.connections.{$connectionName}") ?? []) : [];

        $dbDriver = is_array($connectionConfig) ? (string) ($connectionConfig['driver'] ?? '') : '';
        $dbHost = is_array($connectionConfig) ? (string) ($connectionConfig['host'] ?? '') : '';
        $dbPort = is_array($connectionConfig) ? (string) ($connectionConfig['port'] ?? '') : '';
        $dbDatabase = is_array($connectionConfig) ? (string) ($connectionConfig['database'] ?? '') : '';

        $resolved = $dbHost !== '' ? gethostbyname($dbHost) : '';
        $hostResolves = $dbHost !== '' && $resolved !== '' && $resolved !== $dbHost;

        $hint = "Database connection failed.\n\n"
            ."Connection: {$connectionName}\n"
            ."Driver: {$dbDriver}\n"
            ."Host: {$dbHost}\n"
            ."Port: {$dbPort}\n"
            ."Database: {$dbDatabase}\n";

        if ($dbHost === 'mysql-testing') {
            $hint .= "\nThe `mysql-testing` service is defined under the Docker Compose `testing` profile.\n"
                ."Start it with one of:\n"
                ."- ./vendor/bin/sail up -d --profile testing\n"
                ."- docker compose --profile testing up -d mysql-testing\n";
        }

        if (! $hostResolves && $dbHost !== '' && $dbHost !== '127.0.0.1' && $dbHost !== 'localhost') {
            $hint .= "\nHost resolution failed for '{$dbHost}'. This usually means you're running the script on the host OS\n"
                ."instead of inside the Sail container network. Run:\n"
                ."- ./vendor/bin/sail php tests/manual/verify_feed_001_phase_1_backend_filters_cursor_pagination.php\n";
        } else {
            $hint .= "\nRun inside Sail:\n"
                ."- ./vendor/bin/sail php tests/manual/verify_feed_001_phase_1_backend_filters_cursor_pagination.php\n";
        }

        throw new RuntimeException(rtrim($hint), previous: $e);
    }

    $driverName = DB::getDriverName();

    // MySQL FULLTEXT search can fail to return rows inserted in the same uncommitted transaction.
    // Keep SQLite wrapped for safe rollback; run MySQL without the transaction and clean up.
    $useTransaction = $driverName !== 'mysql';

    if ($useTransaction) {
        DB::beginTransaction();
        $txStarted = true;
    }

    logInfo('=== Starting Manual Test: FEED-001 Phase 1 Backend Filters + Cursor Pagination ===', [
        'driver' => $driverName,
        'wrapped_transaction' => $txStarted,
    ]);

    $now = CarbonImmutable::parse('2026-02-19 12:00:00');
    Carbon::setTestNow(Carbon::instance($now->toDateTime()));
    CarbonImmutable::setTestNow($now);

    logInfo('Step 1: Preparing deterministic dataset (transaction-scoped)');

    // Order matters if FK constraints exist; keep it explicit and narrow.
    // `incident_updates` cascades on delete from `fire_incidents`.
    FireIncident::query()->delete();
    PoliceCall::query()->delete();
    TransitAlert::query()->delete();
    GoTransitAlert::query()->delete();

    // Unique tokens for `q` tests (avoid MySQL stopwords/min-word-length edge cases).
    $tokenFire = 'FEEDTOKFIREVALIDATION';
    $tokenPolice = 'FEEDTOKPOLICEVALIDATION';
    $tokenTransit = 'FEEDTOKTRANSITVALIDATION';
    $tokenGo = 'FEEDTOKGOTRANSITVALIDATION';

    FireIncident::factory()->create([
        'event_num' => 'FIRE-0001',
        'event_type' => "STRUCTURE FIRE {$tokenFire}",
        'prime_street' => 'Main St',
        'cross_streets' => 'King St',
        'dispatch_time' => $now->subMinutes(5),
        'alarm_level' => 2,
        'is_active' => true,
        'feed_updated_at' => $now->subMinutes(5),
    ]);

    FireIncident::factory()->create([
        'event_num' => 'FIRE-0002',
        'event_type' => 'ALARM',
        'prime_street' => 'Queen St',
        'cross_streets' => null,
        'dispatch_time' => $now->subMinutes(20),
        'alarm_level' => 0,
        'is_active' => true,
        'feed_updated_at' => $now->subMinutes(20),
    ]);

    FireIncident::factory()->inactive()->create([
        'event_num' => 'FIRE-0003',
        'event_type' => 'GAS LEAK',
        'prime_street' => null,
        'cross_streets' => null,
        'dispatch_time' => $now->subMinutes(75),
        'alarm_level' => 1,
        'feed_updated_at' => $now->subMinutes(75),
    ]);

    FireIncident::factory()->inactive()->create([
        'event_num' => 'FIRE-0004',
        'event_type' => 'RESCUE',
        'prime_street' => null,
        'cross_streets' => null,
        'dispatch_time' => $now->subMinutes(180),
        'alarm_level' => 0,
        'feed_updated_at' => $now->subMinutes(180),
    ]);

    PoliceCall::factory()->create([
        'object_id' => 900001,
        'call_type_code' => 'ASLTPR',
        'call_type' => "ASSAULT {$tokenPolice}",
        'division' => 'D11',
        'cross_streets' => 'Yonge / Bloor',
        'latitude' => null,
        'longitude' => null,
        'occurrence_time' => $now->subMinutes(10),
        'is_active' => true,
        'feed_updated_at' => $now->subMinutes(10),
    ]);

    PoliceCall::factory()->create([
        'object_id' => 900002,
        'call_type_code' => 'MVC',
        'call_type' => 'MOTOR VEHICLE COLLISION',
        'division' => 'D14',
        'cross_streets' => null,
        'latitude' => null,
        'longitude' => null,
        'occurrence_time' => $now->subMinutes(35),
        'is_active' => true,
        'feed_updated_at' => $now->subMinutes(35),
    ]);

    PoliceCall::factory()->inactive()->create([
        'object_id' => 900003,
        'call_type_code' => 'THEFT',
        'call_type' => 'THEFT',
        'division' => 'D22',
        'cross_streets' => null,
        'latitude' => null,
        'longitude' => null,
        'occurrence_time' => $now->subMinutes(90),
        'feed_updated_at' => $now->subMinutes(90),
    ]);

    PoliceCall::factory()->inactive()->create([
        'object_id' => 900004,
        'call_type_code' => 'SUSP',
        'call_type' => 'SUSPICIOUS PERSON',
        'division' => 'D33',
        'cross_streets' => null,
        'latitude' => null,
        'longitude' => null,
        'occurrence_time' => $now->subMinutes(240),
        'feed_updated_at' => $now->subMinutes(240),
    ]);

    TransitAlert::factory()->create([
        'external_id' => 'api:TR-0001',
        'source_feed' => 'live-api',
        'route_type' => 'Subway',
        'route' => '1',
        'title' => "Line 1 {$tokenTransit} service adjustment",
        'description' => 'Minor operational change',
        'severity' => 'Critical',
        'effect' => 'REDUCED_SERVICE',
        'stop_start' => 'Finch',
        'stop_end' => 'Eglinton',
        'active_period_start' => $now->subMinutes(15),
        'is_active' => true,
        'feed_updated_at' => $now->subMinutes(15),
    ]);

    TransitAlert::factory()->inactive()->create([
        'external_id' => 'sxa:TR-0002',
        'source_feed' => 'sxa',
        'route_type' => 'Streetcar',
        'route' => '510',
        'title' => '510 temporary diversion lifted',
        'description' => 'Normal service resumed',
        'severity' => 'Minor',
        'effect' => 'DETOUR',
        'stop_start' => 'Spadina Station',
        'stop_end' => 'Queens Quay',
        'active_period_start' => $now->subMinutes(120),
        'feed_updated_at' => $now->subMinutes(120),
    ]);

    GoTransitAlert::factory()->create([
        'external_id' => 'GO-0001',
        'alert_type' => 'notification',
        'service_mode' => 'GO Train',
        'corridor_or_route' => 'Lakeshore West',
        'corridor_code' => 'LW',
        'sub_category' => 'TDELAY',
        'message_subject' => "Delay notice {$tokenGo}",
        'message_body' => 'Trains running 15 minutes late',
        'direction' => 'EASTBOUND',
        'trip_number' => null,
        'delay_duration' => null,
        'status' => 'INIT',
        'line_colour' => null,
        'posted_at' => $now->subMinutes(7),
        'is_active' => true,
        'feed_updated_at' => $now->subMinutes(7),
    ]);

    GoTransitAlert::factory()->inactive()->create([
        'external_id' => 'GO-0002',
        'alert_type' => 'saag',
        'service_mode' => 'GO Train',
        'corridor_or_route' => 'Barrie',
        'corridor_code' => 'BR',
        'sub_category' => null,
        'message_subject' => 'Earlier delay cleared',
        'message_body' => null,
        'direction' => null,
        'trip_number' => '1234',
        'delay_duration' => '00:15:00',
        'status' => 'UPD',
        'line_colour' => null,
        'posted_at' => $now->subMinutes(65),
        'feed_updated_at' => $now->subMinutes(65),
    ]);

    assertEqual(FireIncident::count(), 4, 'fire_incidents count');
    assertEqual(PoliceCall::count(), 4, 'police_calls count');
    assertEqual(TransitAlert::count(), 2, 'transit_alerts count');
    assertEqual(GoTransitAlert::count(), 2, 'go_transit_alerts count');

    $alerts = app(UnifiedAlertsQuery::class);

    logInfo('Step 2: Cursor pagination (page 1/2/3) returns deterministic batches with no duplicates');

    $criteriaPage1 = new UnifiedAlertsCriteria(status: 'all', perPage: 5);
    $page1 = $alerts->cursorPaginate($criteriaPage1);
    assertEqual(count($page1['items']), 5, 'cursor page 1 count');
    assertTrue(is_string($page1['next_cursor']) && $page1['next_cursor'] !== '', 'cursor page 1 next_cursor present');

    $page1Ids = pluckIds($page1['items']);
    logInfo('Cursor page 1 IDs', ['ids' => $page1Ids]);
    assertEqual($page1Ids, [
        'fire:FIRE-0001',
        'go_transit:GO-0001',
        'police:900001',
        'transit:api:TR-0001',
        'fire:FIRE-0002',
    ], 'cursor page 1 ordering');

    $decoded1 = UnifiedAlertsCursor::decode($page1['next_cursor']);
    assertEqual($decoded1->id, 'fire:FIRE-0002', 'cursor 1 points to last id');

    $criteriaPage2 = new UnifiedAlertsCriteria(status: 'all', perPage: 5, cursor: $page1['next_cursor']);
    $page2 = $alerts->cursorPaginate($criteriaPage2);
    assertEqual(count($page2['items']), 5, 'cursor page 2 count');
    assertTrue(is_string($page2['next_cursor']) && $page2['next_cursor'] !== '', 'cursor page 2 next_cursor present');

    $page2Ids = pluckIds($page2['items']);
    logInfo('Cursor page 2 IDs', ['ids' => $page2Ids]);
    assertEqual($page2Ids, [
        'police:900002',
        'go_transit:GO-0002',
        'fire:FIRE-0003',
        'police:900003',
        'transit:sxa:TR-0002',
    ], 'cursor page 2 ordering');

    $intersection12 = array_values(array_intersect($page1Ids, $page2Ids));
    assertEqual($intersection12, [], 'cursor pages 1 and 2 have no duplicate ids');

    $criteriaPage3 = new UnifiedAlertsCriteria(status: 'all', perPage: 5, cursor: $page2['next_cursor']);
    $page3 = $alerts->cursorPaginate($criteriaPage3);
    assertEqual(count($page3['items']), 2, 'cursor page 3 count');
    assertEqual($page3['next_cursor'], null, 'cursor page 3 next_cursor is null (end of feed)');

    $page3Ids = pluckIds($page3['items']);
    logInfo('Cursor page 3 IDs', ['ids' => $page3Ids]);
    assertEqual($page3Ids, [
        'fire:FIRE-0004',
        'police:900004',
    ], 'cursor page 3 ordering');

    $allIds = array_merge($page1Ids, $page2Ids, $page3Ids);
    assertEqual(count($allIds), 12, 'cursor pagination returns full dataset across pages');
    assertEqual(count(array_unique($allIds)), 12, 'cursor pagination returns unique ids across pages');

    logInfo('Step 3: Source filter applies across full dataset');
    $fireOnly = $alerts->cursorPaginate(new UnifiedAlertsCriteria(status: 'all', source: 'fire', perPage: 50));
    $fireOnlyIds = pluckIds($fireOnly['items']);
    assertEqual($fireOnlyIds, ['fire:FIRE-0001', 'fire:FIRE-0002', 'fire:FIRE-0003', 'fire:FIRE-0004'], 'source=fire returns only fire');

    logInfo('Step 4: Since filter constrains timestamps (since=30m)');
    $since30m = $alerts->cursorPaginate(new UnifiedAlertsCriteria(status: 'all', since: '30m', perPage: 50));
    $since30mIds = pluckIds($since30m['items']);
    assertEqual($since30mIds, [
        'fire:FIRE-0001',
        'go_transit:GO-0001',
        'police:900001',
        'transit:api:TR-0001',
        'fire:FIRE-0002',
    ], 'since=30m returns only last 30 minutes');

    logInfo('Step 5: Status filter constrains active/cleared');
    $active = $alerts->cursorPaginate(new UnifiedAlertsCriteria(status: 'active', perPage: 50));
    assertEqual(count($active['items']), 6, 'status=active count');
    $activeIds = pluckIds($active['items']);
    assertEqual($activeIds, [
        'fire:FIRE-0001',
        'go_transit:GO-0001',
        'police:900001',
        'transit:api:TR-0001',
        'fire:FIRE-0002',
        'police:900002',
    ], 'status=active ordering');

    $cleared = $alerts->cursorPaginate(new UnifiedAlertsCriteria(status: 'cleared', perPage: 50));
    assertEqual(count($cleared['items']), 6, 'status=cleared count');

    logInfo('Step 6: Search filter matches expected records (q)');
    $searchFire = $alerts->cursorPaginate(new UnifiedAlertsCriteria(status: 'all', query: $tokenFire, perPage: 50));
    assertEqual(pluckIds($searchFire['items']), ['fire:FIRE-0001'], "q={$tokenFire} returns fire match");

    $searchPolice = $alerts->cursorPaginate(new UnifiedAlertsCriteria(status: 'all', query: $tokenPolice, perPage: 50));
    assertEqual(pluckIds($searchPolice['items']), ['police:900001'], "q={$tokenPolice} returns police match");

    $searchTransit = $alerts->cursorPaginate(new UnifiedAlertsCriteria(status: 'all', query: $tokenTransit, perPage: 50));
    assertEqual(pluckIds($searchTransit['items']), ['transit:api:TR-0001'], "q={$tokenTransit} returns transit match");

    $searchGo = $alerts->cursorPaginate(new UnifiedAlertsCriteria(status: 'all', query: $tokenGo, perPage: 50));
    assertEqual(pluckIds($searchGo['items']), ['go_transit:GO-0001'], "q={$tokenGo} returns GO Transit match");

    logInfo('Step 7: Combined filter intersection (status + source + since + q)');
    $combined = $alerts->cursorPaginate(new UnifiedAlertsCriteria(
        status: 'active',
        source: 'fire',
        since: '30m',
        query: $tokenFire,
        perPage: 50,
    ));
    assertEqual(pluckIds($combined['items']), ['fire:FIRE-0001'], 'combined filters return correct intersection');

    logInfo('Step 8: Cursor validation rule fails closed for tampered cursors');
    $validator = Validator::make(
        ['cursor' => 'not-a-real-cursor'],
        ['cursor' => ['nullable', 'string', new UnifiedAlertsCursorRule]]
    );
    assertTrue($validator->fails(), 'invalid cursor fails validation');

    $validator2 = Validator::make(
        ['cursor' => '   '],
        ['cursor' => ['nullable', 'string', new UnifiedAlertsCursorRule]]
    );
    assertTrue(! $validator2->fails(), 'whitespace-only cursor is treated as unset');

    logInfo('=== Manual Test Completed Successfully ===');
} catch (\Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    try {
        Carbon::setTestNow();
    } catch (\Throwable) {
    }

    try {
        CarbonImmutable::setTestNow();
    } catch (\Throwable) {
    }

    if ($txStarted) {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                logInfo('Transaction rolled back (Database preserved).');
            }
        } catch (\Throwable) {
        }
    } else {
        // MySQL path: cleanup explicitly since we cannot rely on rollback.
        try {
            FireIncident::query()->delete();
            PoliceCall::query()->delete();
            TransitAlert::query()->delete();
            GoTransitAlert::query()->delete();

            logInfo('Cleanup completed (tables cleared).');
        } catch (\Throwable $cleanupException) {
            logError('Cleanup failed', [
                'message' => $cleanupException->getMessage(),
                'trace' => $cleanupException->getTraceAsString(),
                'driver' => $driverName,
            ]);
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
