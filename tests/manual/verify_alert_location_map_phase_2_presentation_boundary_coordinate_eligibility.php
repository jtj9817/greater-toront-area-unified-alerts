<?php

/**
 * Manual Test: Alert Location Map — Phase 2: Presentation Boundary and Coordinate Eligibility
 * Generated: 2026-03-28
 * Purpose: Verify locationCoords presentation typing, normalization eligibility rules,
 *          boundary-inclusive coordinate handling, and frontend consumption boundaries.
 *
 * Run via: ./scripts/run-manual-test.sh tests/manual/verify_alert_location_map_phase_2_presentation_boundary_coordinate_eligibility.php
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

$testRunId = 'alert_location_map_phase_2_verify_'.Carbon::now()->format('Y_m_d_His');
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

    logInfo('=== Starting Manual Test: Alert Location Map — Phase 2 Presentation Boundary and Coordinate Eligibility ===');

    $basePath = base_path();
    $typesPath = $basePath.'/resources/js/features/gta-alerts/domain/alerts/view/types.ts';
    $mapperPath = $basePath.'/resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.ts';
    $mapperTestPath = $basePath.'/resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.test.ts';
    $constantsPath = $basePath.'/resources/js/features/gta-alerts/constants.ts';
    $detailsViewPath = $basePath.'/resources/js/features/gta-alerts/components/AlertDetailsView.tsx';

    // =====================================================================
    // STEP 1: FILE PRESENCE
    // =====================================================================
    logInfo('Step 1: Verify Phase 2 target files exist');

    foreach ([$typesPath, $mapperPath, $mapperTestPath, $constantsPath, $detailsViewPath] as $path) {
        assert_true(file_exists($path), 'File exists: '.str_replace($basePath.'/', '', $path));
    }

    // =====================================================================
    // STEP 2: TYPE CONTRACT VERIFICATION
    // =====================================================================
    logInfo('Step 2: Verify AlertPresentation typing exposes locationCoords boundary');

    $typesContent = file_get_contents($typesPath);

    assert_contains('export interface AlertPresentationCoordinates', $typesContent, 'AlertPresentationCoordinates interface exists');
    assert_contains('lat: number;', $typesContent, 'AlertPresentationCoordinates.lat is typed as number');
    assert_contains('lng: number;', $typesContent, 'AlertPresentationCoordinates.lng is typed as number');
    assert_contains('locationCoords: AlertPresentationCoordinates | null;', $typesContent, 'AlertPresentation includes locationCoords union type');

    // =====================================================================
    // STEP 3: NORMALIZATION SEAM VERIFICATION
    // =====================================================================
    logInfo('Step 3: Verify coordinate normalization eligibility seam');

    $mapperContent = file_get_contents($mapperPath);

    assert_contains('function normalizeCoordinates(', $mapperContent, 'normalizeCoordinates seam exists');
    assert_contains("typeof lat !== 'number'", $mapperContent, 'normalizeCoordinates rejects missing/non-number latitude');
    assert_contains('!Number.isFinite(lat)', $mapperContent, 'normalizeCoordinates rejects non-finite latitude');
    assert_contains("typeof lng !== 'number'", $mapperContent, 'normalizeCoordinates rejects missing/non-number longitude');
    assert_contains('!Number.isFinite(lng)', $mapperContent, 'normalizeCoordinates rejects non-finite longitude');
    assert_contains('lat < 40 || lat > 50 || lng < -90 || lng > -70', $mapperContent, 'normalizeCoordinates uses inclusive GTA bounds by rejecting only out-of-range values');
    assert_contains('locationCoords: normalizeCoordinates(alert.location)', $mapperContent, 'mapDomainAlertToPresentation consumes normalization seam');
    assert_contains("location: alert.location?.name?.trim() || 'Unknown location'", $mapperContent, 'mapDomainAlertToPresentation preserves location label fallback');

    // =====================================================================
    // STEP 4: TEST COVERAGE VERIFICATION
    // =====================================================================
    logInfo('Step 4: Verify mapper tests cover required eligibility cases');

    $mapperTestContent = file_get_contents($mapperTestPath);

    $requiredCaseDescriptions = [
        'preserves valid police coordinates as locationCoords',
        'preserves coordinates that sit exactly on GTA boundary limits',
        'sets locationCoords to null when location is null',
        'sets locationCoords to null when both lat and lng are null',
        'sets locationCoords to null when lat is present but lng is null (partial)',
        'sets locationCoords to null when lng is present but lat is null (partial)',
        'sets locationCoords to null for NaN coordinates (non-finite)',
        'sets locationCoords to null for Infinity coordinates (non-finite)',
        'sets locationCoords to null for out-of-range latitude (too far north)',
        'sets locationCoords to null for out-of-range latitude (too far south)',
        'sets locationCoords to null for out-of-range longitude (too far west)',
        'sets locationCoords to null for out-of-range longitude (too far east)',
        'sets locationCoords to null for Null Island (0, 0) coordinates',
        'preserves location label even when locationCoords is null',
    ];

    foreach ($requiredCaseDescriptions as $description) {
        assert_contains($description, $mapperTestContent, "Mapper test exists: {$description}");
    }

    // =====================================================================
    // STEP 5: MOCK DATA CONTRACT VERIFICATION
    // =====================================================================
    logInfo('Step 5: Verify mock presentation constants include locationCoords');

    $constantsContent = file_get_contents($constantsPath);
    $locationCoordsNullCount = substr_count($constantsContent, 'locationCoords: null');

    assert_true($locationCoordsNullCount >= 6, 'Mock alert constants declare locationCoords: null for all seed items');

    // =====================================================================
    // STEP 6: UI CONSUMPTION BOUNDARY VERIFICATION
    // =====================================================================
    logInfo('Step 6: Verify UI avoids raw coordinate inspection');

    $detailsViewContent = file_get_contents($detailsViewPath);
    assert_true(! str_contains($detailsViewContent, 'alert.location.lat'), 'AlertDetailsView does not read raw alert.location.lat');
    assert_true(! str_contains($detailsViewContent, 'alert.location.lng'), 'AlertDetailsView does not read raw alert.location.lng');

    $rawCoordinatePattern = '/alert\.location\.(lat|lng)|location\?\.lat|location\?\.lng/';
    $searchRoots = [
        $basePath.'/resources/js/features/gta-alerts/components',
        $basePath.'/resources/js/features/gta-alerts/services',
    ];
    $rawCoordinateMatches = [];

    foreach ($searchRoots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile()) {
                continue;
            }

            $path = $fileInfo->getPathname();
            if (! str_ends_with($path, '.ts') && ! str_ends_with($path, '.tsx')) {
                continue;
            }
            if (str_ends_with($path, '.test.ts') || str_ends_with($path, '.test.tsx')) {
                continue;
            }

            $content = file_get_contents($path);
            if (preg_match($rawCoordinatePattern, $content) === 1) {
                $rawCoordinateMatches[] = str_replace($basePath.'/', '', $path);
            }
        }
    }

    assert_equals([], $rawCoordinateMatches, 'No raw lat/lng usage in GTA alert components/services');

    // =====================================================================
    // STEP 7: TARGETED PHASE 2 TEST EXECUTION
    // =====================================================================
    logInfo('Step 7: Run targeted Vitest suite for presentation boundary and contract');

    [$vitestExitCode, $vitestOutput] = run_command(
        "cd {$basePath} && LARAVEL_BYPASS_ENV_CHECK=1 CI=true pnpm exec vitest run resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.test.ts resources/js/features/gta-alerts/services/AlertService.test.ts",
        'Targeted Vitest suite passes'
    );

    assert_equals(0, $vitestExitCode, 'Vitest command exits successfully');
    assert_true(str_contains($vitestOutput, 'mapDomainAlertToPresentation.test.ts'), 'Vitest output includes mapDomainAlertToPresentation test file');
    assert_true(str_contains($vitestOutput, 'AlertService.test.ts'), 'Vitest output includes AlertService test file');
    assert_true(str_contains($vitestOutput, 'passed'), 'Vitest output reports passing tests');

    // =====================================================================
    // SUMMARY
    // =====================================================================
    logInfo('');
    logInfo('=== Phase 2 Manual Verification Complete ===');
    logInfo("Results: {$passed} passed, {$failed} failed, ".($passed + $failed).' total');

    if ($failed > 0) {
        logError("VERIFICATION FAILED — {$failed} assertion(s) did not pass.");
    } else {
        logInfo('ALL ASSERTIONS PASSED — Phase 2 Presentation Boundary and Coordinate Eligibility verified.');
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
