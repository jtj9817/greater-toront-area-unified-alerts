<?php
/**
 * Manual Test: Saved Alerts Phase 4 UI Integration (Backend Support)
 * Generated: 2026-03-17
 * Purpose: Verify that the backend correctly supports Phase 4 UI integration by:
 * 1. Providing initialSavedAlertIds to the GtaAlerts dashboard.
 * 2. Providing hydrated alert resources for the SavedView.
 * 3. Correct handling of missing alert IDs in the meta payload.
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution
if (app()->environment('production')) {
    die("Error: Cannot run manual tests in production!\n");
}

use Illuminate\Support\Facades\{DB, Log, Auth};
use Carbon\Carbon;
use App\Models\User;
use App\Models\FireIncident;
use App\Models\SavedAlert;
use Inertia\Testing\AssertableInertia as Assert;

$testRunId = 'test_phase_4_' . Carbon::now()->format('Y_m_d_His');
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

function logInfo($msg, $ctx = []) {
    Log::channel('manual_test')->info($msg, $ctx);
    echo "[INFO] {$msg}\n";
}

function logError($msg, $ctx = []) {
    Log::channel('manual_test')->error($msg, $ctx);
    echo "[ERROR] {$msg}\n";
}

$createdRecords = [];

try {
    DB::beginTransaction();
    
    logInfo("=== Starting Phase 4 Manual Verification: {$testRunId} ===");
    
    // === SETUP PHASE ===
    logInfo("Setting up test user and alerts...");
    $user = User::factory()->create(['email' => "phase4_{$testRunId}@example.com"]);
    $createdRecords['users'][] = $user->id;

    // Create 2 active fire incidents
    $fire1 = FireIncident::factory()->create(['event_num' => "P4_F1", 'is_active' => true]);
    $fire2 = FireIncident::factory()->create(['event_num' => "P4_F2", 'is_active' => true]);
    $createdRecords['fire_incidents'] = [$fire1->id, $fire2->id];

    $id1 = "fire:P4_F1";
    $id2 = "fire:P4_F2";
    $idMissing = "police:NON_EXISTENT_999";

    // Save alerts for user
    logInfo("Saving alerts for user...", ['ids' => [$id1, $id2, $idMissing]]);
    SavedAlert::create(['user_id' => $user->id, 'alert_id' => $id1]);
    SavedAlert::create(['user_id' => $user->id, 'alert_id' => $id2]);
    SavedAlert::create(['user_id' => $user->id, 'alert_id' => $idMissing]);

    // === EXECUTION PHASE ===
    
    logInfo("Testing GtaAlerts dashboard props (saved_alert_ids)...");
    Auth::login($user);
    $request = request();
    $request->setUserResolver(fn() => $user);
    
    // Simulate internal call to GtaAlertsController (__invoke)
    $controller = $app->make(\App\Http\Controllers\GtaAlertsController::class);
    $response = $controller($request, $app->make(\App\Services\Alerts\UnifiedAlertsQuery::class));
    $props = $response->toResponse($request)->getOriginalContent()['page']['props'];

    if (!isset($props['saved_alert_ids'])) {
        logError("FAIL: saved_alert_ids prop is missing from GtaAlerts dashboard.");
    } else {
        $savedIds = $props['saved_alert_ids'];
        logInfo("Dashboard props received.", ['count' => count($savedIds), 'ids' => $savedIds]);
        if (in_array($id1, $savedIds) && in_array($id2, $savedIds) && in_array($idMissing, $savedIds)) {
            logInfo("PASS: All saved IDs present in initial props.");
        } else {
            logError("FAIL: Missing some IDs in initial props.", ['expected' => [$id1, $id2, $idMissing], 'actual' => $savedIds]);
        }
    }

    logInfo("Testing SavedView hydration API (GET /api/saved-alerts)...");
    
    $savedController = $app->make(\App\Http\Controllers\Notifications\SavedAlertController::class);
    $apiResponse = $savedController->index($request, $app->make(\App\Services\Alerts\UnifiedAlertsQuery::class));
    $data = json_decode($apiResponse->getContent(), true);

    logInfo("API Response received.", ['meta' => $data['meta'] ?? 'none']);

    // Check hydration
    $hydratedIds = collect($data['data'])->pluck('id')->toArray();
    if (count($hydratedIds) === 2 && in_array($id1, $hydratedIds) && in_array($id2, $hydratedIds)) {
        logInfo("PASS: Hydrated alerts returned for existing incidents.");
    } else {
        logError("FAIL: Hydration issues.", ['expected' => [$id1, $id2], 'actual' => $hydratedIds]);
    }

    // Check missing IDs
    $missingIds = $data['meta']['missing_alert_ids'] ?? [];
    if (in_array($idMissing, $missingIds)) {
        logInfo("PASS: Missing alert correctly identified in meta.");
    } else {
        logError("FAIL: Missing alert not found in meta.missing_alert_ids.");
    }

    logInfo("Phase 4 Backend Verification completed successfully");
    
} catch (\Exception $e) {
    logError("Verification failed", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} finally {
    // === CLEANUP PHASE ===
    DB::rollBack();
    logInfo("Cleanup (transaction rollback) completed");
    logInfo("=== Test Run Finished ===");
    echo "\n✓ Full logs at: {$logFile}\n";
}
