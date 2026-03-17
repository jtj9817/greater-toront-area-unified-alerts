<?php

/**
 * Manual Test: Saved Alerts Phase 4 UI Integration (Backend Support)
 * Generated: 2026-03-17
 * Purpose: Verify that the backend correctly supports Phase 4 UI integration by:
 * 1. Providing initialSavedAlertIds to the GtaAlerts dashboard.
 * 2. Providing hydrated alert resources for the SavedView.
 * 3. Correct handling of missing alert IDs in the meta payload.
 */

require __DIR__.'/../../vendor/autoload.php';

if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution
if (app()->environment('production')) {
    fwrite(STDERR, "Error: Cannot run manual tests in production!\n");
    exit(1);
}

if (! app()->environment('testing')) {
    fwrite(STDERR, "Error: Manual tests must run with APP_ENV=testing. Destructive test operations are disabled outside the testing environment and cannot be overridden.\n");
    exit(1);
}

$expectedDatabase = 'gta_alerts_testing';
$allowSqliteFallback = getenv('MANUAL_TEST_USE_SQLITE') === '1';
$connection = config('database.default');
$currentDatabase = config("database.connections.{$connection}.database");

if (! $allowSqliteFallback && $currentDatabase !== $expectedDatabase) {
    fwrite(STDERR, "Error: Manual tests must use the '{$expectedDatabase}' database (current: {$currentDatabase}). Destructive test operations are disabled and cannot be overridden.\n");
    exit(1);
}

use App\Models\FireIncident;
use App\Models\SavedAlert;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$testRunId = 'test_phase_4_'.Carbon::now()->format('Y_m_d_His');
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

if (! @chmod($logFile, 0664)) {
    fwrite(STDERR, "Warning: Failed to set permissions on log file: {$logFile}\n");
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

$createdRecords = [];

try {
    DB::beginTransaction();

    logInfo("=== Starting Phase 4 Manual Verification: {$testRunId} ===");

    // === SETUP PHASE ===
    logInfo('Setting up test user and alerts...');
    $user = User::factory()->create(['email' => "phase4_{$testRunId}@example.com"]);
    $createdRecords['users'][] = $user->id;

    // Create 2 active fire incidents
    $eventNum1 = "P4_F1_{$testRunId}";
    $eventNum2 = "P4_F2_{$testRunId}";
    $fire1 = FireIncident::factory()->create(['event_num' => $eventNum1, 'is_active' => true]);
    $fire2 = FireIncident::factory()->create(['event_num' => $eventNum2, 'is_active' => true]);
    $createdRecords['fire_incidents'] = [$fire1->id, $fire2->id];

    $id1 = "fire:{$eventNum1}";
    $id2 = "fire:{$eventNum2}";
    $idMissing = "police:NON_EXISTENT_{$testRunId}";

    // Save alerts for user
    logInfo('Saving alerts for user...', ['ids' => [$id1, $id2, $idMissing]]);
    SavedAlert::create(['user_id' => $user->id, 'alert_id' => $id1]);
    SavedAlert::create(['user_id' => $user->id, 'alert_id' => $id2]);
    SavedAlert::create(['user_id' => $user->id, 'alert_id' => $idMissing]);

    // === EXECUTION PHASE ===

    logInfo('Testing GtaAlerts dashboard props (saved_alert_ids)...');
    Auth::login($user);
    $request = request();
    $request->setUserResolver(fn () => $user);

    // Simulate internal call to GtaAlertsController (__invoke)
    $controller = $app->make(\App\Http\Controllers\GtaAlertsController::class);
    $response = $controller($request, $app->make(\App\Services\Alerts\UnifiedAlertsQuery::class));
    $props = $response->toResponse($request)->getOriginalContent()['page']['props'];

    if (! isset($props['saved_alert_ids'])) {
        logError('FAIL: saved_alert_ids prop is missing from GtaAlerts dashboard.');
    } else {
        $savedIds = $props['saved_alert_ids'];
        logInfo('Dashboard props received.', ['count' => count($savedIds), 'ids' => $savedIds]);
        if (in_array($id1, $savedIds) && in_array($id2, $savedIds) && in_array($idMissing, $savedIds)) {
            logInfo('PASS: All saved IDs present in initial props.');
        } else {
            logError('FAIL: Missing some IDs in initial props.', ['expected' => [$id1, $id2, $idMissing], 'actual' => $savedIds]);
        }
    }

    logInfo('Testing SavedView hydration API (GET /api/saved-alerts)...');

    $savedController = $app->make(\App\Http\Controllers\Notifications\SavedAlertController::class);
    $apiResponse = $savedController->index($request, $app->make(\App\Services\Alerts\UnifiedAlertsQuery::class));
    $data = json_decode($apiResponse->getContent(), true);

    logInfo('API Response received.', ['meta' => $data['meta'] ?? 'none']);

    // Check hydration
    $hydratedIds = collect($data['data'])->pluck('id')->toArray();
    if (count($hydratedIds) === 2 && in_array($id1, $hydratedIds) && in_array($id2, $hydratedIds)) {
        logInfo('PASS: Hydrated alerts returned for existing incidents.');
    } else {
        logError('FAIL: Hydration issues.', ['expected' => [$id1, $id2], 'actual' => $hydratedIds]);
    }

    // Check missing IDs
    $missingIds = $data['meta']['missing_alert_ids'] ?? [];
    if (in_array($idMissing, $missingIds)) {
        logInfo('PASS: Missing alert correctly identified in meta.');
    } else {
        logError('FAIL: Missing alert not found in meta.missing_alert_ids.');
    }

    logInfo('Phase 4 Backend Verification completed successfully');

} catch (\Exception $e) {
    logError('Verification failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
} finally {
    // === CLEANUP PHASE ===
    DB::rollBack();
    logInfo('Cleanup (transaction rollback) completed');
    logInfo('=== Test Run Finished ===');
    echo "\n✓ Full logs at: {$logFile}\n";
}
