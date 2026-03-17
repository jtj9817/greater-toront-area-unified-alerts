<?php

/**
 * Manual Test: Saved Alerts – Phase 3 Frontend Saved Alert State Contract (GTA-103)
 * Generated: 2026-03-17
 * Purpose: Verify the backend contracts that the Phase 3 TypeScript frontend layer
 * (SavedAlertService.ts + useSavedAlerts.ts) depends on:
 * - POST /api/saved-alerts: 201 success, 409 duplicate, 422 validation, 401 auth guard
 * - DELETE /api/saved-alerts/{alertId}: 200 meta.deleted, 404 not-found/ownership, URL-encoded ID
 * - GET /api/saved-alerts: hydrated UnifiedAlertResource shape with meta.saved_ids + meta.missing_alert_ids
 * - GtaAlertsController: saved_alert_ids: [] for guests, newest-first composite IDs for auth users
 * - HTTP status → SavedAlertServiceError kind mapping: 409→duplicate, 401→auth, 422→validation, 404→unknown
 * - Authenticated saves are uncapped (15 saves all returned by GET)
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
    fwrite(STDERR, "Error: Cannot run manual tests in production!\n");
    exit(1);
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
    fwrite(STDERR, "Error: Manual tests must run with APP_ENV=testing. Destructive test operations are disabled outside the testing environment and cannot be overridden.\n");
    exit(1);
}

if ($currentDatabase !== $expectedDatabase) {
    fwrite(STDERR, "Error: Manual tests must use the '{$expectedDatabase}' database (current: {$currentDatabase}). Destructive test operations are disabled and cannot be overridden.\n");
    exit(1);
}

umask(002);

use App\Http\Controllers\GtaAlertsController;
use App\Http\Controllers\Notifications\SavedAlertController;
use App\Http\Requests\Notifications\SavedAlertStoreRequest;
use App\Models\FireIncident;
use App\Models\SavedAlert;
use App\Models\User;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

$testRunId = 'saved_alerts_phase_3_'.Carbon::now()->format('Y_m_d_His');
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

function assertValidIso8601(string $value, string $label): void
{
    $dt = DateTime::createFromFormat(DateTime::ATOM, $value);
    assertTrue($dt !== false, $label, ['value' => $value]);
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
 * Build a plain authenticated Request.
 */
