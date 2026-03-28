<?php

/**
 * Manual Test: Alert Location Map — Phase 1: Contract Guardrails and Runtime Foundation
 * Generated: 2026-03-27
 * Purpose: Verify the location transport contract (lat/lng/name), Leaflet dependencies,
 *          SSR-safe component seam, and component-level test scaffolding from Phase 1.
 *
 * Run via: ./scripts/run-manual-test.sh tests/manual/verify_alert_location_map_phase_1_contract_guardrails_runtime_foundation.php
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution
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

use App\Http\Resources\UnifiedAlertResource;
use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$testRunId = 'alert_location_map_phase_1_verify_'.Carbon::now()->format('Y_m_d_His');
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

$passed = 0;
$failed = 0;

function assert_true(bool $condition, string $label): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        logInfo("  PASS: {$label}");
    } else {
        $failed++;
        logError("  FAIL: {$label}");
    }
}

function assert_equals($expected, $actual, string $label): void
{
    global $passed, $failed;

    if ($expected === $actual) {
        $passed++;
        logInfo("  PASS: {$label}");
    } else {
        $failed++;
        $expectedStr = var_export($expected, true);
        $actualStr = var_export($actual, true);
        logError("  FAIL: {$label} (expected: {$expectedStr}, got: {$actualStr})");
    }
}

try {
    DB::beginTransaction();

    logInfo('=== Starting Manual Test: Alert Location Map — Phase 1 Contract Guardrails and Runtime Foundation ===');

    // =====================================================================
    // STEP 1: DATABASE SETUP
    // =====================================================================
    logInfo('Step 1: Database Setup — Migrating fresh...');
    Artisan::call('migrate:fresh', ['--force' => true]);
    logInfo('Migration completed.');

    // =====================================================================
    // STEP 2: LOCATION CONTRACT — COORDINATES PRESENT
    // =====================================================================
    logInfo('Step 2: Location Contract — Coordinates Present (PoliceCall with lat/lng)');

    $policeCall = PoliceCall::factory()->create([
        'object_id' => 789,
        'latitude' => 43.6567,
        'longitude' => -79.3789,
        'cross_streets' => 'Yonge St & Dundas St',
        'is_active' => true,
        'occurrence_time' => Carbon::now()->subMinutes(5),
        'feed_updated_at' => Carbon::now()->subMinutes(4),
    ]);

    logInfo('  Created PoliceCall', ['object_id' => 789, 'lat' => 43.6567, 'lng' => -79.3789]);

    /** @var UnifiedAlertsQuery $query */
    $query = app(UnifiedAlertsQuery::class);
    $criteria = new UnifiedAlertsCriteria(source: 'police');
    $result = $query->cursorPaginate($criteria);

    assert_true(count($result['items']) >= 1, 'cursorPaginate returns at least 1 police alert');

    $policeAlert = collect($result['items'])->first(fn ($a) => $a->externalId === '789');
    assert_true($policeAlert !== null, 'Police alert with externalId 789 found in results');

    if ($policeAlert) {
        assert_equals('Yonge St & Dundas St', $policeAlert->location->name, 'location.name matches cross_streets');
        assert_equals(43.6567, $policeAlert->location->lat, 'location.lat matches latitude');
        assert_equals(-79.3789, $policeAlert->location->lng, 'location.lng matches longitude');
    }

    // =====================================================================
    // STEP 3: LOCATION CONTRACT — COORDINATES ABSENT
    // =====================================================================
    logInfo('Step 3: Location Contract — Coordinates Absent (FireIncident without lat/lng)');

    $fireIncident = FireIncident::factory()->create([
        'event_num' => 'E999',
        'event_type' => 'FIRE ALARM',
        'prime_street' => 'Main St',
        'cross_streets' => 'King St',
        'is_active' => true,
        'dispatch_time' => Carbon::now()->subMinutes(3),
        'feed_updated_at' => Carbon::now()->subMinutes(2),
    ]);

    logInfo('  Created FireIncident', ['event_num' => 'E999', 'prime_street' => 'Main St', 'cross_streets' => 'King St']);

    $fireCriteria = new UnifiedAlertsCriteria(source: 'fire');
    $fireResult = $query->cursorPaginate($fireCriteria);

    assert_true(count($fireResult['items']) >= 1, 'cursorPaginate returns at least 1 fire alert');

    $fireAlert = collect($fireResult['items'])->first(fn ($a) => $a->externalId === 'E999');
    assert_true($fireAlert !== null, 'Fire alert with externalId E999 found in results');

    if ($fireAlert) {
        assert_equals('Main St / King St', $fireAlert->location->name, 'location.name joins prime_street / cross_streets');
        assert_equals(null, $fireAlert->location->lat, 'location.lat is null when no coordinates');
        assert_equals(null, $fireAlert->location->lng, 'location.lng is null when no coordinates');
    }

    // =====================================================================
    // STEP 4: UNIFIED ALERT RESOURCE CONTRACT
    // =====================================================================
    logInfo('Step 4: UnifiedAlertResource Contract Verification');

    $request = Request::create('/', 'GET');

    // Resource for alert WITH coordinates
    if ($policeAlert) {
        $policeResource = (new UnifiedAlertResource($policeAlert))->toArray($request);

        assert_true(array_key_exists('location', $policeResource), 'Resource output has location key');
        assert_true(is_array($policeResource['location']), 'location is an array');
        assert_true(array_key_exists('name', $policeResource['location']), 'location has name key');
        assert_true(array_key_exists('lat', $policeResource['location']), 'location has lat key');
        assert_true(array_key_exists('lng', $policeResource['location']), 'location has lng key');
        assert_equals('Yonge St & Dundas St', $policeResource['location']['name'], 'Resource location.name for police');
        assert_equals(43.6567, $policeResource['location']['lat'], 'Resource location.lat for police');
        assert_equals(-79.3789, $policeResource['location']['lng'], 'Resource location.lng for police');
    }

    // Resource for alert WITHOUT coordinates
    if ($fireAlert) {
        $fireResource = (new UnifiedAlertResource($fireAlert))->toArray($request);

        assert_true(array_key_exists('location', $fireResource), 'Fire resource output has location key');
        assert_true(is_array($fireResource['location']), 'Fire location is an array');
        assert_equals('Main St / King St', $fireResource['location']['name'], 'Fire resource location.name');
        assert_equals(null, $fireResource['location']['lat'], 'Fire resource location.lat is null');
        assert_equals(null, $fireResource['location']['lng'], 'Fire resource location.lng is null');
    }

    // =====================================================================
    // STEP 5: FRONTEND FILE EXISTENCE
    // =====================================================================
    logInfo('Step 5: Frontend File Existence Verification');

    $basePath = base_path();
    $requiredFiles = [
        'resources/js/features/gta-alerts/components/AlertLocationMap.tsx',
        'resources/js/features/gta-alerts/components/AlertLocationMap.client.tsx',
        'resources/js/features/gta-alerts/components/AlertLocationUnavailable.tsx',
        'resources/js/features/gta-alerts/lib/leaflet.ts',
        'resources/js/features/gta-alerts/components/AlertLocationMap.test.tsx',
        'resources/js/features/gta-alerts/components/AlertLocationMap.client.test.tsx',
    ];

    foreach ($requiredFiles as $relPath) {
        $fullPath = $basePath.'/'.$relPath;
        assert_true(file_exists($fullPath), "File exists: {$relPath}");
    }

    // =====================================================================
    // STEP 6: FRONTEND STRUCTURE VERIFICATION
    // =====================================================================
    logInfo('Step 6: Frontend Structure Verification');

    // AlertLocationMap.tsx — SSR-safe wrapper
    $wrapperContent = file_get_contents($basePath.'/resources/js/features/gta-alerts/components/AlertLocationMap.tsx');
    assert_true(str_contains($wrapperContent, 'React.lazy') || str_contains($wrapperContent, 'lazy('), 'AlertLocationMap.tsx uses React.lazy');
    assert_true(str_contains($wrapperContent, 'Suspense'), 'AlertLocationMap.tsx uses Suspense');
    assert_true(str_contains($wrapperContent, 'AlertLocationMapClient'), 'AlertLocationMap.tsx references AlertLocationMapClient');
    assert_true(str_contains($wrapperContent, 'map-loading'), 'AlertLocationMap.tsx has map-loading fallback id');

    // AlertLocationMap.client.tsx — client-only module
    $clientContent = file_get_contents($basePath.'/resources/js/features/gta-alerts/components/AlertLocationMap.client.tsx');
    assert_true(str_contains($clientContent, 'MapContainer'), 'AlertLocationMap.client.tsx imports MapContainer');
    assert_true(str_contains($clientContent, 'leaflet/dist/leaflet.css'), 'AlertLocationMap.client.tsx imports leaflet CSS');
    assert_true(str_contains($clientContent, 'configureLeafletDefaultIcons'), 'AlertLocationMap.client.tsx calls configureLeafletDefaultIcons');
    assert_true(str_contains($clientContent, 'aspect-video'), 'AlertLocationMap.client.tsx has aspect-video class');

    // AlertLocationUnavailable.tsx — unavailable state
    $unavailContent = file_get_contents($basePath.'/resources/js/features/gta-alerts/components/AlertLocationUnavailable.tsx');
    assert_true(str_contains($unavailContent, 'location_off'), 'AlertLocationUnavailable.tsx uses location_off icon');
    assert_true(str_contains($unavailContent, 'Map unavailable'), 'AlertLocationUnavailable.tsx shows "Map unavailable" text');
    assert_true(str_contains($unavailContent, 'locationName'), 'AlertLocationUnavailable.tsx displays locationName');
    assert_true(str_contains($unavailContent, 'location-unavailable'), 'AlertLocationUnavailable.tsx has location-unavailable id');

    // leaflet.ts — marker configuration
    $leafletContent = file_get_contents($basePath.'/resources/js/features/gta-alerts/lib/leaflet.ts');
    assert_true(str_contains($leafletContent, 'configureLeafletDefaultIcons'), 'leaflet.ts exports configureLeafletDefaultIcons');
    assert_true(str_contains($leafletContent, 'mergeOptions'), 'leaflet.ts calls L.Icon.Default.mergeOptions');
    assert_true(str_contains($leafletContent, 'marker-icon.png'), 'leaflet.ts references marker-icon.png');
    assert_true(str_contains($leafletContent, 'import.meta.url'), 'leaflet.ts uses import.meta.url for Vite asset resolution');

    // =====================================================================
    // STEP 7: DEPENDENCY VERIFICATION
    // =====================================================================
    logInfo('Step 7: Dependency Verification (package.json)');

    $packageJson = json_decode(file_get_contents($basePath.'/package.json'), true);
    $deps = $packageJson['dependencies'] ?? [];
    $devDeps = $packageJson['devDependencies'] ?? [];

    assert_true(isset($deps['leaflet']), 'package.json has leaflet dependency');
    assert_true(isset($deps['react-leaflet']), 'package.json has react-leaflet dependency');
    assert_true(isset($devDeps['@types/leaflet']), 'package.json has @types/leaflet devDependency');

    if (isset($deps['leaflet'])) {
        assert_true(str_contains($deps['leaflet'], '1.9'), 'leaflet version is ^1.9.x');
    }
    if (isset($deps['react-leaflet'])) {
        assert_true(str_contains($deps['react-leaflet'], '4.2'), 'react-leaflet version is ^4.2.x');
    }
    if (isset($devDeps['@types/leaflet'])) {
        assert_true(str_contains($devDeps['@types/leaflet'], '1.9'), '@types/leaflet version is ^1.9.x');
    }

    // Verify lockfile exists
    assert_true(file_exists($basePath.'/pnpm-lock.yaml'), 'pnpm-lock.yaml exists');

    // =====================================================================
    // STEP 8: NO MIGRATION VERIFICATION
    // =====================================================================
    logInfo('Step 8: No-Migration Verification (Phase 1 is frontend-only + test-layer)');

    // Phase 1 commits: 5210db6 and d21be20
    // No new migration files should have been added in these commits.
    // We verify by checking git for migration changes in the Phase 1 range.
    $migrationCheck = shell_exec("cd {$basePath} && git diff --name-only 5210db6^..d21be20 -- database/migrations/ 2>/dev/null");
    $migrationFiles = array_filter(explode("\n", trim($migrationCheck ?? '')));
    assert_equals(0, count($migrationFiles), 'No new migration files added in Phase 1 commits');

    // =====================================================================
    // STEP 9: PEST TEST EXISTENCE
    // =====================================================================
    logInfo('Step 9: Pest Test Existence in GtaAlertsTest.php');

    $testFileContent = file_get_contents($basePath.'/tests/Feature/GtaAlertsTest.php');

    $expectedTests = [
        'police alert with coordinates exposes name, lat, and lng in location object',
        'fire alert without coordinates exposes null lat and lng in location object',
        'alerts prop supports partial reload via reloadOnly',
    ];

    foreach ($expectedTests as $description) {
        assert_true(
            str_contains($testFileContent, $description),
            "GtaAlertsTest.php contains test: {$description}"
        );
    }

    // Also verify component test files have actual test assertions
    $wrapperTestContent = file_get_contents($basePath.'/resources/js/features/gta-alerts/components/AlertLocationMap.test.tsx');
    assert_true(str_contains($wrapperTestContent, 'alert-location-map-client'), 'AlertLocationMap.test.tsx asserts client component rendering');

    $clientTestContent = file_get_contents($basePath.'/resources/js/features/gta-alerts/components/AlertLocationMap.client.test.tsx');
    assert_true(str_contains($clientTestContent, 'configureLeafletDefaultIcons'), 'AlertLocationMap.client.test.tsx asserts icon configuration');
    assert_true(str_contains($clientTestContent, 'aspect-video'), 'AlertLocationMap.client.test.tsx asserts aspect-video sizing');

    // =====================================================================
    // SUMMARY
    // =====================================================================
    logInfo('');
    logInfo('=== Phase 1 Manual Verification Complete ===');
    logInfo("Results: {$passed} passed, {$failed} failed, ".($passed + $failed).' total');

    if ($failed > 0) {
        logError("VERIFICATION FAILED — {$failed} assertion(s) did not pass.");
    } else {
        logInfo('ALL ASSERTIONS PASSED — Phase 1 Contract Guardrails and Runtime Foundation verified.');
    }

} catch (\Exception $e) {
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    DB::rollBack();
    logInfo('Transaction rolled back (Database preserved).');
    logInfo('=== Test Run Finished ===');
    echo "\n".($failed === 0 ? '✓' : '✗')." Full logs at: {$logFileRelative}\n";
}
