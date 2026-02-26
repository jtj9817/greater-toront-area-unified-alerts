<?php

/**
 * Manual Test: FEED-010 Phase 3 Provider Refactors (Search + Scene Intel Meta)
 * Purpose: Verify pgsql provider search (FTS + ILIKE fallback) and fire Scene Intel meta invariants.
 */

use App\Enums\IncidentUpdateType;
use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\IncidentUpdate;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Providers\FireAlertSelectProvider;
use App\Services\Alerts\Providers\GoTransitAlertSelectProvider;
use App\Services\Alerts\Providers\PoliceAlertSelectProvider;
use App\Services\Alerts\Providers\TransitAlertSelectProvider;
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

$testRunId = 'feed_010_phase_3_provider_refactors_search_scene_intel_meta_'.Carbon::now()->format('Y_m_d_His');
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

function decodeMeta(mixed $meta): array
{
    if (is_array($meta)) {
        return $meta;
    }

    if (! is_string($meta) || $meta === '') {
        throw new RuntimeException('Expected meta to be a non-empty JSON string or array.');
    }

    $decoded = json_decode($meta, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException('Decoded meta must be an array.');
    }

    return $decoded;
}

function assertSearchReturnsExternalId(object $provider, string $query, string $expectedExternalId): void
{
    $rows = $provider->select(new UnifiedAlertsCriteria(status: 'all', query: $query, perPage: 50))->get();
    $ids = collect($rows)->pluck('external_id')->map(static fn ($id) => (string) $id)->all();

    assertTrue(in_array($expectedExternalId, $ids, true), "q={$query} returns {$expectedExternalId}", ['ids' => $ids]);
}

function assertSearchReturnsNoRows(object $provider, string $query): void
{
    $rows = $provider->select(new UnifiedAlertsCriteria(status: 'all', query: $query, perPage: 50))->get();
    assertTrue($rows->isEmpty(), "q={$query} returns no rows");
}

$exitCode = 0;
$txStarted = false;

