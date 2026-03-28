<?php

/**
 * Manual Test: Alert Location Map — Phase 3: Shared Client-Only Map Components
 * Generated: 2026-03-28
 * Purpose: Verify shared Leaflet runtime setup, client-only map rendering seams,
 *          deterministic map IDs, truthful unavailable copy, and targeted component coverage.
 *
 * Run via: ./scripts/run-manual-test.sh tests/manual/verify_alert_location_map_phase_3_shared_client_only_map_components.php
 */

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

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$testRunId = 'alert_location_map_phase_3_verify_'.Carbon::now()->format('Y_m_d_His');
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

function assert_contains(string $needle, string $haystack, string $label): void
{
    assert_true(str_contains($haystack, $needle), $label);
}

function run_command(string $command, string $label): array
{
    logInfo("Running command: {$command}");

    $output = [];
    $exitCode = 0;
    exec($command.' 2>&1', $output, $exitCode);
    $outputText = implode("\n", $output);

    if ($exitCode === 0) {
        logInfo("  PASS: {$label}");
    } else {
        logError("  FAIL: {$label}", ['exit_code' => $exitCode, 'output' => $outputText]);
    }

    return [$exitCode, $outputText];
}

try {
    DB::beginTransaction();

    logInfo('=== Starting Manual Test: Alert Location Map — Phase 3 Shared Client-Only Map Components ===');

    $basePath = base_path();
    $wrapperPath = $basePath.'/resources/js/features/gta-alerts/components/AlertLocationMap.tsx';
    $clientPath = $basePath.'/resources/js/features/gta-alerts/components/AlertLocationMap.client.tsx';
    $unavailablePath = $basePath.'/resources/js/features/gta-alerts/components/AlertLocationUnavailable.tsx';
    $leafletLibPath = $basePath.'/resources/js/features/gta-alerts/lib/leaflet.ts';
    $wrapperTestPath = $basePath.'/resources/js/features/gta-alerts/components/AlertLocationMap.test.tsx';
    $clientTestPath = $basePath.'/resources/js/features/gta-alerts/components/AlertLocationMap.client.test.tsx';

    // =====================================================================
    // STEP 1: FILE PRESENCE
    // =====================================================================
    logInfo('Step 1: Verify Phase 3 target files exist');

    foreach ([$wrapperPath, $clientPath, $unavailablePath, $leafletLibPath, $wrapperTestPath, $clientTestPath] as $path) {
        assert_true(file_exists($path), 'File exists: '.str_replace($basePath.'/', '', $path));
    }

    // =====================================================================
    // STEP 2: SHARED LEAFLET RUNTIME SEAM
    // =====================================================================
    logInfo('Step 2: Verify shared Leaflet runtime setup and OSM provider seam');

    $leafletLib = file_get_contents($leafletLibPath);
    assert_contains('export const OPEN_STREET_MAP_TILE_URL', $leafletLib, 'leaflet.ts exports tile URL constant');
    assert_contains('tile.openstreetmap.org', $leafletLib, 'leaflet.ts uses OpenStreetMap raster tile URL');
    assert_contains('export const OPEN_STREET_MAP_ATTRIBUTION', $leafletLib, 'leaflet.ts exports OSM attribution constant');
    assert_contains('OpenStreetMap', $leafletLib, 'leaflet.ts attribution includes OpenStreetMap');
    assert_contains('configureLeafletDefaultIcons', $leafletLib, 'leaflet.ts exports marker icon configuration function');
    assert_contains('mergeOptions', $leafletLib, 'leaflet.ts configures marker defaults through mergeOptions');

    // =====================================================================
    // STEP 3: CLIENT-ONLY MAP MODULE
    // =====================================================================
    logInfo('Step 3: Verify client-only map module structure and mobile behavior');

    $clientContent = file_get_contents($clientPath);
    assert_contains("import 'leaflet/dist/leaflet.css';", $clientContent, 'client map imports Leaflet CSS');
    assert_contains("import { useIsMobile } from '@/hooks/use-mobile';", $clientContent, 'client map uses shared useIsMobile hook');
    assert_contains('MapContainer', $clientContent, 'client map uses MapContainer');
    assert_contains('TileLayer', $clientContent, 'client map uses TileLayer');
    assert_contains('Marker', $clientContent, 'client map uses Marker');
    assert_contains('Popup', $clientContent, 'client map uses Popup');
    assert_contains('OPEN_STREET_MAP_TILE_URL', $clientContent, 'client map consumes OSM tile URL seam');
    assert_contains('OPEN_STREET_MAP_ATTRIBUTION', $clientContent, 'client map consumes OSM attribution seam');
    assert_contains('id={`${idBase}-map-wrapper`}', $clientContent, 'client map wrapper ID is deterministic from idBase');
    assert_contains('id={`${idBase}-map`}', $clientContent, 'map container ID is deterministic from idBase');
    assert_contains('isolate z-0', $clientContent, 'client map wrapper isolates stacking context');
    assert_contains('aspect-video', $clientContent, 'client map wrapper enforces explicit map height');
    assert_contains('dragging={!isMobile}', $clientContent, 'client map disables dragging on mobile');
    assert_contains('touchZoom={!isMobile}', $clientContent, 'client map disables touch zoom on mobile');
    assert_contains('doubleClickZoom={!isMobile}', $clientContent, 'client map disables double-click zoom on mobile');
    assert_contains('boxZoom={!isMobile}', $clientContent, 'client map disables box zoom on mobile');
    assert_contains('keyboard={!isMobile}', $clientContent, 'client map disables keyboard interactions on mobile');

    // =====================================================================
    // STEP 4: SSR SAFE WRAPPER + UNAVAILABLE COPY
    // =====================================================================
    logInfo('Step 4: Verify SSR-safe wrapper and unavailable-state semantics');

    $wrapperContent = file_get_contents($wrapperPath);
    assert_contains('lazy(', $wrapperContent, 'wrapper uses lazy loading for client module');
    assert_contains('Suspense', $wrapperContent, 'wrapper uses Suspense fallback');
    assert_contains('./AlertLocationMap.client', $wrapperContent, 'wrapper imports only the client map module lazily');
    assert_true(! str_contains($wrapperContent, 'react-leaflet'), 'wrapper does not directly import react-leaflet');
    assert_true(! str_contains($wrapperContent, 'leaflet/dist/leaflet.css'), 'wrapper does not import Leaflet CSS');
    assert_contains("export { AlertLocationUnavailable } from './AlertLocationUnavailable';", $wrapperContent, 'wrapper re-exports AlertLocationUnavailable');

    $unavailableContent = file_get_contents($unavailablePath);
    assert_contains('location-unavailable', $unavailableContent, 'unavailable component has deterministic id suffix');
    assert_contains('Map unavailable', $unavailableContent, 'unavailable component renders heading copy');
    assert_contains('Exact coordinates are not available for this alert.', $unavailableContent, 'unavailable component renders truthful copy');
    assert_contains('locationName', $unavailableContent, 'unavailable component displays location label');

    // =====================================================================
    // STEP 5: TEST COVERAGE SHAPE
    // =====================================================================
    logInfo('Step 5: Verify Phase 3 map component tests assert required behaviors');

    $wrapperTestContent = file_get_contents($wrapperTestPath);
    assert_contains("vi.mock('react-leaflet'", $wrapperTestContent, 'wrapper test mocks react-leaflet primitives');
    assert_contains('OpenStreetMap', $wrapperTestContent, 'wrapper test checks attribution surface');
    assert_contains('map-wrapper', $wrapperTestContent, 'wrapper test checks stable wrapper id');
    assert_contains('Map unavailable', $wrapperTestContent, 'wrapper test covers unavailable state copy');

    $clientTestContent = file_get_contents($clientTestPath);
    assert_contains("vi.mock('react-leaflet'", $clientTestContent, 'client test mocks react-leaflet primitives');
    assert_contains('configureLeafletDefaultIcons', $clientTestContent, 'client test asserts marker icon setup');
    assert_contains('TileLayer', $clientTestContent, 'client test includes tile layer coverage');
    assert_contains('Marker', $clientTestContent, 'client test includes marker coverage');
    assert_contains('Popup', $clientTestContent, 'client test includes popup coverage');

    // =====================================================================
    // STEP 6: TARGETED PHASE 3 TEST EXECUTION
    // =====================================================================
    logInfo('Step 6: Run targeted Vitest suite for Phase 3 map components');

    [$wrapperVitestExitCode, $wrapperVitestOutput] = run_command(
        "cd {$basePath} && LARAVEL_BYPASS_ENV_CHECK=1 CI=true pnpm exec vitest run --pool=forks resources/js/features/gta-alerts/components/AlertLocationMap.test.tsx",
        'AlertLocationMap.test.tsx passes'
    );

    [$clientVitestExitCode, $clientVitestOutput] = run_command(
        "cd {$basePath} && LARAVEL_BYPASS_ENV_CHECK=1 CI=true pnpm exec vitest run --pool=forks resources/js/features/gta-alerts/components/AlertLocationMap.client.test.tsx",
        'AlertLocationMap.client.test.tsx passes'
    );

    assert_equals(0, $wrapperVitestExitCode, 'AlertLocationMap.test.tsx command exits successfully');
    assert_equals(0, $clientVitestExitCode, 'AlertLocationMap.client.test.tsx command exits successfully');
    assert_true(str_contains($wrapperVitestOutput, 'AlertLocationMap.test.tsx'), 'Wrapper Vitest output includes AlertLocationMap.test.tsx');
    assert_true(str_contains($clientVitestOutput, 'AlertLocationMap.client.test.tsx'), 'Client Vitest output includes AlertLocationMap.client.test.tsx');
    assert_true(str_contains($wrapperVitestOutput, 'passed'), 'Wrapper Vitest output reports passing tests');
    assert_true(str_contains($clientVitestOutput, 'passed'), 'Client Vitest output reports passing tests');

    // =====================================================================
    // SUMMARY
    // =====================================================================
    logInfo('');
    logInfo('=== Phase 3 Manual Verification Complete ===');
    logInfo("Results: {$passed} passed, {$failed} failed, ".($passed + $failed).' total');

    if ($failed > 0) {
        logError("VERIFICATION FAILED — {$failed} assertion(s) did not pass.");
    } else {
        logInfo('ALL ASSERTIONS PASSED — Phase 3 Shared Client-Only Map Components verified.');
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
