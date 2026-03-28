<?php

/**
 * Manual Test: Alert Location Map — Phase 4: Shared Alert Details Integration
 * Generated: 2026-03-28
 * Purpose: Verify the shared Alert Details location-map integration, deterministic IDs,
 *          map/unavailable rendering decisions, and the removal of legacy placeholder copy.
 *
 * Run via: ./scripts/run-manual-test.sh tests/manual/verify_alert_location_map_phase_4_shared_alert_details_integration.php
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

$testRunId = 'alert_location_map_phase_4_verify_'.Carbon::now()->format('Y_m_d_His');
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

function assert_not_contains(string $needle, string $haystack, string $label): void
{
    assert_true(! str_contains($haystack, $needle), $label);
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

    logInfo('=== Starting Manual Test: Alert Location Map — Phase 4 Shared Alert Details Integration ===');

    $basePath = base_path();
    $detailsViewPath = $basePath.'/resources/js/features/gta-alerts/components/AlertDetailsView.tsx';
    $detailsViewTestPath = $basePath.'/resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx';
    $mapPath = $basePath.'/resources/js/features/gta-alerts/components/AlertLocationMap.tsx';
    $mapUnavailablePath = $basePath.'/resources/js/features/gta-alerts/components/AlertLocationUnavailable.tsx';

    // =====================================================================
    // STEP 1: FILE PRESENCE
    // =====================================================================
    logInfo('Step 1: Verify Phase 4 target files exist');

    foreach ([$detailsViewPath, $detailsViewTestPath, $mapPath, $mapUnavailablePath] as $path) {
        assert_true(file_exists($path), 'File exists: '.str_replace($basePath.'/', '', $path));
    }

    // =====================================================================
    // STEP 2: SHARED LAYOUT INTEGRATION
    // =====================================================================
    logInfo('Step 2: Verify shared location section is in common details layout');

    $detailsViewContent = file_get_contents($detailsViewPath);

    assert_contains("import { AlertLocationMap, AlertLocationUnavailable } from './AlertLocationMap';", $detailsViewContent, 'AlertDetailsView imports shared map surfaces');
    assert_contains('const idBase = `gta-alerts-alert-details-${alert.id}`;', $detailsViewContent, 'AlertDetailsView uses shared idBase');
    assert_contains('id={`${idBase}-location-section`}', $detailsViewContent, 'Shared location section has deterministic ID');
    assert_contains('<AlertLocationMap', $detailsViewContent, 'Shared layout renders AlertLocationMap when coordinates exist');
    assert_contains('<AlertLocationUnavailable', $detailsViewContent, 'Shared layout renders AlertLocationUnavailable when coordinates are missing');
    assert_contains('alert.locationCoords ? (', $detailsViewContent, 'Shared layout branches on presentation locationCoords');
    assert_contains('Location Map', $detailsViewContent, 'Shared layout includes Location Map heading');

    $briefingIndex = strpos($detailsViewContent, 'id={`${idBase}-briefing-section`}');
    $locationIndex = strpos($detailsViewContent, 'id={`${idBase}-location-section`}');
    $specializedIndex = strpos($detailsViewContent, '{sections.specializedContent}');

    assert_true($briefingIndex !== false, 'Briefing section anchor exists');
    assert_true($locationIndex !== false, 'Location section anchor exists');
    assert_true($specializedIndex !== false, 'Specialized content anchor exists');
    if ($briefingIndex !== false && $locationIndex !== false && $specializedIndex !== false) {
        assert_true($briefingIndex < $locationIndex, 'Location section appears after Official Briefing');
        assert_true($locationIndex < $specializedIndex, 'Location section appears before specialized content');
    }

    // =====================================================================
    // STEP 3: FIRE BRANCH SPECIALIZATION BOUNDARY
    // =====================================================================
    logInfo('Step 3: Verify fire branch no longer owns map placeholder rendering');

    assert_not_contains('Interactive Map Loading...', $detailsViewContent, 'Legacy placeholder text removed from AlertDetailsView');

    $fireStart = strpos($detailsViewContent, 'function buildFireSections');
    $policeStart = strpos($detailsViewContent, 'function buildPoliceSections');
    $fireSegment = '';

    if ($fireStart !== false && $policeStart !== false && $policeStart > $fireStart) {
        $fireSegment = substr($detailsViewContent, $fireStart, $policeStart - $fireStart);
    }

    assert_true($fireSegment !== '', 'Fire section source segment extracted');
    if ($fireSegment !== '') {
        assert_contains('<SceneIntelTimeline', $fireSegment, 'Fire specialized content retains SceneIntelTimeline');
        assert_not_contains('AlertLocationMap', $fireSegment, 'Fire specialized content no longer renders AlertLocationMap');
        assert_not_contains('Location Map', $fireSegment, 'Fire specialized content no longer declares map heading');
    }

    // =====================================================================
    // STEP 4: DETAIL TEST COVERAGE FOR PHASE 4
    // =====================================================================
    logInfo('Step 4: Verify AlertDetailsView test coverage includes shared location behavior');

    $detailsViewTestContent = file_get_contents($detailsViewTestPath);

    assert_contains("vi.mock('./AlertLocationMap'", $detailsViewTestContent, 'AlertDetailsView tests mock shared map module');
    assert_contains('renders police detail branch for police kind', $detailsViewTestContent, 'Police branch test exists');
    assert_contains('renders unavailable location map state for fire alerts without renderable coordinates', $detailsViewTestContent, 'Fire unavailable-state test exists');
    assert_contains('alert-location-map', $detailsViewTestContent, 'Tests assert map branch rendering');
    assert_contains('alert-location-unavailable', $detailsViewTestContent, 'Tests assert unavailable branch rendering');
    assert_contains('Interactive Map Loading...', $detailsViewTestContent, 'Tests assert legacy placeholder text removal');
    assert_contains('location-section', $detailsViewTestContent, 'Tests assert stable shared location section ID');
    assert_contains('-map-wrapper', $detailsViewTestContent, 'Tests assert deterministic map wrapper ID');
    assert_contains('-location-unavailable', $detailsViewTestContent, 'Tests assert deterministic unavailable wrapper ID');

    // =====================================================================
    // STEP 5: TARGETED PHASE 4 TEST EXECUTION
    // =====================================================================
    logInfo('Step 5: Run targeted Vitest suite for shared details integration');

    [$detailsVitestExitCode, $detailsVitestOutput] = run_command(
        "cd {$basePath} && LARAVEL_BYPASS_ENV_CHECK=1 CI=true pnpm exec vitest run --pool=forks resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx",
        'AlertDetailsView.test.tsx passes'
    );

    [$mapVitestExitCode, $mapVitestOutput] = run_command(
        "cd {$basePath} && LARAVEL_BYPASS_ENV_CHECK=1 CI=true pnpm exec vitest run --pool=forks resources/js/features/gta-alerts/components/AlertLocationMap.test.tsx",
        'AlertLocationMap.test.tsx passes'
    );

    assert_equals(0, $detailsVitestExitCode, 'AlertDetailsView.test.tsx command exits successfully');
    assert_equals(0, $mapVitestExitCode, 'AlertLocationMap.test.tsx command exits successfully');
    assert_true(str_contains($detailsVitestOutput, 'AlertDetailsView.test.tsx'), 'Detail Vitest output includes AlertDetailsView.test.tsx');
    assert_true(str_contains($mapVitestOutput, 'AlertLocationMap.test.tsx'), 'Map Vitest output includes AlertLocationMap.test.tsx');
    assert_true(str_contains($detailsVitestOutput, 'passed'), 'Detail Vitest output reports passing tests');
    assert_true(str_contains($mapVitestOutput, 'passed'), 'Map Vitest output reports passing tests');

    // =====================================================================
    // SUMMARY
    // =====================================================================
    logInfo('');
    logInfo('=== Phase 4 Manual Verification Complete ===');
    logInfo("Results: {$passed} passed, {$failed} failed, ".($passed + $failed).' total');

    if ($failed > 0) {
        logError("VERIFICATION FAILED — {$failed} assertion(s) did not pass.");
    } else {
        logInfo('ALL ASSERTIONS PASSED — Phase 4 Shared Alert Details Integration verified.');
    }
} catch (Throwable $e) {
    logError('Unhandled exception during manual verification', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
    $failed++;
} finally {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    logInfo("Log file: {$logFileRelative}");
    logInfo('=== Test Run Finished ===');
    echo "\nSummary: {$passed} passed, {$failed} failed\n";
    echo "Full log: {$logFileRelative}\n";
    echo $failed > 0 ? "Status: FAILED\n" : "Status: PASSED\n";

    exit($failed > 0 ? 1 : 0);
}

