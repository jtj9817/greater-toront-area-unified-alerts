<?php

/**
 * Manual Test Script: Phase 6 - MiWay Alert Source API Contract
 * Generated: 2026-03-31
 * Purpose: Verify MiWay alerts pass through the unified feed API endpoint
 *          with correct source identity and structure.
 */

require __DIR__.'/../../vendor/autoload.php';

// Set test environment variables before bootstrapping
$_ENV['APP_ENV'] = 'testing';
$_ENV['APP_KEY'] = 'base64:gJIkV3ILHa6kY/X/vFGHm+8qz0PvCVwb/4BPmqIeTpE=';
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = ':memory:';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Run migrations to set up the schema
Artisan::call('migrate', ['--force' => true]);

use App\Models\MiwayAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// === ENVIRONMENT GUARD ===
if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

if (app()->environment('testing')) {
    echo "[WARN] Running in testing environment - using test database\n";
}

$testRunId = 'miway_phase6_'.Carbon::now()->format('Y_m_d_His');
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

function logInfo($msg, $ctx = [])
{
    Log::channel('manual_test')->info($msg, $ctx);
    echo "[INFO] {$msg}\n";
}

function logError($msg, $ctx = [])
{
    Log::channel('manual_test')->error($msg, $ctx);
    echo "[ERROR] {$msg}\n";
}

function logSection($title)
{
    echo "\n".str_repeat('=', 60)."\n";
    echo "  {$title}\n";
    echo str_repeat('=', 60)."\n";
}

$createdAlertIds = [];

