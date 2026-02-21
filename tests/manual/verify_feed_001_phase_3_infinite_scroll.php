<?php

/**
 * Manual Test: FEED-001 - Phase 3 Infinite Scroll (Cursor-Based)
 * Generated: 2026-02-21
 *
 * Purpose:
 * - Verify cursor-based infinite scroll appends batches without duplicates
 * - Verify IntersectionObserver triggers loading at scroll threshold
 * - Verify next_cursor properly fetches subsequent batches
 * - Verify filter changes reset the list and cursor
 * - Verify "no more results" state when cursor is null
 * - Verify concurrent request prevention (double-fetch guard)
 *
 * Run:
 * - ./vendor/bin/sail php tests/manual/verify_feed_001_phase_3_infinite_scroll.php
 *
 * Prerequisites:
 * - Backend server running: ./vendor/bin/sail up -d
 * - Frontend dev server: ./vendor/bin/sail npm run dev (or built assets)
 * - At least 60+ alerts seeded for pagination testing
 *
 * Notes:
 * - This is a semi-automated test that also requires manual browser verification.
 * - Automated checks verify API cursor behavior and data consistency.
 * - Manual steps require browser interaction to verify UI scroll behavior.
 */

require __DIR__.'/../../vendor/autoload.php';

// Default manual verification runs to testing so Laravel loads `.env.testing`.
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}

// Some manual test environments do not provide APP_KEY in `.env.testing`.
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

use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

$testRunId = 'feed_001_phase_3_infinite_scroll_'.Carbon::now()->format('Y_m_d_His');
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

Log::info("[{$testRunId}] Starting FEED-001 Phase 3 manual verification");

// Simple assertion helpers
$passed = 0;
$failed = 0;

function assertTrue($condition, $message)
{
    global $passed, $failed, $testRunId;
    if ($condition) {
        $passed++;
        Log::info("[{$testRunId}] PASS: {$message}");
        echo "✓ {$message}\n";
    } else {
        $failed++;
        Log::error("[{$testRunId}] FAIL: {$message}");
        echo "✗ {$message}\n";
    }
}

function assertEqual($expected, $actual, $message)
{
    assertTrue($expected === $actual, "{$message} (expected: ".var_export($expected, true).', got: '.var_export($actual, true).')');
}

function logInfo($message)
{
    global $testRunId;
    Log::info("[{$testRunId}] {$message}");
    echo "→ {$message}\n";
}

// Clean up function
$cleanup = function () {
    logInfo('Cleaning up test data...');
    FireIncident::query()->delete();
    PoliceCall::query()->delete();
};

// Register cleanup on shutdown
register_shutdown_function(function () use ($cleanup, $logFileRelative, &$passed, &$failed) {
    $cleanup();
    echo "\n";
    echo "========================================\n";
    echo "Phase 3 Infinite Scroll Verification Complete\n";
    echo "Passed: {$passed}\n";
    echo "Failed: {$failed}\n";
    echo "Log: {$logFileRelative}\n";
    echo "========================================\n";
});

// ============================================
// TEST DATA SETUP
// ============================================
logInfo('Step 1: Setting up test data (70 FireIncidents for pagination testing)');

// Create 70 fire incidents with deterministic timestamps for cursor testing
for ($i = 1; $i <= 70; $i++) {
    FireIncident::factory()->create([
        'event_num' => sprintf('F%03d', $i),
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes($i),
        'event_type' => 'TEST_INCIDENT',
        'prime_street' => "Test Street {$i}",
    ]);
}

assertEqual(70, FireIncident::count(), 'Created 70 test fire incidents');

// ============================================
// API CURSOR PAGINATION TESTS
// ============================================
logInfo('Step 2: Testing API cursor pagination');

$alertsQuery = app(UnifiedAlertsQuery::class);

// Test 1: Initial load returns batch with next_cursor
$criteria1 = new UnifiedAlertsCriteria(status: 'all', perPage: 25);
$page1 = $alertsQuery->cursorPaginate($criteria1);

assertEqual(25, count($page1['items']), 'First page returns 25 items');
assertTrue($page1['next_cursor'] !== null, 'First page has next_cursor');
logInfo('First page next_cursor: '.substr($page1['next_cursor'] ?? '', 0, 50).'...');

// Test 2: Second batch using cursor
$criteria2 = new UnifiedAlertsCriteria(status: 'all', perPage: 25, cursor: $page1['next_cursor']);
$page2 = $alertsQuery->cursorPaginate($criteria2);

assertEqual(25, count($page2['items']), 'Second page returns 25 items');
assertTrue($page2['next_cursor'] !== null, 'Second page has next_cursor');

// Test 3: Verify no duplicates between pages
$page1Ids = array_map(fn ($item) => $item->id, $page1['items']);
$page2Ids = array_map(fn ($item) => $item->id, $page2['items']);
$intersection = array_intersect($page1Ids, $page2Ids);
assertEqual([], $intersection, 'No duplicate IDs between page 1 and page 2');

// Test 4: Third batch (should have remaining 20)
$criteria3 = new UnifiedAlertsCriteria(status: 'all', perPage: 25, cursor: $page2['next_cursor']);
$page3 = $alertsQuery->cursorPaginate($criteria3);

assertEqual(20, count($page3['items']), 'Third page returns remaining 20 items');
assertEqual(null, $page3['next_cursor'], 'Third page has null next_cursor (end of feed)');

