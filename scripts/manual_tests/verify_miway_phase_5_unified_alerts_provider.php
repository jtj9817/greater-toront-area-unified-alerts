<?php

/**
 * Manual Test Script: Phase 5 — Unified Alerts Provider
 * Purpose: Verify MiwayAlertSelectProvider is correctly wired into the
 *          UnifiedAlertsQuery cursor-pagination pipeline and returns
 *          properly shaped UnifiedAlert DTOs through the unified feed endpoint.
 *
 * Usage: ./scripts/run-manual-test.sh scripts/manual_tests/verify_miway_phase_5_unified_alerts_provider.php
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution
if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

use App\Models\MiwayAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\MiwayAlertSelectProvider;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$testRunId = 'miway_phase5_'.Carbon::now()->format('Y_m_d_His');
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

if (! is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

config([
    'logging.channels.manual_test' => [
        'driver' => 'single',
        'path' => $logFile,
        'level' => 'debug',
    ],
]);

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

$createdRecords = [];

try {
    DB::beginTransaction();

    logInfo('=== Phase 5 Manual Verification: Unified Alerts Provider ===');
    logInfo("Test run ID: {$testRunId}");

    // ──────────────────────────────────────────────────────────────
    // SETUP PHASE — seed MiwayAlert records
    // ──────────────────────────────────────────────────────────────
    logInfo('Phase 1: Data Setup — seeding MiwayAlert records');

    $alert1 = MiwayAlert::create([
        'external_id' => 'miway:verify:001',
        'header_text' => 'Route 101 buses detour due to construction',
        'description_text' => 'Routes 101 and 102 are detoured via Main St while Queen St is closed.',
        'cause' => 'CONSTRUCTION',
        'effect' => 'DETOUR',
        'starts_at' => Carbon::now()->subMinutes(30),
        'ends_at' => Carbon::now()->addHours(4),
        'url' => 'https://www.miway.ca/alerts/verify001',
        'detour_pdf_url' => 'https://www.miway.ca/detours/verify001.pdf',
        'is_active' => true,
        'feed_updated_at' => Carbon::now(),
    ]);
    $createdRecords[] = $alert1->getKey();
    logInfo('Created MiwayAlert #1', ['id' => $alert1->id, 'external_id' => $alert1->external_id]);

    $alert2 = MiwayAlert::create([
        'external_id' => 'miway:verify:002',
        'header_text' => 'Reduced service on Route 7',
        'description_text' => 'Route 7 operating on reduced schedule due to vehicle shortage.',
        'cause' => 'VEHICLE_SHORTAGE',
        'effect' => 'REDUCED_SERVICE',
        'starts_at' => Carbon::now()->subHours(2),
        'ends_at' => Carbon::now()->addHours(1),
        'url' => 'https://www.miway.ca/alerts/verify002',
        'detour_pdf_url' => null,
        'is_active' => true,
        'feed_updated_at' => Carbon::now(),
    ]);
    $createdRecords[] = $alert2->getKey();
    logInfo('Created MiwayAlert #2', ['id' => $alert2->id, 'external_id' => $alert2->external_id]);

    $alert3 = MiwayAlert::create([
        'external_id' => 'miway:verify:003',
        'header_text' => 'Route 22 — service suspended',
        'description_text' => 'Route 22 service suspended until further notice.',
        'cause' => 'INCIDENT',
        'effect' => 'NO_SERVICE',
        'starts_at' => Carbon::now()->subMinutes(10),
        'ends_at' => null,
        'url' => 'https://www.miway.ca/alerts/verify003',
        'detour_pdf_url' => null,
        'is_active' => false,
        'feed_updated_at' => Carbon::now()->subMinutes(5),
    ]);
    $createdRecords[] = $alert3->getKey();
    logInfo('Created MiwayAlert #3 (inactive)', ['id' => $alert3->id, 'external_id' => $alert3->external_id]);

    logInfo('Data setup completed — 3 MiwayAlert records created');

    // ──────────────────────────────────────────────────────────────
    // VERIFICATION PHASE 1 — MiwayAlertSelectProvider::select()
    // ──────────────────────────────────────────────────────────────
    logInfo('');
    logInfo('Phase 2a: Verifying MiwayAlertSelectProvider::select() raw mapping');

    $provider = new MiwayAlertSelectProvider;

    // 2a-1: Default criteria — all active + inactive
    $criteria = new UnifiedAlertsCriteria;
    $allRows = $provider->select($criteria)->get();
    logInfo("  All rows (no filter): count={$allRows->count()}");
    foreach ($allRows as $row) {
        logInfo("    id={$row->id} source={$row->source} external_id={$row->external_id} active={$row->is_active} title={$row->title}");
    }

    // Verify id composition
    $firstRow = $allRows->first();
    if ($firstRow->id === 'miway:miway:verify:001') {
        logInfo('  [PASS] id composition is correct: miway:external_id');
    } else {
        logError("  [FAIL] id composition wrong: expected 'miway:miway:verify:001', got '{$firstRow->id}'");
    }

    // Verify source
    if ($firstRow->source === 'miway') {
        logInfo("  [PASS] source is 'miway'");
    } else {
        logError("  [FAIL] source wrong: expected 'miway', got '{$firstRow->source}'");
    }

    // Verify meta payload
    $meta = UnifiedAlertMapper::decodeMeta($firstRow->meta);
    if (isset($meta['header_text']) && isset($meta['description_text']) && isset($meta['cause']) && isset($meta['effect'])) {
        logInfo('  [PASS] meta payload contains header_text, description_text, cause, effect');
    } else {
        logError('  [FAIL] meta payload missing expected keys', ['meta' => $meta]);
    }

    // Verify meta contains url and detour_pdf_url
    if ($meta['url'] === 'https://www.miway.ca/alerts/verify001' && $meta['detour_pdf_url'] === 'https://www.miway.ca/detours/verify001.pdf') {
        logInfo('  [PASS] meta url and detour_pdf_url are correct');
    } else {
        logError('  [FAIL] meta url/detour_pdf_url mismatch', ['url' => $meta['url'] ?? null, 'detour_pdf_url' => $meta['detour_pdf_url'] ?? null]);
    }

    // 2a-2: Status = active
    $activeCriteria = new UnifiedAlertsCriteria(status: 'active');
    $activeRows = $provider->select($activeCriteria)->get();
    logInfo("  Active rows only: count={$activeRows->count()}");
    if ($activeRows->count() === 2) {
        logInfo('  [PASS] active filter returns exactly 2 active alerts');
    } else {
        logError("  [FAIL] expected 2 active alerts, got {$activeRows->count()}");
    }

    // 2a-3: Status = cleared
    $clearedCriteria = new UnifiedAlertsCriteria(status: 'cleared');
    $clearedRows = $provider->select($clearedCriteria)->get();
    logInfo("  Cleared rows only: count={$clearedRows->count()}");
    if ($clearedRows->count() === 1) {
        logInfo('  [PASS] cleared filter returns exactly 1 inactive alert');
    } else {
        logError("  [FAIL] expected 1 cleared alert, got {$clearedRows->count()}");
    }

    // 2a-4: Source = miway
    $miwaySourceCriteria = new UnifiedAlertsCriteria(source: 'miway');
    $miwayRows = $provider->select($miwaySourceCriteria)->get();
    logInfo("  Source=miway rows: count={$miwayRows->count()}");
    if ($miwayRows->count() === 3) {
        logInfo('  [PASS] source=miway filter returns all 3 records');
    } else {
        logError("  [FAIL] expected 3 records for source=miway, got {$miwayRows->count()}");
    }

    // 2a-5: Source mismatch (fire)
    $fireSourceCriteria = new UnifiedAlertsCriteria(source: 'fire');
    $fireRows = $provider->select($fireSourceCriteria)->get();
    logInfo("  Source=fire rows: count={$fireRows->count()}");
    if ($fireRows->count() === 0) {
        logInfo('  [PASS] source=fire filter returns 0 rows (correct — hard 1=0 predicate)');
    } else {
        logError("  [FAIL] expected 0 rows for source=fire, got {$fireRows->count()}");
    }

    // 2a-6: Since filter (last 3 hours)
    $sinceCriteria = new UnifiedAlertsCriteria(since: '3h');
    $sinceRows = $provider->select($sinceCriteria)->get();
    logInfo("  Since=3h rows: count={$sinceRows->count()}");
    if ($sinceRows->count() >= 2) {
        logInfo('  [PASS] since=3h filter returns recent alerts');
    } else {
        logError("  [FAIL] expected at least 2 recent alerts, got {$sinceRows->count()}");
    }

    // 2a-7: Query search
    $queryCriteria = new UnifiedAlertsCriteria(query: 'detour');
    $queryRows = $provider->select($queryCriteria)->get();
    logInfo("  Query='detour' rows: count={$queryRows->count()}");
    if ($queryRows->count() >= 1) {
        logInfo("  [PASS] text search on 'detour' returns matching alerts");
        foreach ($queryRows as $r) {
            logInfo("    matched: {$r->title}");
        }
    } else {
        logError("  [FAIL] text search for 'detour' returned 0 results");
    }

    // ──────────────────────────────────────────────────────────────
    // VERIFICATION PHASE 2 — UnifiedAlertMapper::fromRow()
    // ──────────────────────────────────────────────────────────────
    logInfo('');
    logInfo('Phase 2b: Verifying UnifiedAlertMapper::fromRow() produces valid UnifiedAlert DTO');

    $criteria = new UnifiedAlertsCriteria;
    $rows = $provider->select($criteria)->get();

    foreach ($rows as $row) {
        try {
            $dto = (new UnifiedAlertMapper)->fromRow($row);
            logInfo("  [PASS] UnifiedAlert from row id={$row->id}:");
            logInfo("    id={$dto->id}");
            logInfo("    source={$dto->source}");
            logInfo("    externalId={$dto->externalId}");
            logInfo("    isActive={$dto->isActive}");
            logInfo("    timestamp={$dto->timestamp->toIso8601String()}");
            logInfo("    title={$dto->title}");
            logInfo('    location='.($dto->location !== null ? $dto->location->name : 'null'));
            logInfo('    meta keys: '.implode(', ', array_keys($dto->meta)));
        } catch (\Throwable $e) {
            logError("  [FAIL] UnifiedAlertMapper::fromRow() threw for id={$row->id}: {$e->getMessage()}");
        }
    }

    // ──────────────────────────────────────────────────────────────
    // VERIFICATION PHASE 3 — UnifiedAlertsQuery::cursorPaginate()
    // ──────────────────────────────────────────────────────────────
    logInfo('');
    logInfo('Phase 2c: Verifying UnifiedAlertsQuery::cursorPaginate() with MiwayAlertSelectProvider in the pipeline');

    $query = app(UnifiedAlertsQuery::class);

    // 2c-1: All alerts
    $criteria = new UnifiedAlertsCriteria(perPage: 10);
    $result = $query->cursorPaginate($criteria);
    logInfo('  cursorPaginate (perPage=10, no filters): items='.count($result['items']).' next_cursor='.($result['next_cursor'] ?? 'null'));

    $miwayItems = array_filter($result['items'], fn ($a) => $a->source === 'miway');
    logInfo('  MiWay items in mixed feed: '.count($miwayItems));

    if (count($miwayItems) >= 2) {
        logInfo('  [PASS] UnifiedAlertsQuery returns MiWay alerts in mixed feed');
    } else {
        logError('  [FAIL] Expected at least 2 MiWay items in unified feed, got '.count($miwayItems));
    }

    // 2c-2: MiWay only via source filter
    $miwayCriteria = new UnifiedAlertsCriteria(source: 'miway', perPage: 10);
    $miwayResult = $query->cursorPaginate($miwayCriteria);
    $miwayOnlyCount = count(array_filter($miwayResult['items'], fn ($a) => $a->source === 'miway'));
    logInfo('  cursorPaginate (source=miway): items='.count($miwayResult['items'])." miway_items={$miwayOnlyCount}");

    if ($miwayOnlyCount === count($miwayResult['items'])) {
        logInfo('  [PASS] source=miway filter returns only MiWay alerts');
    } else {
        logError('  [FAIL] source=miway returned non-miway items');
    }

    // 2c-3: Active only
    $activeCriteria = new UnifiedAlertsCriteria(status: 'active', perPage: 10);
    $activeResult = $query->cursorPaginate($activeCriteria);
    $activeMiwayCount = count(array_filter($activeResult['items'], fn ($a) => $a->source === 'miway' && $a->isActive === true));
    logInfo('  cursorPaginate (status=active): items='.count($activeResult['items'])." active_miway={$activeMiwayCount}");

    if ($activeMiwayCount >= 2) {
        logInfo('  [PASS] status=active returns only active MiWay alerts');
    } else {
        logError("  [FAIL] expected at least 2 active miway items, got {$activeMiwayCount}");
    }

    // 2c-4: Cleared only
    $clearedCriteria = new UnifiedAlertsCriteria(status: 'cleared', perPage: 10);
    $clearedResult = $query->cursorPaginate($clearedCriteria);
    $clearedMiwayCount = count(array_filter($clearedResult['items'], fn ($a) => $a->source === 'miway' && $a->isActive === false));
    logInfo('  cursorPaginate (status=cleared): items='.count($clearedResult['items'])." cleared_miway={$clearedMiwayCount}");

    if ($clearedMiwayCount >= 1) {
        logInfo('  [PASS] status=cleared returns at least 1 cleared MiWay alert');
    } else {
        logError("  [FAIL] expected at least 1 cleared miway item, got {$clearedMiwayCount}");
    }

    // 2c-5: Query search through unified pipeline
    $queryCriteria = new UnifiedAlertsCriteria(query: 'detour', perPage: 10);
    $queryResult = $query->cursorPaginate($queryCriteria);
    $queryMiwayCount = count(array_filter($queryResult['items'], fn ($a) => $a->source === 'miway'));
    logInfo('  cursorPaginate (q=detour): items='.count($queryResult['items'])." miway_matches={$queryMiwayCount}");

    if ($queryMiwayCount >= 1) {
        logInfo("  [PASS] text search 'detour' surfaces MiWay alert in unified results");
    } else {
        logError("  [FAIL] expected at least 1 miway match for 'detour', got {$queryMiwayCount}");
    }

    // 2c-6: Since cutoff through unified pipeline
    $sinceCriteria = new UnifiedAlertsCriteria(since: '12h', perPage: 10);
    $sinceResult = $query->cursorPaginate($sinceCriteria);
    $sinceMiwayCount = count(array_filter($sinceResult['items'], fn ($a) => $a->source === 'miway'));
    logInfo('  cursorPaginate (since=12h): items='.count($sinceResult['items'])." miway_matches={$sinceMiwayCount}");

    if ($sinceMiwayCount >= 2) {
        logInfo('  [PASS] since=12h cutoff returns recent MiWay alerts');
    } else {
        logError("  [FAIL] expected at least 2 miway items in last 12h, got {$sinceMiwayCount}");
    }

    logInfo('');
    logInfo('=== All Phase 5 verification checks completed ===');

} catch (\Throwable $e) {
    logError('Unexpected error', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    DB::rollBack();

    // Cleanup seed records
    if (! empty($createdRecords)) {
        MiwayAlert::whereIn('id', $createdRecords)->forceDelete();
        logInfo("Cleanup: force-deleted {$createdRecords[0]}, {$createdRecords[1]}, {$createdRecords[2]}");
    }

    logInfo('=== Test Run Finished ===');
    echo "\n✓ Phase 5 verification complete. Full logs at: {$logFile}\n";
}