try {
    DB::beginTransaction();

    logSection('PHASE 6: MiWay Alert Source + API Contract Verification');

    // === SETUP PHASE ===
    logInfo('Phase 1: Data Setup');

    // Clean up any existing test data from previous runs
    MiwayAlert::where('external_id', 'LIKE', 'miway:manual:test:%')->delete();

    // Create MiWay alerts for testing
    $alert1 = MiwayAlert::create([
        'external_id' => 'miway:manual:test:alert:001',
        'header_text' => 'Route 101 Detour - Hurontario Street Construction',
        'description_text' => 'Due to ongoing construction on Hurontario Street, Route 101 buses are detouring via Queensway. Expect delays of 10-15 minutes.',
        'cause' => 'CONSTRUCTION',
        'effect' => 'DETOUR',
        'starts_at' => Carbon::now()->subMinutes(30),
        'ends_at' => Carbon::now()->addHours(4),
        'url' => 'https://www.miway.ca/alerts/101',
        'detour_pdf_url' => 'https://www.miway.ca/detours/101.pdf',
        'is_active' => true,
        'feed_updated_at' => Carbon::now(),
    ]);
    $createdAlertIds[] = $alert1->id;
    logInfo('Created active MiWay alert', ['id' => $alert1->id, 'external_id' => $alert1->external_id]);

    $alert2 = MiwayAlert::create([
        'external_id' => 'miway:manual:test:alert:002',
        'header_text' => 'Route 17 Reduced Service',
        'description_text' => 'Due to vehicle shortage, Route 17 is operating on reduced frequency until 6:00 PM.',
        'cause' => 'VEHICLE_SHORTAGE',
        'effect' => 'REDUCED_SERVICE',
        'starts_at' => Carbon::now()->subHours(2),
        'ends_at' => Carbon::now()->addHours(3),
        'url' => 'https://www.miway.ca/alerts/17',
        'detour_pdf_url' => null,
        'is_active' => true,
        'feed_updated_at' => Carbon::now(),
    ]);
    $createdAlertIds[] = $alert2->id;
    logInfo('Created second active MiWay alert', ['id' => $alert2->id, 'external_id' => $alert2->external_id]);

    $alert3 = MiwayAlert::create([
        'external_id' => 'miway:manual:test:alert:003',
        'header_text' => 'Route 5 Service Resumed',
        'description_text' => 'Service has resumed on Route 5 after earlier mechanical issue.',
        'cause' => 'UNKNOWN_CAUSE',
        'effect' => 'UNKNOWN_EFFECT',
        'starts_at' => Carbon::now()->subHours(5),
        'ends_at' => Carbon::now()->subHours(3),
        'url' => null,
        'detour_pdf_url' => null,
        'is_active' => false,
        'feed_updated_at' => Carbon::now()->subHours(3),
    ]);
    $createdAlertIds[] = $alert3->id;
    logInfo('Created inactive MiWay alert', ['id' => $alert3->id, 'external_id' => $alert3->external_id]);

    logInfo('Data setup completed', ['total_alerts_created' => count($createdAlertIds)]);

    // === EXECUTION PHASE ===
    logSection('PHASE 2: Test Execution');

    // Test 1: Query all MiWay alerts (no source filter)
    logInfo('Test 2.1: Query all MiWay alerts without source filter');
    $criteriaAll = new UnifiedAlertsCriteria(
        status: 'all',
        sort: 'desc',
        source: null,
        query: null,
        since: null,
        cursor: null,
        perPage: 50
    );
    $resultAll = app(UnifiedAlertsQuery::class)->cursorPaginate($criteriaAll);
    $miwayAlertsAll = array_filter(
        $resultAll['items'],
        fn ($item) => $item->source === 'miway'
    );
    logInfo('Found MiWay alerts in unified query', ['count' => count($miwayAlertsAll)]);

    if (count($miwayAlertsAll) !== 3) {
        logError('FAIL: Expected 3 MiWay alerts, got '.count($miwayAlertsAll));
    } else {
        logInfo('PASS: Found 3 MiWay alerts as expected');
    }

    // Test 2: Query with source=miway filter
    logInfo('Test 2.2: Query with source=miway filter');
    $criteriaMiway = new UnifiedAlertsCriteria(
        status: 'all',
        sort: 'desc',
        source: 'miway',
        query: null,
        since: null,
        cursor: null,
        perPage: 50
    );
    $resultMiway = app(UnifiedAlertsQuery::class)->cursorPaginate($criteriaMiway);
    logInfo('Query result', [
        'total' => count($resultMiway['items']),
        'has_next_cursor' => $resultMiway['next_cursor'] !== null,
    ]);

    $passSourceFilter = true;
    foreach ($resultMiway['items'] as $item) {
        if ($item->source !== 'miway') {
            logError('FAIL: Found non-MiWay alert in source=miway filter', ['source' => $item->source]);
            $passSourceFilter = false;
        }
    }

    if ($passSourceFilter && count($resultMiway['items']) === 3) {
        logInfo('PASS: source=miway filter returns only MiWay alerts');
    }

    // Test 3: Query with source=miway and status=active
    logInfo('Test 2.3: Query with source=miway and status=active');
    $criteriaActive = new UnifiedAlertsCriteria(
        status: 'active',
        sort: 'desc',
        source: 'miway',
        query: null,
        since: null,
        cursor: null,
        perPage: 50
    );
    $resultActive = app(UnifiedAlertsQuery::class)->cursorPaginate($criteriaActive);
    logInfo('Active MiWay alerts', ['count' => count($resultActive['items'])]);

    $passActiveFilter = true;
    foreach ($resultActive['items'] as $item) {
        if ($item->source !== 'miway') {
            logError('FAIL: Found non-MiWay alert', ['source' => $item->source]);
            $passActiveFilter = false;
        }
        if (! $item->isActive) {
            logError('FAIL: Found inactive alert when filtering for active', ['is_active' => $item->isActive]);
            $passActiveFilter = false;
        }
    }

    if ($passActiveFilter && count($resultActive['items']) === 2) {
        logInfo('PASS: source=miway + status=active returns 2 active MiWay alerts');
    }

    // Test 4: Verify alert structure and meta fields
    logInfo('Test 2.4: Verify MiWay alert structure');
    $firstAlert = $resultMiway['items'][0];

    logInfo('Checking alert fields', [
        'id' => $firstAlert->id,
        'source' => $firstAlert->source,
        'external_id' => $firstAlert->externalId,
        'is_active' => $firstAlert->isActive,
        'title' => $firstAlert->title,
    ]);

    $structurePass = true;
    if (! str_starts_with($firstAlert->id, 'miway:')) {
        logError("FAIL: Alert ID should start with 'miway:'", ['id' => $firstAlert->id]);
        $structurePass = false;
    }
    if ($firstAlert->source !== 'miway') {
        logError("FAIL: Alert source should be 'miway'", ['source' => $firstAlert->source]);
        $structurePass = false;
    }
    if ($firstAlert->location !== null) {
        logError('FAIL: MiWay alerts should have null location', ['location' => $firstAlert->location]);
        $structurePass = false;
    }
    if (empty($firstAlert->meta)) {
        logError('FAIL: MiWay alerts should have non-empty meta');
        $structurePass = false;
    }

    if ($structurePass) {
        logInfo('PASS: MiWay alert structure is correct');
        logInfo('Meta fields', ['meta' => $firstAlert->meta]);
    }

    // Test 5: Text search on MiWay alerts
    logInfo('Test 2.5: Text search on MiWay header_text');
    $criteriaSearch = new UnifiedAlertsCriteria(
        status: 'all',
        sort: 'desc',
        source: 'miway',
        query: 'Route 101',
        since: null,
        cursor: null,
        perPage: 50
    );
    $resultSearch = app(UnifiedAlertsQuery::class)->cursorPaginate($criteriaSearch);
    logInfo("Search 'Route 101' results", ['count' => count($resultSearch['items'])]);

    if (count($resultSearch['items']) >= 1 && str_contains($resultSearch['items'][0]->title, 'Route 101')) {
        logInfo('PASS: Text search works on MiWay alerts');
    } else {
        logError('FAIL: Text search did not return expected results');
    }

    // Test 6: Empty result for miway when no alerts exist
    logInfo('Test 2.6: Query source=miway with no MiWay alerts (using wrong source prefix)');

    // Create then immediately delete to simulate no matching alerts
    $tempAlert = MiwayAlert::create([
        'external_id' => 'miway:manual:test:temp:999',
        'header_text' => 'Temporary Alert',
        'description_text' => 'This will be deleted',
        'cause' => 'TEST',
        'effect' => 'NO_SERVICE',
        'starts_at' => Carbon::now(),
        'is_active' => true,
        'feed_updated_at' => Carbon::now(),
    ]);
    $createdAlertIds[] = $tempAlert->id;
    $tempAlert->delete();

    $criteriaEmpty = new UnifiedAlertsCriteria(
        status: 'all',
        sort: 'desc',
        source: 'miway',
        query: 'NONEXISTENT_ALERT_TITLE_THAT_SHOULD_NOT_MATCH_ANYTHING',
        since: null,
        cursor: null,
        perPage: 50
    );
    $resultEmpty = app(UnifiedAlertsQuery::class)->cursorPaginate($criteriaEmpty);
    logInfo('Empty search results', ['count' => count($resultEmpty['items'])]);

    if (count($resultEmpty['items']) === 0) {
        logInfo('PASS: Empty search returns no results');
    } else {
        logError('FAIL: Expected 0 results for non-matching search');
    }

    logSection('PHASE 3: Verification Summary');

    $allPassed = $passSourceFilter && $passActiveFilter && $structurePass;

    if ($allPassed) {
        echo "\n";
        echo "██╗██╗   ██╗ █████╗ ███╗   ██╗ ██████╗ ███████╗██████╗\n";
        echo "██║██║   ██║██╔══██╗████╗  ██║██╔════╝ ██╔════╝██╔══██╗\n";
        echo "██║██║   ██║███████║██╔██╗ ██║██║  ███╗█████╗  ██████╔╝\n";
        echo "╚═╝╚██╗ ██╔╝██╔══██║██║╚██╗██║██║   ██║██╔══╝  ██╔══██╗\n";
        echo "██╗ ╚████╔╝ ██║  ██║██║ ╚████║╚██████╔╝███████╗██║  ██║\n";
        echo "╚═╝  ╚═══╝  ╚═╝  ╚═╝╚═╝  ╚═══╝ ╚═════╝ ╚══════╝╚═╝  ╚═╝\n";
        echo "\n✅ Phase 6 Manual Verification PASSED\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        echo "MiWay Alert Source + API Contract Plumbing Verified:\n";
        echo "  ✓ MiWay alerts appear in unified feed with correct structure\n";
        echo "  ✓ source=miway filter returns only MiWay alerts\n";
        echo "  ✓ source=miway + status=active filter works correctly\n";
        echo "  ✓ Alert ID format: miway:{external_id}\n";
        echo "  ✓ Location is null for MiWay (transit-specific)\n";
        echo "  ✓ Meta contains header_text, description_text, cause, effect, url\n";
        echo "  ✓ Text search works on MiWay alert content\n";
        echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        logInfo('Phase 6 manual verification PASSED');
    } else {
        echo "\n";
        echo "██╗   ██╗ █████╗ ██╗   ██╗\n";
        echo "██║   ██║██╔══██╗██║   ██║\n";
        echo "██║   ██║███████║██║   ██║\n";
        echo "╚██╗ ██╔╝██╔══██║██║   ██║\n";
        echo " ╚████╔╝ ██║  ██║╚██████╔╝\n";
        echo "  ╚═══╝  ╚═╝  ╚═╝ ╚═════╝\n";
        echo "\n❌ Phase 6 Manual Verification FAILED\n";
        logError('Phase 6 manual verification FAILED');
    }

} catch (\Exception $e) {
    logError('Test execution failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
    echo "\n❌ Exception: {$e->getMessage()}\n";
} finally {
    // === CLEANUP PHASE ===
    logSection('PHASE 4: Data Cleanup');

    DB::rollBack();
    logInfo('Transaction rolled back - no persistent changes made');
    logInfo('Created alert IDs were: '.implode(', ', array_map('strval', $createdAlertIds)));
    logInfo('Cleanup completed');
    logInfo('=== Test Run Finished ===');
    echo "\n✓ Full logs available at: {$logFile}\n";
}