function makeAuthRequest(User $user, string $method, string $uri, array $params = []): Request
{
    $request = Request::create(
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

/**
 * Build an Inertia-capable GET Request.
 * Setting X-Inertia makes the response return JSON props instead of a full HTML document.
 */
function makeInertiaRequest(?User $user, string $uri): Request
{
    $request = Request::create(
        $uri,
        'GET',
        [],
        [],
        [],
        [
            'HTTP_ACCEPT' => 'text/html, application/xhtml+xml',
            'HTTP_X_INERTIA' => 'true',
            'HTTP_X_INERTIA_VERSION' => '',
        ]
    );

    if ($user !== null) {
        $request->setUserResolver(static fn (): User => $user);
    }

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

    logInfo('=== Starting Manual Test: Saved Alerts Phase 3 – Frontend Saved Alert State Contract ===');

    /** @var UnifiedAlertsQuery $alertsQuery */
    $alertsQuery = app(UnifiedAlertsQuery::class);

    $controller = app(SavedAlertController::class);

    // =========================================================================
    // SECTION 1: POST /api/saved-alerts – SavedAlertService.saveAlert contract
    // =========================================================================
    logInfo('--- Section 1: POST /api/saved-alerts (saveAlert contract) ---');

    // 1a. Route exists and requires auth (frontend saveAlert() triggers kind: "auth" on 401)
    $storeRoute = Route::getRoutes()->getByName('api.saved-alerts.store');
    assertTrue($storeRoute !== null, 'api.saved-alerts.store route is registered');
    if ($storeRoute !== null) {
        assertEqual($storeRoute->uri(), 'api/saved-alerts', 'store route URI is api/saved-alerts');
        assertTrue(in_array('POST', $storeRoute->methods(), true), 'store route accepts POST');
        assertTrue(
            in_array('auth', $storeRoute->gatherMiddleware(), true),
            'store route is protected by auth middleware (ensures 401 for guests → kind: "auth")'
        );
    }

    // 1b. Successful POST returns 201 (saveAlert resolves without throwing)
    $userA = User::factory()->create();
    $storeReq = makeStoreRequest($userA, ['alert_id' => 'fire:P3TEST001']);
    $storeReq->validateResolved();
    $storeResponse = $controller->store($storeReq);
    assertEqual($storeResponse->getStatusCode(), 201, 'POST returns 201 on first save');

    logInfo('Successful POST returns 201.', ['status' => 201]);

    // 1c. Duplicate POST returns 409 with a message key (→ kind: "duplicate")
    $dupReq = makeStoreRequest($userA, ['alert_id' => 'fire:P3TEST001']);
    $dupReq->validateResolved();
    $dupResponse = $controller->store($dupReq);
    assertEqual($dupResponse->getStatusCode(), 409, 'duplicate POST returns 409 (→ kind: "duplicate")');

    $dupPayload = decodeJsonResponse($dupResponse);
    assertTrue(
        isset($dupPayload['message']) && is_string($dupPayload['message']) && strlen($dupPayload['message']) > 0,
        '409 response has a non-empty message key (errorKindFromStatus reads this field)'
    );

    logInfo('409 duplicate response shape verified.', ['message' => $dupPayload['message']]);

    // 1d. POST with invalid alert_id returns 422 ValidationException (→ kind: "validation")
    //     The SavedAlertStoreRequest validates against the AlertId contract.
    $invalidAlertIds = [
        'no-colon-separator',   // missing colon
        'unknown:ID001',        // invalid source
        'fire:',                // empty externalId
        ':ID001',               // empty source
    ];

    foreach ($invalidAlertIds as $badId) {
        try {
            $invalidReq = makeStoreRequest($userA, ['alert_id' => $badId]);
            $invalidReq->validateResolved();
            throw new RuntimeException("Expected ValidationException for alert_id: {$badId}");
        } catch (ValidationException $e) {
            assertTrue(
                isset($e->errors()['alert_id']),
                "invalid alert_id '{$badId}' produces 422 ValidationException with alert_id errors (→ kind: \"validation\")"
            );
        }
    }

    logInfo('Section 1 complete.');

    // =========================================================================
    // SECTION 2: DELETE /api/saved-alerts/{alertId} – removeAlert contract
    // =========================================================================
    logInfo('--- Section 2: DELETE /api/saved-alerts/{alertId} (removeAlert contract) ---');

    // 2a. Route exists and requires auth
    $destroyRoute = Route::getRoutes()->getByName('api.saved-alerts.destroy');
    assertTrue($destroyRoute !== null, 'api.saved-alerts.destroy route is registered');
    if ($destroyRoute !== null) {
        assertTrue(in_array('DELETE', $destroyRoute->methods(), true), 'destroy route accepts DELETE');
        assertTrue(
            in_array('auth', $destroyRoute->gatherMiddleware(), true),
            'destroy route is protected by auth middleware (ensures 401 for guests → kind: "auth")'
        );
    }

    // 2b. URL-encoded composite ID: the TypeScript removeAlert() calls
    //     encodeURIComponent(alertId) which encodes `:` as `%3A`.
    //     Laravel's router URL-decodes the route segment before binding,
    //     so the controller receives the plain composite string (e.g. fire:P3DEL001).
    //     Verify that the controller correctly handles the decoded form.
    $userB = User::factory()->create();
    SavedAlert::factory()->create(['user_id' => $userB->id, 'alert_id' => 'fire:P3DEL001']);

    // Simulate what Laravel route binding does: decode `fire%3AP3DEL001` → `fire:P3DEL001`
    $rawEncodedId = 'fire%3AP3DEL001';
    $decodedId = urldecode($rawEncodedId);
    assertEqual($decodedId, 'fire:P3DEL001', 'urldecode("fire%3AP3DEL001") produces composite alert ID');

    $deleteReq = makeAuthRequest($userB, 'DELETE', "/api/saved-alerts/{$rawEncodedId}");
    $deleteResponse = $controller->destroy($deleteReq, $decodedId);
    assertEqual($deleteResponse->getStatusCode(), 200, 'DELETE with URL-decoded composite ID returns 200');

    $deletePayload = decodeJsonResponse($deleteResponse);
    assertTrue(
        ($deletePayload['meta']['deleted'] ?? false) === true,
        'DELETE response has meta.deleted = true (removeAlert resolves without throwing)'
    );

    assertTrue(
        ! SavedAlert::query()->where('user_id', $userB->id)->where('alert_id', 'fire:P3DEL001')->exists(),
        'saved_alerts row removed from database after successful DELETE'
    );

    logInfo('URL-encoded DELETE contract verified.', ['raw_encoded' => $rawEncodedId, 'decoded' => $decodedId]);

    // 2c. DELETE of non-existent ID throws ModelNotFoundException (HTTP 404 → kind: "unknown")
    try {
        $notFoundReq = makeAuthRequest($userB, 'DELETE', '/api/saved-alerts/fire%3AGHOST_P3');
        $controller->destroy($notFoundReq, 'fire:GHOST_P3');
        throw new RuntimeException('Expected ModelNotFoundException for non-existent saved alert');
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        logInfo('Assertion passed: DELETE of non-existent ID throws ModelNotFoundException (→ HTTP 404 → kind: "unknown")');
    }

    // 2d. DELETE with cross-user ownership: other user cannot delete owner's saved alert
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    SavedAlert::factory()->create(['user_id' => $owner->id, 'alert_id' => 'police:P3OWNED001']);

    try {
        $intruderReq = makeAuthRequest($intruder, 'DELETE', '/api/saved-alerts/police%3AP3OWNED001');
        $controller->destroy($intruderReq, 'police:P3OWNED001');
        throw new RuntimeException('Expected ModelNotFoundException for cross-user DELETE attempt');
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        logInfo('Assertion passed: cross-user DELETE throws ModelNotFoundException (ownership scoped → kind: "unknown")');
    }

    assertTrue(
        SavedAlert::query()->where('user_id', $owner->id)->where('alert_id', 'police:P3OWNED001')->exists(),
        "owner's saved alert still exists after unauthorized DELETE attempt"
    );

    logInfo('Section 2 complete.');

    // =========================================================================
    // SECTION 3: GET /api/saved-alerts – fetchSavedAlerts() response shape
    // =========================================================================
    logInfo('--- Section 3: GET /api/saved-alerts hydrated UnifiedAlertResource shape ---');

    // 3a. Route exists and requires auth
    $indexRoute = Route::getRoutes()->getByName('api.saved-alerts.index');
    assertTrue($indexRoute !== null, 'api.saved-alerts.index route is registered');
    if ($indexRoute !== null) {
        assertTrue(in_array('GET', $indexRoute->methods(), true), 'index route accepts GET');
        assertTrue(
            in_array('auth', $indexRoute->gatherMiddleware(), true),
            'index route is protected by auth middleware (ensures 401 for guests → kind: "auth")'
        );
    }

    // 3b. Full hydrated UnifiedAlertResource shape
    //     fetchSavedAlerts() expects: { data: UnifiedAlertResource[], meta: { saved_ids, missing_alert_ids } }
    //     data[].id must be the composite alert ID string (e.g. fire:F26018618)
    $userC = User::factory()->create();
    $fireInc = FireIncident::factory()->create([
        'event_num' => 'P3HYDRATE001',
        'event_type' => 'STRUCTURE FIRE',
        'is_active' => true,
    ]);
    $compositeId = "fire:{$fireInc->event_num}";
    SavedAlert::factory()->create(['user_id' => $userC->id, 'alert_id' => $compositeId]);

    $indexReq = makeAuthRequest($userC, 'GET', '/api/saved-alerts');
    $indexResponse = $controller->index($indexReq, $alertsQuery);
    assertEqual($indexResponse->getStatusCode(), 200, 'GET /api/saved-alerts returns 200');

    $indexPayload = decodeJsonResponse($indexResponse);

    // Top-level shape matches what fetchSavedAlerts() validates
    assertTrue(isset($indexPayload['data']) && is_array($indexPayload['data']), 'response has data array');
    assertTrue(isset($indexPayload['meta']) && is_array($indexPayload['meta']), 'response has meta object');
    assertTrue(isset($indexPayload['meta']['saved_ids']) && is_array($indexPayload['meta']['saved_ids']), 'meta has saved_ids array');
    assertTrue(isset($indexPayload['meta']['missing_alert_ids']) && is_array($indexPayload['meta']['missing_alert_ids']), 'meta has missing_alert_ids array');

    assertEqual(count($indexPayload['data']), 1, 'data contains 1 hydrated resource');

    $resource = $indexPayload['data'][0];

    // 3c. data[].id is the composite alert ID string (not the database row id)
    //     The TypeScript fetchSavedAlerts() casts data as UnifiedAlertResource[] and accesses result.data[0].id
    assertEqual($resource['id'], $compositeId, 'data[0].id is the composite alert ID string (source:externalId)');
    assertTrue(
        str_contains((string) $resource['id'], ':'),
        'data[0].id contains a colon separator (composite format)'
    );

    // 3d. All required UnifiedAlertResource fields are present
    //     fetchSavedAlerts() validates: data, meta.saved_ids, meta.missing_alert_ids (array checks)
    //     The TypeScript UnifiedAlertResource type expects: id, source, external_id, is_active,
    //     timestamp, title, location, meta
    $requiredFields = ['id', 'source', 'external_id', 'is_active', 'timestamp', 'title', 'location', 'meta'];
    foreach ($requiredFields as $field) {
        assertTrue(
            array_key_exists($field, $resource),
            "hydrated resource has required field: {$field} (used by UnifiedAlertResource type)"
        );
    }

    // 3e. Spot-check field types and values
    assertEqual($resource['source'], 'fire', 'data[0].source is "fire"');
    assertEqual($resource['external_id'], $fireInc->event_num, 'data[0].external_id matches event_num');
    assertTrue(is_bool($resource['is_active']), 'data[0].is_active is a boolean');
    assertTrue(
        is_string($resource['timestamp']) && strlen($resource['timestamp']) > 0,
        'data[0].timestamp is a non-empty string'
    );
    assertValidIso8601($resource['timestamp'], 'data[0].timestamp is a valid ISO 8601 timestamp');
    assertEqual($resource['title'], 'STRUCTURE FIRE', 'data[0].title matches event_type');

    // 3f. meta.saved_ids contains the composite alert ID string
    assertTrue(
        in_array($compositeId, $indexPayload['meta']['saved_ids'], true),
        'meta.saved_ids contains the composite alert ID string'
    );
    assertEqual($indexPayload['meta']['missing_alert_ids'], [], 'meta.missing_alert_ids is empty when all resolved');

    logInfo('Hydrated UnifiedAlertResource shape verified.', ['resource_id' => $resource['id']]);

    // 3g. meta.missing_alert_ids is populated for unresolvable saved IDs
    $userD = User::factory()->create();
    SavedAlert::factory()->create(['user_id' => $userD->id, 'alert_id' => 'fire:P3GHOST_MISSING']);

    $ghostReq = makeAuthRequest($userD, 'GET', '/api/saved-alerts');
    $ghostResponse = $controller->index($ghostReq, $alertsQuery);
    $ghostPayload = decodeJsonResponse($ghostResponse);

    assertEqual($ghostPayload['data'], [], 'data is empty when saved alert cannot be hydrated');
    assertTrue(
        in_array('fire:P3GHOST_MISSING', $ghostPayload['meta']['missing_alert_ids'], true),
        'meta.missing_alert_ids contains the unresolvable composite ID'
    );
    assertTrue(
        in_array('fire:P3GHOST_MISSING', $ghostPayload['meta']['saved_ids'], true),
        'meta.saved_ids still includes the unresolvable ID (hook uses this to initialize savedIds state)'
    );

    logInfo('Unresolvable ID in meta.missing_alert_ids verified.');

    // 3h. data does NOT include old saved-alert row fields (alert_id, saved_at)
    //     fetchSavedAlerts() expects UnifiedAlertResource[], not Phase 1 saved-alert rows
    assertTrue(
        ! array_key_exists('alert_id', $resource),
        'hydrated resource does NOT contain alert_id key (would indicate old Phase 1 shape, not UnifiedAlertResource)'
    );
    assertTrue(
        ! array_key_exists('saved_at', $resource),
        'hydrated resource does NOT contain saved_at key (would indicate old Phase 1 shape, not UnifiedAlertResource)'
    );

    logInfo('Section 3 complete.');

    // =========================================================================
    // SECTION 4: Guest Inertia bootstrap – useSavedAlerts guest localStorage contract
    // =========================================================================
    logInfo('--- Section 4: GtaAlertsController guest Inertia bootstrap (saved_alert_ids: []) ---');

    $gtaController = app(GtaAlertsController::class);

    // 4a. Guest (unauthenticated) receives saved_alert_ids: [] in Inertia props
    //     The hook checks authUserId === null (guest mode) and falls back to localStorage.
    //     The prop being [] (not null or missing) is the signal that this is a guest context.
    $guestReq = makeInertiaRequest(null, '/');
    $guestInertiaResponse = $gtaController->__invoke($guestReq, $alertsQuery);
    $guestJson = json_decode($guestInertiaResponse->toResponse($guestReq)->getContent(), true);

    assertTrue(is_array($guestJson), 'guest Inertia response is valid JSON');
    assertTrue(isset($guestJson['props']), 'guest Inertia response has props key');
    assertTrue(
        isset($guestJson['props']['saved_alert_ids']),
        'guest Inertia props contain saved_alert_ids key (hook requires this key to exist)'
    );
    assertEqual(
        $guestJson['props']['saved_alert_ids'],
        [],
        'guest saved_alert_ids is an empty array (hook falls back to localStorage in guest mode)'
    );
    assertTrue(
        is_array($guestJson['props']['saved_alert_ids']),
        'saved_alert_ids is always an array for guests (never null, never missing)'
    );

    logInfo('Guest Inertia bootstrap verified.', [
        'saved_alert_ids' => $guestJson['props']['saved_alert_ids'],
    ]);

    logInfo('Section 4 complete.');

    // =========================================================================
    // SECTION 5: Auth Inertia bootstrap – useSavedAlerts auth bootstrap contract
    // =========================================================================
    logInfo('--- Section 5: GtaAlertsController auth Inertia bootstrap (newest-first composite IDs) ---');

    // 5a. Authenticated user gets saved_alert_ids bootstrapped in newest-first order
    //     The hook initializes savedIds from this prop for auth mode.
    $userE = User::factory()->create();
    SavedAlert::factory()->create(['user_id' => $userE->id, 'alert_id' => 'fire:P3BOOT_FIRST']);
    SavedAlert::factory()->create(['user_id' => $userE->id, 'alert_id' => 'police:P3BOOT_SECOND']);

    $authInertiaReq = makeInertiaRequest($userE, '/');
    $authInertiaResponse = $gtaController->__invoke($authInertiaReq, $alertsQuery);
    $authJson = json_decode($authInertiaResponse->toResponse($authInertiaReq)->getContent(), true);

    assertTrue(isset($authJson['props']['saved_alert_ids']), 'auth Inertia props contain saved_alert_ids');
    $bootstrapIds = $authJson['props']['saved_alert_ids'];
    assertTrue(is_array($bootstrapIds), 'auth saved_alert_ids is an array');
    assertEqual(count($bootstrapIds), 2, 'auth saved_alert_ids has 2 entries');

    // Newest-first: police:P3BOOT_SECOND was saved second → higher id → comes first
    assertEqual(
        $bootstrapIds[0],
        'police:P3BOOT_SECOND',
        'saved_alert_ids[0] is the most recently saved ID (newest-first order)'
    );
    assertEqual(
        $bootstrapIds[1],
        'fire:P3BOOT_FIRST',
        'saved_alert_ids[1] is the earlier saved ID'
    );

    logInfo('Newest-first bootstrap order verified.', ['saved_alert_ids' => $bootstrapIds]);

    // 5b. IDs in saved_alert_ids use the composite {source}:{externalId} format
    //     useSavedAlerts initializes state with these IDs and the hook/service use
    //     composite IDs as the primary identifier throughout.
    foreach ($bootstrapIds as $bootstrapId) {
        assertTrue(
            str_contains($bootstrapId, ':'),
            "bootstrap ID '{$bootstrapId}' is in composite {source}:{externalId} format"
        );
        $parts = explode(':', $bootstrapId, 2);
        assertTrue(
            count($parts) === 2 && strlen($parts[0]) > 0 && strlen($parts[1]) > 0,
            "bootstrap ID '{$bootstrapId}' has non-empty source and externalId parts"
        );
    }

    logInfo('Composite ID format verified for all bootstrap IDs.');

    // 5c. Authenticated user with no saved alerts gets empty array (not null, not missing)
    $userNoSaved = User::factory()->create();
    $emptyAuthReq = makeInertiaRequest($userNoSaved, '/');
    $emptyAuthJson = json_decode($gtaController->__invoke($emptyAuthReq, $alertsQuery)->toResponse($emptyAuthReq)->getContent(), true);

    assertEqual(
        $emptyAuthJson['props']['saved_alert_ids'] ?? null,
        [],
        'auth user with no saved alerts gets empty saved_alert_ids array (not null)'
    );

    // 5d. saved_alert_ids is scoped to the authenticated user only
    $userOwner = User::factory()->create();
    $userOther = User::factory()->create();
    SavedAlert::factory()->create(['user_id' => $userOwner->id, 'alert_id' => 'fire:P3SCOPE_OWNER']);
    SavedAlert::factory()->create(['user_id' => $userOther->id, 'alert_id' => 'fire:P3SCOPE_OTHER']);

    $ownerInertiaReq = makeInertiaRequest($userOwner, '/');
    $ownerJson = json_decode($gtaController->__invoke($ownerInertiaReq, $alertsQuery)->toResponse($ownerInertiaReq)->getContent(), true);
    $ownerBootstrapIds = $ownerJson['props']['saved_alert_ids'];

    assertTrue(
        in_array('fire:P3SCOPE_OWNER', $ownerBootstrapIds, true),
        'owner sees their own saved ID in bootstrap'
    );
    assertTrue(
        ! in_array('fire:P3SCOPE_OTHER', $ownerBootstrapIds, true),
        'owner does NOT see other user saved IDs in bootstrap (scoped correctly)'
    );

    logInfo('Section 5 complete.');

    // =========================================================================
    // SECTION 6: HTTP status → SavedAlertServiceError kind mapping
    // =========================================================================
    logInfo('--- Section 6: HTTP status code → SavedAlertServiceError kind mapping ---');

    // The TypeScript errorKindFromStatus function maps:
    //   409 → "duplicate"   (confirmed: controller returns 409 on duplicate save)
    //   401 → "auth"        (confirmed: routes require auth middleware)
    //   403 → "auth"        (confirmed: same auth middleware covers 403)
    //   422 → "validation"  (confirmed: SavedAlertStoreRequest throws ValidationException → 422)
    //   other → "unknown"   (confirmed: ModelNotFoundException → 404 is "unknown")

    // 6a. 409 → kind: "duplicate" (POST duplicate already verified in Section 1c)
    $userF = User::factory()->create();
    $dupCheckReq1 = makeStoreRequest($userF, ['alert_id' => 'fire:P3ERRMAP001']);
    $dupCheckReq1->validateResolved();
    $controller->store($dupCheckReq1);  // First save → 201

    $dupCheckReq2 = makeStoreRequest($userF, ['alert_id' => 'fire:P3ERRMAP001']);
    $dupCheckReq2->validateResolved();
    $dupCheckResponse = $controller->store($dupCheckReq2);  // Duplicate → 409
    assertEqual(
        $dupCheckResponse->getStatusCode(),
        409,
        'HTTP 409 on duplicate save → maps to SavedAlertServiceError kind: "duplicate"'
    );

    // 6b. 401 → kind: "auth" (routes protected by auth middleware)
    $storeRouteForAuth = Route::getRoutes()->getByName('api.saved-alerts.store');
    $indexRouteForAuth = Route::getRoutes()->getByName('api.saved-alerts.index');
    $destroyRouteForAuth = Route::getRoutes()->getByName('api.saved-alerts.destroy');

    foreach ([
        ['route' => $storeRouteForAuth, 'name' => 'store'],
        ['route' => $indexRouteForAuth, 'name' => 'index'],
        ['route' => $destroyRouteForAuth, 'name' => 'destroy'],
    ] as $routeInfo) {
        if ($routeInfo['route'] !== null) {
            assertTrue(
                in_array('auth', $routeInfo['route']->gatherMiddleware(), true),
                "api.saved-alerts.{$routeInfo['name']} has auth middleware → unauthenticated → HTTP 401 → kind: \"auth\""
            );
        }
    }

    // 6c. 422 → kind: "validation" (invalid alert_id format triggers ValidationException)
    try {
        $validationReq = makeStoreRequest($userF, ['alert_id' => 'invalid-no-colon']);
        $validationReq->validateResolved();
        throw new RuntimeException('Expected ValidationException for invalid alert_id');
    } catch (ValidationException $e) {
        assertTrue(
            isset($e->errors()['alert_id']),
            'invalid alert_id triggers ValidationException (Laravel returns 422) → maps to kind: "validation"'
        );
    }

    // 6d. 404 → kind: "unknown" (non-existent DELETE throws ModelNotFoundException → 404)
    try {
        $notExistReq = makeAuthRequest($userF, 'DELETE', '/api/saved-alerts/fire%3AP3ERRMAP_NOTEXIST');
        $controller->destroy($notExistReq, 'fire:P3ERRMAP_NOTEXIST');
        throw new RuntimeException('Expected ModelNotFoundException for non-existent DELETE');
    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
        logInfo('Assertion passed: DELETE non-existent → ModelNotFoundException → HTTP 404 → kind: "unknown"');
    }

    logInfo('Error kind mapping summary', [
        '409' => 'duplicate',
        '401/403' => 'auth',
        '422' => 'validation',
        '404' => 'unknown',
    ]);

    logInfo('Section 6 complete.');

    // =========================================================================
    // SECTION 7: Authenticated saves are uncapped (guest cap is frontend-only)
    // =========================================================================
    logInfo('--- Section 7: Authenticated saves are uncapped (backend has no limit) ---');

    // The TypeScript useSavedAlerts hook enforces a 10-item cap in guest mode.
    // Auth mode has no cap: the backend accepts any number of saves.
    // Verify by creating 15 saved alerts and checking GET returns all 15.
    $userUnlimited = User::factory()->create();

    $savedAlertIds = [];
    $sources = ['fire', 'police', 'transit', 'go_transit'];

    for ($i = 1; $i <= 15; $i++) {
        $source = $sources[($i - 1) % count($sources)];
        $alertId = "{$source}:P3NOCAP".str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        SavedAlert::factory()->create(['user_id' => $userUnlimited->id, 'alert_id' => $alertId]);
        $savedAlertIds[] = $alertId;
    }

    // Verify all 15 are returned by GET /api/saved-alerts
    $unlimitedReq = makeAuthRequest($userUnlimited, 'GET', '/api/saved-alerts');
    $unlimitedResponse = $controller->index($unlimitedReq, $alertsQuery);
    $unlimitedPayload = decodeJsonResponse($unlimitedResponse);

    // meta.saved_ids must contain all 15 composite IDs
    assertEqual(
        count($unlimitedPayload['meta']['saved_ids']),
        15,
        'meta.saved_ids contains all 15 saved alert IDs (backend has no cap, only guest mode is capped)'
    );

    foreach ($savedAlertIds as $expectedId) {
        assertTrue(
            in_array($expectedId, $unlimitedPayload['meta']['saved_ids'], true),
            "meta.saved_ids contains {$expectedId}"
        );
    }

    logInfo('Uncapped authenticated saves verified.', [
        'total_saved' => count($unlimitedPayload['meta']['saved_ids']),
    ]);

    logInfo('Section 7 complete.');

    // =========================================================================
    // All sections passed
    // =========================================================================
    logInfo('=== All sections passed. Phase 3 Frontend Saved Alert State Contract verified. ===');

} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual test failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    if ($txStarted) {
        DB::rollBack();
        logInfo('Transaction rolled back (database preserved).');
    }
    logInfo('=== Test Run Finished ===');
    echo "\n".($exitCode === 0 ? '✓' : '✗')." Full logs at: {$logFileRelative}\n";
}

exit($exitCode);
