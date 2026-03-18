<?php

/**
 * Manual Test: Saved Alerts Phase 5 Quality Gates
 * Generated: 2026-03-18
 * Purpose: Verify all saved-alerts API flows against the GTA-105 acceptance criteria:
 * 1. Guest flow — Inertia props return empty saved_alert_ids for unauthenticated users.
 * 2. Auth save flow — POST /api/saved-alerts returns 201; duplicate returns 409.
 * 3. Auth delete flow — DELETE /api/saved-alerts/{id} returns 200 with meta.deleted=true.
 * 4. Ownership scoping — a user cannot delete another user's saved alert.
 * 5. SavedView hydration — GET /api/saved-alerts returns hydrated UnifiedAlertResource payloads.
 * 6. Unresolved IDs — meta.missing_alert_ids surfaces IDs that cannot be hydrated.
 * 7. Invalid alert_id validation — store returns 422 for malformed alert_id.
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

use App\Http\Controllers\Notifications\SavedAlertController;
use App\Http\Requests\Notifications\SavedAlertStoreRequest;
use App\Models\FireIncident;
use App\Models\SavedAlert;
use App\Models\User;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$testRunId = 'test_phase_5_'.Carbon::now()->format('Y_m_d_His');
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

function logPass($label)
{
    Log::channel('manual_test')->info("PASS: {$label}");
    echo "[PASS] {$label}\n";
}

function logFail($label, $ctx = [])
{
    Log::channel('manual_test')->error("FAIL: {$label}", $ctx);
    echo "[FAIL] {$label}\n";
    if ($ctx) {
        foreach ($ctx as $k => $v) {
            echo "       {$k}: ".json_encode($v)."\n";
        }
    }
}

/** Build a Request instance bound to the given user. */
function makeRequest(array $data = [], ?object $user = null): Request
{
    $request = Request::create('/api/saved-alerts', 'POST', $data);
    if ($user !== null) {
        $request->setUserResolver(fn () => $user);
    }

    return $request;
}

/** Build a SavedAlertStoreRequest bound to the given user and data, fully validated. */
function makeStoreRequest(array $data, object $user): SavedAlertStoreRequest
{
    $app = app();
    $request = SavedAlertStoreRequest::create('/api/saved-alerts', 'POST', $data);
    $request->setUserResolver(fn () => $user);
    $request->setContainer($app);
    $request->setRedirector($app->make(\Illuminate\Routing\Redirector::class));
    $request->validateResolved();

    return $request;
}

$passCount = 0;
$failCount = 0;

$savedController = app(SavedAlertController::class);
$alertsQuery = app(UnifiedAlertsQuery::class);
$gtaController = app(\App\Http\Controllers\GtaAlertsController::class);