try {
    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        throw new RuntimeException(
            'Database connection failed. If using Sail, run: ./vendor/bin/sail php tests/manual/verify_feed_010_phase_3_provider_refactors_search_scene_intel_meta.php',
            previous: $e,
        );
    }

    if (DB::getDriverName() !== 'pgsql') {
        throw new RuntimeException('This manual test requires DB_CONNECTION=pgsql.');
    }

    logInfo('Running with testing database', ['connection' => $connection, 'database' => $currentDatabase]);

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: FEED-010 Phase 3 Provider Refactors (Search + Scene Intel Meta) ===');

    FireIncident::query()->delete();
    IncidentUpdate::query()->delete();
    PoliceCall::query()->delete();
    TransitAlert::query()->delete();
    GoTransitAlert::query()->delete();

    $baseTime = CarbonImmutable::parse('2026-02-26 12:00:00', 'UTC');

    FireIncident::factory()->create([
        'event_num' => 'F-PGSQL-FTS',
        'event_type' => 'ALARM FTSFIRETOKEN',
        'prime_street' => 'HarbourfrontTerminal',
        'cross_streets' => 'QueensQuayWest',
        'dispatch_time' => $baseTime,
        'is_active' => true,
    ]);

    FireIncident::factory()->create([
        'event_num' => 'F-PGSQL-NO-UPDATES',
        'event_type' => 'MEDICAL ASSIST',
        'prime_street' => 'KingStreetWest',
        'cross_streets' => 'SpadinaAvenue',
        'dispatch_time' => $baseTime->subMinutes(10),
        'is_active' => true,
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => 'F-PGSQL-FTS',
        'update_type' => IncidentUpdateType::RESOURCE_STATUS,
        'content' => 'Units staged',
        'created_at' => $baseTime->addMinutes(2),
        'updated_at' => $baseTime->addMinutes(2),
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => 'F-PGSQL-FTS',
        'update_type' => IncidentUpdateType::ALARM_CHANGE,
        'content' => 'Alarm raised',
        'created_at' => $baseTime->addMinutes(5),
        'updated_at' => $baseTime->addMinutes(5),
    ]);

    PoliceCall::factory()->create([
        'object_id' => 810001,
        'call_type' => 'ASSAULT FTSPOLICETOKEN',
        'cross_streets' => 'QueenBroadwayJunction',
        'occurrence_time' => $baseTime->addMinutes(1),
        'is_active' => true,
    ]);

    TransitAlert::factory()->create([
        'external_id' => 'ttc:pgsql:search-1',
        'title' => 'FTSTRANSITTOKEN line delay',
        'description' => 'Service issue at LakeshoreBranchHub',
        'route' => '501',
        'route_type' => 'Streetcar',
        'stop_start' => 'LongBranch',
        'stop_end' => 'HumberLoop',
        'active_period_start' => $baseTime->addMinutes(3),
        'is_active' => true,
    ]);

    GoTransitAlert::factory()->create([
        'external_id' => 'go:pgsql:search-1',
        'message_subject' => 'FTSGOTOKEN advisory',
        'message_body' => 'Train reroute via MeadowvaleConnector',
        'corridor_or_route' => 'LakeshoreWest',
        'corridor_code' => 'LW',
        'service_mode' => 'GO Train',
        'posted_at' => $baseTime->addMinutes(4),
        'is_active' => true,
    ]);

    logInfo('Step 1: Verify pgsql FTS query terms return matching rows');
    assertSearchReturnsExternalId(new FireAlertSelectProvider, 'FTSFIRETOKEN', 'F-PGSQL-FTS');
    assertSearchReturnsExternalId(new PoliceAlertSelectProvider, 'FTSPOLICETOKEN', '810001');
    assertSearchReturnsExternalId(new TransitAlertSelectProvider, 'FTSTRANSITTOKEN', 'ttc:pgsql:search-1');
    assertSearchReturnsExternalId(new GoTransitAlertSelectProvider, 'FTSGOTOKEN', 'go:pgsql:search-1');

    logInfo('Step 2: Verify missing terms return no rows (search reduction)');
    assertSearchReturnsNoRows(new FireAlertSelectProvider, 'NO_MATCH_TOKEN_1234');
    assertSearchReturnsNoRows(new PoliceAlertSelectProvider, 'NO_MATCH_TOKEN_1234');
    assertSearchReturnsNoRows(new TransitAlertSelectProvider, 'NO_MATCH_TOKEN_1234');
    assertSearchReturnsNoRows(new GoTransitAlertSelectProvider, 'NO_MATCH_TOKEN_1234');

    logInfo('Step 3: Verify ILIKE substring fallback matches partial terms');
    assertSearchReturnsExternalId(new FireAlertSelectProvider, 'frontterm', 'F-PGSQL-FTS');
    assertSearchReturnsExternalId(new PoliceAlertSelectProvider, 'broadwayjun', '810001');
    assertSearchReturnsExternalId(new TransitAlertSelectProvider, 'shorebranch', 'ttc:pgsql:search-1');
    assertSearchReturnsExternalId(new GoTransitAlertSelectProvider, 'valeconn', 'go:pgsql:search-1');

    logInfo('Step 4: Verify fire meta.intel_summary is always an array and never null');
    $fireRows = (new FireAlertSelectProvider)->select(new UnifiedAlertsCriteria(status: 'all', perPage: 50))->get();
    assertTrue($fireRows->count() >= 2, 'fire provider returned seeded rows');

    $isoTimestampPattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/';

    foreach ($fireRows as $row) {
        $meta = decodeMeta($row->meta);
        assertTrue(array_key_exists('intel_summary', $meta), 'intel_summary key exists', ['event_num' => (string) $row->external_id]);
        assertTrue(is_array($meta['intel_summary']), 'intel_summary is array', ['event_num' => (string) $row->external_id]);

        if ((string) $row->external_id === 'F-PGSQL-NO-UPDATES') {
            assertTrue($meta['intel_summary'] === [], 'intel_summary empty array when no updates');
            assertTrue(($meta['intel_last_updated'] ?? null) === null, 'intel_last_updated is null when no updates');
        }

        if ((string) $row->external_id === 'F-PGSQL-FTS') {
            assertTrue(($meta['intel_last_updated'] ?? null) !== null, 'intel_last_updated exists when updates present');
            assertTrue(
                is_string($meta['intel_last_updated']) && preg_match($isoTimestampPattern, $meta['intel_last_updated']) === 1,
                'intel_last_updated has ISO-8601 offset format',
                ['value' => $meta['intel_last_updated']],
            );

            assertTrue(count($meta['intel_summary']) >= 1, 'intel_summary contains update entries');
            foreach ($meta['intel_summary'] as $entry) {
                $timestamp = $entry['timestamp'] ?? null;
                assertTrue(
                    is_string($timestamp) && preg_match($isoTimestampPattern, $timestamp) === 1,
                    'intel_summary item timestamp has ISO-8601 offset format',
                    ['timestamp' => $timestamp],
                );
            }
        }
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