// Test 5: Verify total items across all pages
$allIds = array_merge($page1Ids, $page2Ids, array_map(fn ($item) => $item->id, $page3['items']));
assertEqual(70, count($allIds), 'Total items across all pages equals 70');
assertEqual(70, count(array_unique($allIds)), 'All items have unique IDs');

// ============================================
// CURSOR STABILITY TESTS
// ============================================
logInfo('Step 3: Testing cursor stability (new alerts during pagination)');

// Get first page
$criteriaStable = new UnifiedAlertsCriteria(status: 'all', perPage: 10);
$stablePage1 = $alertsQuery->cursorPaginate($criteriaStable);
$firstItemTimestamp = $stablePage1['items'][0]->timestamp;

// Simulate new alert arriving (more recent than first page)
FireIncident::factory()->create([
    'event_num' => 'FNEW',
    'is_active' => true,
    'dispatch_time' => Carbon::now()->addMinute(), // Future time, more recent
    'event_type' => 'NEW_INCIDENT',
]);

// Get second page using cursor from first (should not include the new alert)
$criteriaStable2 = new UnifiedAlertsCriteria(status: 'all', perPage: 10, cursor: $stablePage1['next_cursor']);
$stablePage2 = $alertsQuery->cursorPaginate($criteriaStable2);

// Verify new alert is not in page 2 (cursor is stable)
$stablePage2Ids = array_map(fn ($item) => $item->id, $stablePage2['items']);
assertTrue(! in_array('fire:FNEW', $stablePage2Ids), 'New alert arriving after cursor does not appear in subsequent pages');

// ============================================
// FILTER RESET TESTS
// ============================================
logInfo('Step 4: Testing filter change resets cursor');

// Get filtered results (fire only)
$criteriaFire = new UnifiedAlertsCriteria(status: 'all', source: 'fire', perPage: 25);
$firePage1 = $alertsQuery->cursorPaginate($criteriaFire);

assertTrue(count($firePage1['items']) > 0, 'Fire filter returns results');
assertTrue($firePage1['next_cursor'] !== null, 'Fire filter has next_cursor');

// Change filter to police (should return empty, different result set)
$criteriaPolice = new UnifiedAlertsCriteria(status: 'all', source: 'police', perPage: 25);
$policePage1 = $alertsQuery->cursorPaginate($criteriaPolice);

assertEqual(0, count($policePage1['items']), 'Police filter returns empty (no police data seeded)');
assertEqual(null, $policePage1['next_cursor'], 'Police filter has null cursor');

// ============================================
// API ENDPOINT TESTS
// ============================================
logInfo('Step 5: Testing API endpoint directly');

$response = \Illuminate\Support\Facades\Http::get(route('api.feed', ['per_page' => 20]));

assertTrue($response->successful(), 'API feed endpoint returns successful response');

$responseData = $response->json();
assertTrue(isset($responseData['data']), 'API response has data key');
assertTrue(isset($responseData['next_cursor']), 'API response has next_cursor key');
assertTrue(is_array($responseData['data']), 'API data is an array');

// ============================================
// MANUAL VERIFICATION STEPS
// ============================================
echo "\n";
echo "========================================\n";
echo "MANUAL VERIFICATION STEPS\n";
echo "========================================\n";
echo "\n";
echo "Please perform the following checks in a browser:\n";
echo "\n";
echo "1. INITIAL LOAD:\n";
echo "   - Visit: http://localhost/\n";
echo "   - Verify initial alerts load (up to 50 items)\n";
echo "   - Check that 'X loaded' counter shows the correct count\n";
echo "\n";
echo "2. INFINITE SCROLL:\n";
echo "   - Scroll down to the bottom of the feed\n";
echo "   - Verify 'Loading more...' spinner appears\n";
echo "   - Verify more alerts are appended to the list\n";
echo "   - Check that total counter updates\n";
echo "   - Continue scrolling until 'No more alerts' appears\n";
echo "\n";
echo "3. NO DUPLICATES:\n";
echo "   - After loading multiple batches, verify no duplicate alert IDs\n";
echo "   - Check browser console for any deduplication warnings\n";
echo "\n";
echo "4. FILTER RESET:\n";
echo "   - Apply a filter (e.g., Source: Fire)\n";
echo "   - Scroll to load more results\n";
echo "   - Change the filter (e.g., Source: Police)\n";
echo "   - Verify the list resets and shows new results from page 1\n";
echo "   - Verify cursor is reset (scroll position should be at top)\n";
echo "\n";
echo "5. CONCURRENT REQUEST GUARD:\n";
echo "   - Scroll quickly to the bottom multiple times\n";
echo "   - Verify only one 'Loading more...' indicator appears\n";
echo "   - Check Network tab - only one pending request at a time\n";
echo "\n";
echo "6. ERROR HANDLING:\n";
echo "   - Disconnect network briefly while scrolling\n";
echo "   - Verify error message appears with reload option\n";
echo "\n";
echo "7. BROWSER BACK/FORWARD:\n";
echo "   - Load page with filters applied\n";
echo "   - Load more batches via scroll\n";
echo "   - Navigate to another page\n";
echo "   - Click back button\n";
echo "   - Verify feed loads with correct filters (scroll resets to top)\n";
echo "\n";
echo "========================================\n";
echo "Log file: {$logFileRelative}\n";
echo "========================================\n";

Log::info("[{$testRunId}] Manual verification steps printed");