try {
    DB::beginTransaction();

    logInfo("=== Starting Phase 5 Manual Verification: {$testRunId} ===");

    // -----------------------------------------------------------------------
    // SETUP
    // -----------------------------------------------------------------------
    logInfo('Setting up test data...');

    $userA = User::factory()->create(['email' => "p5_a_{$testRunId}@example.com"]);
    $userB = User::factory()->create(['email' => "p5_b_{$testRunId}@example.com"]);

    $eventNum1 = "P5_F1_{$testRunId}";
    $eventNum2 = "P5_F2_{$testRunId}";
    $fire1 = FireIncident::factory()->create(['event_num' => $eventNum1, 'is_active' => true]);
    $fire2 = FireIncident::factory()->create(['event_num' => $eventNum2, 'is_active' => true]);

    $alertId1 = "fire:{$eventNum1}";
    $alertId2 = "fire:{$eventNum2}";
    $alertIdMissing = "police:NON_EXISTENT_{$testRunId}";

    logInfo('Test data created.', ['userA' => $userA->id, 'userB' => $userB->id, 'alerts' => [$alertId1, $alertId2]]);

    // -----------------------------------------------------------------------
    // TEST 1: Guest flow — Inertia props contain empty saved_alert_ids
    // -----------------------------------------------------------------------
    logInfo('--- Test 1: Guest flow (unauthenticated Inertia props) ---');

    Auth::logout();
    $guestRequest = Request::create('/', 'GET');
    $guestRequest->setUserResolver(fn () => null);

    $gtaResponse = $gtaController($guestRequest, $alertsQuery);
    $props = $gtaResponse->toResponse($guestRequest)->getOriginalContent()['page']['props'];

    if (isset($props['saved_alert_ids']) && $props['saved_alert_ids'] === []) {
        logPass('Test 1: Guest receives empty saved_alert_ids in initial props.');
        $passCount++;
    } else {
        logFail('Test 1: Guest saved_alert_ids mismatch.', ['actual' => $props['saved_alert_ids'] ?? 'KEY_MISSING']);
        $failCount++;
    }

    // -----------------------------------------------------------------------
    // TEST 2: Auth save flow — POST returns 201
    // -----------------------------------------------------------------------
    logInfo('--- Test 2: Authenticated save (POST 201) ---');

    Auth::login($userA);

    $storeRequest = makeStoreRequest(['alert_id' => $alertId1], $userA);
    $storeResponse = $savedController->store($storeRequest);

    if ($storeResponse->getStatusCode() === 201) {
        $body = json_decode($storeResponse->getContent(), true);
        if (($body['data']['alert_id'] ?? '') === $alertId1) {
            logPass('Test 2: POST /api/saved-alerts returns 201 with correct alert_id.');
            $passCount++;
        } else {
            logFail('Test 2: 201 received but alert_id mismatch in body.', ['body' => $body]);
            $failCount++;
        }
    } else {
        logFail('Test 2: Expected 201, got '.$storeResponse->getStatusCode().'.');
        $failCount++;
    }

    // -----------------------------------------------------------------------
    // TEST 3: Auth save flow — duplicate POST returns 409
    // -----------------------------------------------------------------------
    logInfo('--- Test 3: Duplicate save (POST 409) ---');

    $dupeRequest = makeStoreRequest(['alert_id' => $alertId1], $userA);
    $dupeResponse = $savedController->store($dupeRequest);

    if ($dupeResponse->getStatusCode() === 409) {
        logPass('Test 3: Duplicate POST /api/saved-alerts returns 409.');
        $passCount++;
    } else {
        logFail('Test 3: Expected 409, got '.$dupeResponse->getStatusCode().'.');
        $failCount++;
    }

    // -----------------------------------------------------------------------
    // TEST 4: Auth delete flow — DELETE returns 200 with meta.deleted=true
    // -----------------------------------------------------------------------
    logInfo('--- Test 4: Authenticated delete (DELETE 200 meta.deleted=true) ---');

    // Save alert2 so we have something to delete
    SavedAlert::create(['user_id' => $userA->id, 'alert_id' => $alertId2]);

    $deleteRequest = makeRequest([], $userA);
    $deleteResponse = $savedController->destroy($deleteRequest, $alertId2);

    if ($deleteResponse->getStatusCode() === 200) {
        $deleteBody = json_decode($deleteResponse->getContent(), true);
        if (($deleteBody['meta']['deleted'] ?? false) === true) {
            logPass('Test 4: DELETE /api/saved-alerts/{id} returns 200 with meta.deleted=true.');
            $passCount++;
        } else {
            logFail('Test 4: 200 received but meta.deleted not true.', ['body' => $deleteBody]);
            $failCount++;
        }
    } else {
        logFail('Test 4: Expected 200, got '.$deleteResponse->getStatusCode().'.');
        $failCount++;
    }

    // -----------------------------------------------------------------------
    // TEST 5: Ownership scoping — user cannot delete another user's saved alert
    // -----------------------------------------------------------------------
    logInfo('--- Test 5: Ownership scoping (cross-user delete blocked) ---');

    // Save alert2 as userB
    SavedAlert::create(['user_id' => $userB->id, 'alert_id' => $alertId2]);

    // Try to delete it as userA — should 404 (firstOrFail)
    $crossDeleteRequest = makeRequest([], $userA);
    try {
        $savedController->destroy($crossDeleteRequest, $alertId2);
        // alert2 was already deleted by userA in test 4, so userA's row doesn't exist.
        // The above will 404 already. But explicitly test userB's row is not deleted.
        logFail('Test 5: Expected ModelNotFoundException, but no exception was thrown.');
        $failCount++;
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        // Verify userB's record still exists
        $stillExists = SavedAlert::where('user_id', $userB->id)->where('alert_id', $alertId2)->exists();
        if ($stillExists) {
            logPass("Test 5: Cross-user delete correctly blocked; userB's saved alert is intact.");
            $passCount++;
        } else {
            logFail("Test 5: Cross-user delete raised exception but userB's record is missing.");
            $failCount++;
        }
    }

    // -----------------------------------------------------------------------
    // TEST 6: SavedView hydration — GET returns hydrated resources
    // -----------------------------------------------------------------------
    logInfo('--- Test 6: SavedView hydration (GET hydrated resources) ---');

    // userA now has only alertId1 saved (alertId2 was deleted in test 4).
    // Add the missing-ID to userA so we can check meta.missing_alert_ids too.
    SavedAlert::create(['user_id' => $userA->id, 'alert_id' => $alertIdMissing]);

    $indexRequest = makeRequest([], $userA);
    $indexResponse = $savedController->index($indexRequest, $alertsQuery);
    $indexBody = json_decode($indexResponse->getContent(), true);

    $hydratedIds = collect($indexBody['data'] ?? [])->pluck('id')->toArray();

    if (in_array($alertId1, $hydratedIds)) {
        logPass('Test 6a: alert1 is hydrated in GET /api/saved-alerts response.');
        $passCount++;
    } else {
        logFail('Test 6a: alert1 not found in hydrated data.', ['hydrated' => $hydratedIds]);
        $failCount++;
    }

    // -----------------------------------------------------------------------
    // TEST 7: Unresolved IDs — meta.missing_alert_ids surfaces stale references
    // -----------------------------------------------------------------------
    logInfo('--- Test 7: Unresolved IDs surfaced in meta.missing_alert_ids ---');

    $missingIds = $indexBody['meta']['missing_alert_ids'] ?? [];

    if (in_array($alertIdMissing, $missingIds)) {
        logPass('Test 7: Unresolvable alert_id correctly listed in meta.missing_alert_ids.');
        $passCount++;
    } else {
        logFail('Test 7: Unresolvable alert_id not found in meta.missing_alert_ids.', [
            'expected' => $alertIdMissing,
            'actual' => $missingIds,
        ]);
        $failCount++;
    }

    // -----------------------------------------------------------------------
    // TEST 8: Invalid alert_id validation — store returns 422
    // -----------------------------------------------------------------------
    logInfo('--- Test 8: Invalid alert_id validation (422) ---');

    try {
        // makeStoreRequest calls validateResolved() — must throw for a bad alert_id.
        makeStoreRequest(['alert_id' => 'not-a-valid-format'], $userA);
        logFail('Test 8: Expected ValidationException for malformed alert_id, none thrown.');
        $failCount++;
    } catch (\Illuminate\Validation\ValidationException $ve) {
        $errors = $ve->errors();
        if (isset($errors['alert_id'])) {
            logPass('Test 8: Invalid alert_id correctly fails validation with alert_id error.');
            $passCount++;
        } else {
            logFail('Test 8: ValidationException thrown but no alert_id field error.', ['errors' => $errors]);
            $failCount++;
        }
    }

    // -----------------------------------------------------------------------
    // SUMMARY
    // -----------------------------------------------------------------------
    logInfo("=== Phase 5 Verification Summary ===", [
        'passed' => $passCount,
        'failed' => $failCount,
        'total' => $passCount + $failCount,
    ]);

    if ($failCount === 0) {
        logInfo("All {$passCount} tests PASSED.");
    } else {
        logError("{$failCount} test(s) FAILED. See above for details.");
    }

} catch (\Exception $e) {
    logError('Verification aborted with unexpected exception', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
} finally {
    DB::rollBack();
    logInfo('Cleanup (transaction rollback) completed');
    logInfo('=== Test Run Finished ===');
    echo "\n✓ Full logs at: {$logFile}\n";
}
