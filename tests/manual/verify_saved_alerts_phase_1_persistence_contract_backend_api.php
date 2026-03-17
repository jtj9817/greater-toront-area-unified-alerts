<?php

/**
 * Manual Test: Saved Alerts – Phase 1 Persistence Contract & Backend API (GTA-101)
 * Generated: 2026-03-17
 * Purpose: Verify Phase 1 deliverables for the saved-alerts feature:
 * - saved_alerts table schema and indexes
 * - SavedAlert model fillable + user() relation
 * - User::savedAlerts() HasMany relation
 * - SavedAlertStoreRequest alert_id validation (AlertId contract)
 * - POST /api/saved-alerts: 201 created, 409 duplicate, all sources
 * - GET /api/saved-alerts: response shape, scoping, newest-first ordering
 * - DELETE /api/saved-alerts/{alertId}: 200 meta.deleted, 404 not-owned/missing
 * - All endpoints require authentication (401 when unauthenticated)
 * - Authenticated saves are uncapped in this iteration
 */

require __DIR__.'/../../vendor/autoload.php';

// Default manual verification runs to testing so Laravel loads `.env.testing`.
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}

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

use App\Http\Controllers\Notifications\SavedAlertController;
use App\Http\Requests\Notifications\SavedAlertStoreRequest;
use App\Models\SavedAlert;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

$testRunId = 'saved_alerts_phase_1_'.Carbon::now()->format('Y_m_d_His');
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

if (! @chmod($logFile, 0664)) {
    fwrite(STDERR, "Warning: Failed to set permissions on log file: {$logFile}\n");
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

function logInfo(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->info($msg, $ctx);
    $suffix = $ctx === [] ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES);
    echo "[INFO] {$msg}{$suffix}\n";
}

function logError(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->error($msg, $ctx);
    $suffix = $ctx === [] ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES);
    echo "[ERROR] {$msg}{$suffix}\n";
}

function assertTrue(bool $condition, string $label, array $ctx = []): void
{
    if (! $condition) {
        $message = "Assertion failed: {$label}.";
        logError($message, $ctx);
        throw new RuntimeException($message);
    }
    logInfo("Assertion passed: {$label}");
}

function assertEqual(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        $message = "Assertion failed for {$label}.";
        logError($message, ['expected' => $expected, 'actual' => $actual]);
        throw new RuntimeException($message);
    }
    logInfo("Assertion passed: {$label}");
}

/**
 * @return array<string, mixed>
 */
function decodeJsonResponse(JsonResponse $response): array
{
    $decoded = $response->getData(true);
    assertTrue(is_array($decoded), 'response payload decodes to array');

    return $decoded;
}

/**
 * Build a SavedAlertStoreRequest with the given payload and authenticated user.
 *
 * Note: do NOT set CONTENT_TYPE to application/json here. When that header is
 * present, Laravel's Request::isJson() returns true and getInputSource() reads
 * from the JSON body (empty in create() calls) instead of $this->request. The
 * form-data parameters would be invisible to $this->input() / $this->all().
 */
function makeStoreRequest(User $user, array $payload): SavedAlertStoreRequest
{
    $request = SavedAlertStoreRequest::create(
        '/api/saved-alerts',
        'POST',
        $payload,
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json']
    );

    $request->setContainer(app());
    $request->setRedirector(app('redirect'));
    $request->setUserResolver(static fn (): User => $user);

    return $request;
}

/**
 * Build a plain Request authenticated as the given user.
 */
function makeAuthRequest(User $user, string $method, string $uri, array $params = []): Illuminate\Http\Request
{
    $request = Illuminate\Http\Request::create(
        $uri,
        $method,
        $params,
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json']
    );
    $request->setUserResolver(static fn (): User => $user);

    return $request;
}

$exitCode = 0;
$txStarted = false;

try {
    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        throw new RuntimeException(
            "Database connection failed. If you're using Sail, run: ./scripts/init-testing-environment.sh",
            previous: $e
        );
    }

    logInfo('Boot context', [
        'app_env' => app()->environment(),
        'db_connection' => $connection,
        'db_database' => $currentDatabase,
    ]);

    if (! Schema::hasTable('saved_alerts')) {
        logInfo('saved_alerts table missing; running migrations for testing database');
        Artisan::call('migrate', ['--force' => true]);
        logInfo('Migration output', ['output' => trim(Artisan::output())]);
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: Saved Alerts Phase 1 – Persistence Contract & Backend API ===');

    // =========================================================================
    // SECTION 1: Schema and index verification
    // =========================================================================
    logInfo('--- Section 1: saved_alerts table schema and indexes ---');

    assertTrue(Schema::hasTable('saved_alerts'), 'saved_alerts table exists');

    assertTrue(Schema::hasColumns('saved_alerts', [
        'id',
        'user_id',
        'alert_id',
        'created_at',
        'updated_at',
    ]), 'saved_alerts has all required columns');

    // Verify the unique constraint on (user_id, alert_id)
    assertTrue(
        Schema::hasIndex('saved_alerts', ['user_id', 'alert_id']),
        'saved_alerts has unique index on user_id + alert_id'
    );

    // Verify the compound index supporting newest-first retrieval (user_id, id)
    assertTrue(
        Schema::hasIndex('saved_alerts', ['user_id', 'id']),
        'saved_alerts has compound index on user_id + id for newest-first retrieval'
    );

    logInfo('Section 1 complete.');

    // =========================================================================
    // SECTION 2: Model and relationship verification
    // =========================================================================
    logInfo('--- Section 2: Model fillable and relationships ---');

    $modelInstance = new SavedAlert;
    assertEqual(
        $modelInstance->getFillable(),
        ['user_id', 'alert_id'],
        'SavedAlert fillable is [user_id, alert_id]'
    );

    $userA = User::factory()->create();
    $userB = User::factory()->create();

    // User::savedAlerts() HasMany
    $savedForA = SavedAlert::factory()->count(3)->create(['user_id' => $userA->id]);
    SavedAlert::factory()->create(['user_id' => $userB->id]);

    $freshUserA = $userA->fresh();
    assertTrue($freshUserA->savedAlerts instanceof \Illuminate\Database\Eloquent\Collection, 'User::savedAlerts returns a Collection');
    assertEqual($freshUserA->savedAlerts->count(), 3, 'savedAlerts() scopes to owner user');
    assertTrue($freshUserA->savedAlerts->first() instanceof SavedAlert, 'savedAlerts items are SavedAlert instances');
    assertEqual(
        $freshUserA->savedAlerts->pluck('user_id')->unique()->values()->all(),
        [$userA->id],
        'all savedAlerts belong to the same user'
    );

    // SavedAlert::user() BelongsTo
    $oneAlert = $savedForA->first()->fresh();
    assertTrue($oneAlert->user instanceof User, 'SavedAlert::user() returns a User instance');
    assertEqual($oneAlert->user->id, $userA->id, 'SavedAlert::user() returns the correct owner');

    logInfo('Section 2 complete.');

    // =========================================================================
    // SECTION 3: SavedAlertStoreRequest – alert_id validation
    // =========================================================================
    logInfo('--- Section 3: SavedAlertStoreRequest validation ---');

    $controller = app(SavedAlertController::class);

    // 3a. Missing alert_id
    try {
        $req = makeStoreRequest($userA, []);
        $req->validateResolved();
        throw new RuntimeException('Expected validation failure for missing alert_id');
    } catch (ValidationException $e) {
        assertTrue(isset($e->errors()['alert_id']), 'validation rejects missing alert_id');
    }

    // 3b. Non-string alert_id (array)
    try {
        $req = makeStoreRequest($userA, ['alert_id' => []]);
        $req->validateResolved();
        throw new RuntimeException('Expected validation failure for non-string alert_id');
    } catch (ValidationException $e) {
        assertTrue(isset($e->errors()['alert_id']), 'validation rejects non-string alert_id');
    }

    // Helper: assert that a ValidationException's alert_id errors are from the
    // AlertId closure (format error), NOT from the 'required' rule.
    $assertAlertIdFormatError = function (ValidationException $e, string $label): void {
        $errors = $e->errors()['alert_id'] ?? [];
        assertTrue(! empty($errors), "validation rejects {$label}");
        assertTrue(
            ! in_array('The alert_id field is required.', $errors, true),
            "rejection for {$label} is an AlertId format error, not a missing-field error",
            ['actual_errors' => $errors]
        );
    };

    // 3c. No colon separator
    try {
        $req = makeStoreRequest($userA, ['alert_id' => 'fireF26018618']);
        $req->validateResolved();
        throw new RuntimeException('Expected validation failure for alert_id without colon');
    } catch (ValidationException $e) {
        $assertAlertIdFormatError($e, 'alert_id without colon separator');
    }

    // 3d. Invalid source
    try {
        $req = makeStoreRequest($userA, ['alert_id' => 'unknown:F26018618']);
        $req->validateResolved();
        throw new RuntimeException('Expected validation failure for invalid source');
    } catch (ValidationException $e) {
        $assertAlertIdFormatError($e, 'invalid source in alert_id');
    }

    // 3e. Empty externalId (fire:)
    try {
        $req = makeStoreRequest($userA, ['alert_id' => 'fire:']);
        $req->validateResolved();
        throw new RuntimeException('Expected validation failure for empty externalId');
    } catch (ValidationException $e) {
        $assertAlertIdFormatError($e, 'alert_id with empty externalId');
    }

    // 3f. Empty source (:F26018618)
    try {
        $req = makeStoreRequest($userA, ['alert_id' => ':F26018618']);
        $req->validateResolved();
        throw new RuntimeException('Expected validation failure for empty source');
    } catch (ValidationException $e) {
        $assertAlertIdFormatError($e, 'alert_id with empty source');
    }

    // 3g. Whitespace normalization: padded valid value should pass (prepareForValidation trims)
    $paddedReq = makeStoreRequest($userA, ['alert_id' => '  fire:TESTPAD001  ']);
    $paddedReq->validateResolved();
    assertEqual($paddedReq->validated('alert_id'), 'fire:TESTPAD001', 'prepareForValidation trims whitespace before validation');

    // 3h. All four valid sources pass validation
    foreach (['fire:ID001', 'police:ID002', 'transit:ID003', 'go_transit:ID004'] as $validId) {
        $req = makeStoreRequest($userA, ['alert_id' => $validId]);
        $req->validateResolved();
        assertEqual($req->validated('alert_id'), $validId, "validation accepts valid alert_id: {$validId}");
    }

    logInfo('Section 3 complete.');

    // =========================================================================
    // SECTION 4: POST /api/saved-alerts – store
    // =========================================================================
    logInfo('--- Section 4: POST /api/saved-alerts (store) ---');

    // 4a. Route exists and requires auth
    $storeRoute = Route::getRoutes()->getByName('api.saved-alerts.store');
    assertTrue($storeRoute !== null, 'api.saved-alerts.store route is registered');
    if ($storeRoute !== null) {
        assertEqual($storeRoute->uri(), 'api/saved-alerts', 'store route URI is api/saved-alerts');
        assertTrue(in_array('POST', $storeRoute->methods(), true), 'store route accepts POST');
        assertTrue(in_array('auth', $storeRoute->gatherMiddleware(), true), 'store route is protected by auth middleware');
    }

    // 4b. Valid store for all sources
    $testAlerts = [
        'fire'       => 'fire:F26011111',
        'police'     => 'police:P12340001',
        'transit'    => 'transit:T99880001',
        'go_transit' => 'go_transit:GT000001',
    ];

    $userC = User::factory()->create();

    foreach ($testAlerts as $source => $alertId) {
        $req = makeStoreRequest($userC, ['alert_id' => $alertId]);
        $req->validateResolved();

        $response = $controller->store($req);
        assertEqual($response->getStatusCode(), 201, "store returns 201 for source: {$source}");

        $payload = decodeJsonResponse($response);
        assertTrue(isset($payload['data']['id']), "store response contains data.id for source: {$source}");
        assertEqual($payload['data']['alert_id'] ?? null, $alertId, "store response data.alert_id matches input for source: {$source}");
        assertTrue(isset($payload['data']['saved_at']), "store response contains data.saved_at for source: {$source}");
        assertValidIso8601($payload['data']['saved_at'] ?? '', "data.saved_at is a valid ISO 8601 timestamp for source: {$source}");

        assertTrue(
            SavedAlert::query()->where('user_id', $userC->id)->where('alert_id', $alertId)->exists(),
            "saved_alerts row exists in database for source: {$source}"
        );
    }

    // 4c. Duplicate save returns 409 (application-layer check)
    $dupAlertId = 'fire:DUPCHECK01';
    $dupReq1 = makeStoreRequest($userC, ['alert_id' => $dupAlertId]);
    $dupReq1->validateResolved();
    $dupResponse1 = $controller->store($dupReq1);
    assertEqual($dupResponse1->getStatusCode(), 201, 'first save of duplicate alert returns 201');

    $dupReq2 = makeStoreRequest($userC, ['alert_id' => $dupAlertId]);
    $dupReq2->validateResolved();
    $dupResponse2 = $controller->store($dupReq2);
    assertEqual($dupResponse2->getStatusCode(), 409, 'second save of same alert returns 409');

    $dupPayload = decodeJsonResponse($dupResponse2);
    assertTrue(isset($dupPayload['message']), '409 response includes a message');

    assertEqual(
        SavedAlert::query()->where('user_id', $userC->id)->where('alert_id', $dupAlertId)->count(),
        1,
        'no duplicate row created after 409 response'
    );

    // 4d. DB unique constraint as race-condition backstop
    // Simulate the race: bypass the application-layer check by inserting a second row
    // directly, then ensure UniqueConstraintViolationException is the outcome.
    $raceAlertId = 'police:RACECHK01';
    SavedAlert::query()->create(['user_id' => $userC->id, 'alert_id' => $raceAlertId]);
    try {
        // Force a raw insert to trigger the DB constraint
        DB::table('saved_alerts')->insert([
            'user_id'    => $userC->id,
            'alert_id'   => $raceAlertId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        throw new RuntimeException('Expected DB unique constraint violation was not raised');
    } catch (UniqueConstraintViolationException) {
        logInfo('Assertion passed: DB unique constraint raises UniqueConstraintViolationException on race insert');
    }

    logInfo('Section 4 complete.');

    // =========================================================================
    // SECTION 5: GET /api/saved-alerts – index
    // =========================================================================
    logInfo('--- Section 5: GET /api/saved-alerts (index) ---');

    // 5a. Route exists and requires auth
    $indexRoute = Route::getRoutes()->getByName('api.saved-alerts.index');
    assertTrue($indexRoute !== null, 'api.saved-alerts.index route is registered');
    if ($indexRoute !== null) {
        assertEqual($indexRoute->uri(), 'api/saved-alerts', 'index route URI is api/saved-alerts');
        assertTrue(in_array('GET', $indexRoute->methods(), true), 'index route accepts GET');
        assertTrue(in_array('auth', $indexRoute->gatherMiddleware(), true), 'index route is protected by auth middleware');
    }

    // 5b. Empty list for a user with no saved alerts
    $userEmpty = User::factory()->create();
    $emptyIndexReq = makeAuthRequest($userEmpty, 'GET', '/api/saved-alerts');
    $emptyResponse = $controller->index($emptyIndexReq);
    assertEqual($emptyResponse->getStatusCode(), 200, 'index returns 200 for user with no saved alerts');

    $emptyPayload = decodeJsonResponse($emptyResponse);
    assertTrue(isset($emptyPayload['data']), 'index response contains data key');
    assertTrue(isset($emptyPayload['meta']), 'index response contains meta key');
    assertTrue(isset($emptyPayload['meta']['saved_ids']), 'index meta contains saved_ids');
    assertTrue(isset($emptyPayload['meta']['missing_alert_ids']), 'index meta contains missing_alert_ids');
    assertEqual($emptyPayload['data'], [], 'empty user data is an empty array');
    assertEqual($emptyPayload['meta']['saved_ids'], [], 'empty user saved_ids is an empty array');
    assertEqual($emptyPayload['meta']['missing_alert_ids'], [], 'missing_alert_ids defaults to empty array');

    // 5c. Populated list with correct shape and newest-first ordering
    $userD = User::factory()->create();
    $olderSaved = SavedAlert::factory()->create(['user_id' => $userD->id, 'alert_id' => 'fire:OLDER001']);
    $newerSaved = SavedAlert::factory()->create(['user_id' => $userD->id, 'alert_id' => 'police:NEWER001']);

    $indexReq = makeAuthRequest($userD, 'GET', '/api/saved-alerts');
    $indexResponse = $controller->index($indexReq);
    assertEqual($indexResponse->getStatusCode(), 200, 'index returns 200 for populated user');

    $indexPayload = decodeJsonResponse($indexResponse);
    assertEqual(count($indexPayload['data']), 2, 'index data contains 2 items');

    // Newest first (descending id order)
    assertEqual($indexPayload['data'][0]['alert_id'] ?? null, 'police:NEWER001', 'first data item is the most recently saved alert');
    assertEqual($indexPayload['data'][1]['alert_id'] ?? null, 'fire:OLDER001', 'second data item is the older saved alert');

    // Each item has the required fields
    foreach ($indexPayload['data'] as $item) {
        assertTrue(isset($item['id']), 'data item has id field');
        assertTrue(isset($item['alert_id']), 'data item has alert_id field');
        assertTrue(isset($item['saved_at']), 'data item has saved_at field');
        assertValidIso8601($item['saved_at'] ?? '', 'data item saved_at is a valid ISO 8601 timestamp');
    }

    // saved_ids in meta matches the saved alerts
    $savedIds = $indexPayload['meta']['saved_ids'];
    assertTrue(in_array('fire:OLDER001', $savedIds, true), 'meta.saved_ids contains fire:OLDER001');
    assertTrue(in_array('police:NEWER001', $savedIds, true), 'meta.saved_ids contains police:NEWER001');

    // 5d. Scoping: other user's alerts are not visible
    $userOther = User::factory()->create();
    SavedAlert::factory()->create(['user_id' => $userOther->id, 'alert_id' => 'transit:OTHER001']);

    $scopeIndexReq = makeAuthRequest($userD, 'GET', '/api/saved-alerts');
    $scopeIndexPayload = decodeJsonResponse($controller->index($scopeIndexReq));

    assertTrue(
        ! in_array('transit:OTHER001', $scopeIndexPayload['meta']['saved_ids'], true),
        'index does not expose other users saved alerts'
    );
    assertEqual(count($scopeIndexPayload['data']), 2, 'index data count is unchanged after other user adds a saved alert');

    logInfo('Section 5 complete.');

    // =========================================================================
    // SECTION 6: DELETE /api/saved-alerts/{alertId} – destroy
    // =========================================================================
    logInfo('--- Section 6: DELETE /api/saved-alerts/{alertId} (destroy) ---');

    // 6a. Route exists and requires auth
    $destroyRoute = Route::getRoutes()->getByName('api.saved-alerts.destroy');
    assertTrue($destroyRoute !== null, 'api.saved-alerts.destroy route is registered');
    if ($destroyRoute !== null) {
        assertTrue(in_array('DELETE', $destroyRoute->methods(), true), 'destroy route accepts DELETE');
        assertTrue(in_array('auth', $destroyRoute->gatherMiddleware(), true), 'destroy route is protected by auth middleware');
    }

    // 6b. Successful delete
    $userE = User::factory()->create();
    SavedAlert::factory()->create(['user_id' => $userE->id, 'alert_id' => 'fire:DEL001']);

    $destroyReq = makeAuthRequest($userE, 'DELETE', '/api/saved-alerts/fire:DEL001');
    $destroyResponse = $controller->destroy($destroyReq, 'fire:DEL001');
    assertEqual($destroyResponse->getStatusCode(), 200, 'destroy returns 200 on success');

    $destroyPayload = decodeJsonResponse($destroyResponse);
    assertTrue(($destroyPayload['meta']['deleted'] ?? false) === true, 'destroy response meta.deleted is true');

    assertTrue(
        ! SavedAlert::query()->where('user_id', $userE->id)->where('alert_id', 'fire:DEL001')->exists(),
        'saved_alerts row is removed from database after destroy'
    );

    // 6c. 404 when alert is not saved (not-found)
    try {
        $notFoundReq = makeAuthRequest($userE, 'DELETE', '/api/saved-alerts/fire:NOTFOUND');
        $controller->destroy($notFoundReq, 'fire:NOTFOUND');
        throw new RuntimeException('Expected 404 ModelNotFoundException was not thrown');
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        logInfo('Assertion passed: destroy throws ModelNotFoundException for non-existent saved alert');
    }

    // 6d. Owner scoping: other user cannot delete someone else's saved alert
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    SavedAlert::factory()->create(['user_id' => $owner->id, 'alert_id' => 'police:OWNED001']);

    try {
        $intruderReq = makeAuthRequest($intruder, 'DELETE', '/api/saved-alerts/police:OWNED001');
        $controller->destroy($intruderReq, 'police:OWNED001');
        throw new RuntimeException('Expected 404 for cross-user delete attempt was not thrown');
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        logInfo('Assertion passed: destroy scopes delete to owner; other user gets 404');
    }

    assertTrue(
        SavedAlert::query()->where('user_id', $owner->id)->where('alert_id', 'police:OWNED001')->exists(),
        "owner's saved alert still exists after unauthorized delete attempt"
    );

    logInfo('Section 6 complete.');

    // =========================================================================
    // SECTION 7: Uncapped authenticated saves
    // =========================================================================
    logInfo('--- Section 7: Authenticated saves are uncapped ---');

    $userUnlimited = User::factory()->create();
    $sources = ['fire', 'police', 'transit', 'go_transit'];

    for ($i = 1; $i <= 25; $i++) {
        $src = $sources[($i - 1) % count($sources)];
        SavedAlert::factory()->create(['user_id' => $userUnlimited->id, 'alert_id' => "{$src}:UNLIM{$i}"]);
    }

    $unlimitedIndexReq = makeAuthRequest($userUnlimited, 'GET', '/api/saved-alerts');
    $unlimitedPayload = decodeJsonResponse($controller->index($unlimitedIndexReq));

    assertEqual(count($unlimitedPayload['meta']['saved_ids']), 25, 'authenticated user can save 25+ alerts without hitting a cap');
    assertEqual(count($unlimitedPayload['data']), 25, 'all 25 saved alerts are returned in data');

    logInfo('Section 7 complete.');

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'file'    => $e->getFile(),
        'line'    => $e->getLine(),
    ]);
} finally {
    if ($txStarted && DB::transactionLevel() > 0) {
        DB::rollBack();
        logInfo('Rolled back transaction; no persistent data changes were kept.');
    }

    logInfo('Manual test log file', ['path' => $logFileRelative]);
}

exit($exitCode);

// =========================================================================
// Helpers
// =========================================================================

function assertValidIso8601(string $value, string $label): void
{
    $dt = DateTime::createFromFormat(DateTime::ATOM, $value);
    assertTrue($dt !== false, $label, ['value' => $value]);
}
