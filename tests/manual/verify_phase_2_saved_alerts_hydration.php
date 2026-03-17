<?php

/**
 * Manual Test: Saved Alerts – Phase 2 Read Model & Feed Hydration (GTA-102)
 * Generated: 2026-03-17
 * Purpose: Verify Phase 2 deliverables for the saved-alerts feature:
 * - UnifiedAlertsQuery::fetchByIds() returns hydrated UnifiedAlert DTOs
 * - fetchByIds() preserves caller-supplied ordering
 * - fetchByIds() populates missing_ids for unresolvable alert IDs
 * - GET /api/saved-alerts returns UnifiedAlertResource payloads (not raw saved-alert rows)
 * - Unresolvable saved IDs surface in meta.missing_alert_ids, absent from data
 * - Newest-saved-first ordering in the index response
 * - GtaAlertsController injects saved_alert_ids into the Inertia page props
 * - Guests receive an empty saved_alert_ids array in the Inertia props
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
use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\SavedAlert;
use App\Models\TransitAlert;
use App\Models\User;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

$testRunId = 'saved_alerts_phase_2_'.Carbon::now()->format('Y_m_d_His');
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
 * Build a plain GET Request authenticated as the given user.
 */
function makeGetRequest(User $user, string $uri): Request
{
    $request = Request::create($uri, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
    $request->setUserResolver(static fn (): User => $user);

    return $request;
}

/**
 * Build a guest GET Request (no user resolver).
 */
function makeGuestRequest(string $uri): Request
{
    return Request::create($uri, 'GET', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
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

    logInfo('=== Starting Manual Test: Saved Alerts Phase 2 – Read Model & Feed Hydration ===');

    /** @var UnifiedAlertsQuery $alertsQuery */
    $alertsQuery = app(UnifiedAlertsQuery::class);

    $controller = app(SavedAlertController::class);

    // =========================================================================
    // SECTION 1: UnifiedAlertsQuery::fetchByIds() – empty input
    // =========================================================================
    logInfo('--- Section 1: fetchByIds() with empty input ---');

    $emptyResult = $alertsQuery->fetchByIds([]);
    assertEqual($emptyResult['items'], [], 'fetchByIds([]) returns empty items');
    assertEqual($emptyResult['missing_ids'], [], 'fetchByIds([]) returns empty missing_ids');

    logInfo('Section 1 complete.');

    // =========================================================================
    // SECTION 2: fetchByIds() – single-source hydration (one per source)
    // =========================================================================
    logInfo('--- Section 2: fetchByIds() single-source hydration ---');

    // Fire
    $fire = FireIncident::factory()->create([
        'event_num' => 'MTEST_FIRE001',
        'event_type' => 'STRUCTURE FIRE',
        'is_active' => true,
    ]);
    $fireId = "fire:{$fire->event_num}";

    $fireResult = $alertsQuery->fetchByIds([$fireId]);
    assertEqual(count($fireResult['items']), 1, 'fetchByIds fire: returns 1 item');
    assertEqual($fireResult['missing_ids'], [], 'fetchByIds fire: no missing_ids');
    assertEqual($fireResult['items'][0]->id, $fireId, 'fetchByIds fire: item id matches');
    assertEqual($fireResult['items'][0]->source, 'fire', 'fetchByIds fire: item source is fire');
    assertEqual($fireResult['items'][0]->externalId, $fire->event_num, 'fetchByIds fire: externalId matches');
    assertTrue($fireResult['items'][0]->isActive, 'fetchByIds fire: isActive is true');
    assertEqual($fireResult['items'][0]->title, 'STRUCTURE FIRE', 'fetchByIds fire: title matches event_type');

    logInfo('Fire hydration verified.', ['id' => $fireId]);

    // Police
    $police = PoliceCall::factory()->create([
        'object_id' => 98765,
        'call_type' => 'THEFT',
        'is_active' => true,
    ]);
    $policeId = "police:{$police->object_id}";

    $policeResult = $alertsQuery->fetchByIds([$policeId]);
    assertEqual(count($policeResult['items']), 1, 'fetchByIds police: returns 1 item');
    assertEqual($policeResult['missing_ids'], [], 'fetchByIds police: no missing_ids');
    assertEqual($policeResult['items'][0]->id, $policeId, 'fetchByIds police: item id matches');
    assertEqual($policeResult['items'][0]->source, 'police', 'fetchByIds police: source is police');
    assertEqual($policeResult['items'][0]->title, 'THEFT', 'fetchByIds police: title matches call_type');

    logInfo('Police hydration verified.', ['id' => $policeId]);

    // Transit
    $transit = TransitAlert::factory()->create([
        'external_id' => 'api:MTEST9999',
        'title' => 'Line 1 Delay',
        'is_active' => true,
    ]);
    $transitId = "transit:{$transit->external_id}";

    $transitResult = $alertsQuery->fetchByIds([$transitId]);
    assertEqual(count($transitResult['items']), 1, 'fetchByIds transit: returns 1 item');
    assertEqual($transitResult['missing_ids'], [], 'fetchByIds transit: no missing_ids');
    assertEqual($transitResult['items'][0]->id, $transitId, 'fetchByIds transit: item id matches');
    assertEqual($transitResult['items'][0]->source, 'transit', 'fetchByIds transit: source is transit');
    assertEqual($transitResult['items'][0]->title, 'Line 1 Delay', 'fetchByIds transit: title matches');

    logInfo('Transit hydration verified.', ['id' => $transitId]);

    // GO Transit
    $goExtId = 'notif:LW:TDELAY:MTEST001';
    $goTransit = GoTransitAlert::factory()->create([
        'external_id' => $goExtId,
        'alert_type' => 'notification',
        'is_active' => true,
    ]);
    $goId = "go_transit:{$goExtId}";

    $goResult = $alertsQuery->fetchByIds([$goId]);
    assertEqual(count($goResult['items']), 1, 'fetchByIds go_transit: returns 1 item');
    assertEqual($goResult['missing_ids'], [], 'fetchByIds go_transit: no missing_ids');
    assertEqual($goResult['items'][0]->id, $goId, 'fetchByIds go_transit: item id matches');
    assertEqual($goResult['items'][0]->source, 'go_transit', 'fetchByIds go_transit: source is go_transit');

    logInfo('GO Transit hydration verified.', ['id' => $goId]);

    logInfo('Section 2 complete.');

    // =========================================================================
    // SECTION 3: fetchByIds() – unresolved IDs
    // =========================================================================
    logInfo('--- Section 3: fetchByIds() unresolved IDs ---');

    // Non-existent fire incident
    $ghostId = 'fire:GHOST_MTEST999';
    $ghostResult = $alertsQuery->fetchByIds([$ghostId]);
    assertEqual($ghostResult['items'], [], 'fetchByIds ghost: items is empty');
    assertEqual($ghostResult['missing_ids'], [$ghostId], 'fetchByIds ghost: ghost id in missing_ids');

    logInfo('Ghost ID verified.', ['id' => $ghostId]);

    // Mixed: one resolved, one unresolved
    $mixedResult = $alertsQuery->fetchByIds([$fireId, 'fire:GHOST_MTEST998']);
    assertEqual(count($mixedResult['items']), 1, 'fetchByIds mixed: only resolved item returned');
    assertEqual($mixedResult['items'][0]->id, $fireId, 'fetchByIds mixed: resolved item is the fire incident');
    assertEqual($mixedResult['missing_ids'], ['fire:GHOST_MTEST998'], 'fetchByIds mixed: ghost id in missing_ids');

    logInfo('Mixed resolved/unresolved verified.');

    logInfo('Section 3 complete.');

    // =========================================================================
    // SECTION 4: fetchByIds() – caller-supplied ordering is preserved
    // =========================================================================
    logInfo('--- Section 4: fetchByIds() preserves caller-supplied order ---');

    $fireA = FireIncident::factory()->create(['event_num' => 'MTEST_ORDER_A']);
    $fireB = FireIncident::factory()->create(['event_num' => 'MTEST_ORDER_B']);
    $idA = "fire:{$fireA->event_num}";
    $idB = "fire:{$fireB->event_num}";

    // Request B first, A second
    $orderedResult = $alertsQuery->fetchByIds([$idB, $idA]);
    assertEqual(count($orderedResult['items']), 2, 'fetchByIds ordering: 2 items returned');
    assertEqual($orderedResult['items'][0]->id, $idB, 'fetchByIds ordering: first item is B (as requested)');
    assertEqual($orderedResult['items'][1]->id, $idA, 'fetchByIds ordering: second item is A (as requested)');

    logInfo('Caller-supplied order verified.', ['requested_order' => [$idB, $idA]]);

    logInfo('Section 4 complete.');

    // =========================================================================
    // SECTION 5: GET /api/saved-alerts – hydrated UnifiedAlertResource shape
    // =========================================================================
    logInfo('--- Section 5: GET /api/saved-alerts returns hydrated UnifiedAlertResource ---');

    $user1 = User::factory()->create();

    // Create an actual FireIncident that can be resolved
    $savedFire = FireIncident::factory()->create([
        'event_num' => 'MTEST_SAVED_F01',
        'event_type' => 'ALRM',
        'is_active' => true,
    ]);
    $savedAlertId = "fire:{$savedFire->event_num}";
    SavedAlert::factory()->create(['user_id' => $user1->id, 'alert_id' => $savedAlertId]);

    $indexReq = makeGetRequest($user1, '/api/saved-alerts');
    $indexResponse = $controller->index($indexReq, $alertsQuery);
    assertEqual($indexResponse->getStatusCode(), 200, 'index returns 200');

    $indexPayload = decodeJsonResponse($indexResponse);

    assertTrue(isset($indexPayload['data']), 'index response has data key');
    assertTrue(isset($indexPayload['meta']), 'index response has meta key');
    assertEqual(count($indexPayload['data']), 1, 'index data contains 1 hydrated resource');

    $resource = $indexPayload['data'][0];

    // Verify UnifiedAlertResource shape (not the old saved-alert row shape)
    $requiredFields = ['id', 'source', 'external_id', 'is_active', 'timestamp', 'title', 'location', 'meta'];
    foreach ($requiredFields as $field) {
        assertTrue(isset($resource[$field]) || array_key_exists($field, $resource), "hydrated resource has field: {$field}");
    }

    // Ensure the OLD shape fields are NOT present (phase 1 returned alert_id, saved_at)
    assertTrue(! array_key_exists('alert_id', $resource), 'hydrated resource does NOT include alert_id key (old shape)');
    assertTrue(! array_key_exists('saved_at', $resource), 'hydrated resource does NOT include saved_at key (old shape)');

    // Field values
    assertEqual($resource['id'], $savedAlertId, 'hydrated resource id matches saved alert_id');
    assertEqual($resource['source'], 'fire', 'hydrated resource source is fire');
    assertEqual($resource['external_id'], $savedFire->event_num, 'hydrated resource external_id matches event_num');
    assertTrue(is_bool($resource['is_active']), 'hydrated resource is_active is boolean');
    assertTrue(is_string($resource['timestamp']) && strlen($resource['timestamp']) > 0, 'hydrated resource timestamp is a non-empty string');
    assertEqual($resource['title'], 'ALRM', 'hydrated resource title matches event_type');

    // Meta
    $metaSavedIds = $indexPayload['meta']['saved_ids'];
    assertTrue(in_array($savedAlertId, $metaSavedIds, true), 'meta.saved_ids contains the saved alert ID');
    assertEqual($indexPayload['meta']['missing_alert_ids'], [], 'meta.missing_alert_ids is empty when all resolved');

    logInfo('UnifiedAlertResource shape verified.', ['resource_id' => $resource['id']]);

    logInfo('Section 5 complete.');

    // =========================================================================
    // SECTION 6: Unresolvable IDs surface in meta.missing_alert_ids
    // =========================================================================
    logInfo('--- Section 6: Unresolvable IDs in meta.missing_alert_ids ---');

    $user2 = User::factory()->create();
    // Save an alert ID with no matching record in the database
    SavedAlert::factory()->create(['user_id' => $user2->id, 'alert_id' => 'fire:GHOST_MTEST777']);

    $ghostReq = makeGetRequest($user2, '/api/saved-alerts');
    $ghostResponse = $controller->index($ghostReq, $alertsQuery);
    assertEqual($ghostResponse->getStatusCode(), 200, 'index returns 200 for unresolvable saved alert');

    $ghostPayload = decodeJsonResponse($ghostResponse);
    assertEqual($ghostPayload['data'], [], 'data is empty when saved alert cannot be resolved');
    assertTrue(in_array('fire:GHOST_MTEST777', $ghostPayload['meta']['missing_alert_ids'], true), 'missing_alert_ids contains the unresolvable ID');
    assertTrue(in_array('fire:GHOST_MTEST777', $ghostPayload['meta']['saved_ids'], true), 'saved_ids still contains the unresolvable ID');

    logInfo('Unresolvable ID surfacing verified.');

    logInfo('Section 6 complete.');

    // =========================================================================
    // SECTION 7: Newest-saved-first ordering in index response
    // =========================================================================
    logInfo('--- Section 7: index returns results newest-saved-first ---');

    $user3 = User::factory()->create();

    $fireFirst = FireIncident::factory()->create(['event_num' => 'MTEST_ORDER_FIRST']);
    $fireLast = FireIncident::factory()->create(['event_num' => 'MTEST_ORDER_LAST']);

    // Save FIRST, then LAST (so LAST has a higher auto-increment id)
    SavedAlert::factory()->create(['user_id' => $user3->id, 'alert_id' => "fire:{$fireFirst->event_num}"]);
    SavedAlert::factory()->create(['user_id' => $user3->id, 'alert_id' => "fire:{$fireLast->event_num}"]);

    $orderReq = makeGetRequest($user3, '/api/saved-alerts');
    $orderResponse = $controller->index($orderReq, $alertsQuery);
    $orderPayload = decodeJsonResponse($orderResponse);

    assertEqual(count($orderPayload['data']), 2, 'ordering test: 2 items in response');
    // Newest-saved = LAST (inserted second, higher id)
    assertEqual(
        $orderPayload['data'][0]['id'],
        "fire:{$fireLast->event_num}",
        'data[0] is the most recently saved alert (LAST)'
    );
    assertEqual(
        $orderPayload['data'][1]['id'],
        "fire:{$fireFirst->event_num}",
        'data[1] is the earlier saved alert (FIRST)'
    );

    logInfo('Newest-saved-first ordering verified.');

    logInfo('Section 7 complete.');

    // =========================================================================
    // SECTION 8: GtaAlertsController – Inertia payload bootstraps saved_alert_ids
    // =========================================================================
    logInfo('--- Section 8: GtaAlertsController bootstraps saved_alert_ids in Inertia props ---');

    $gtaController = app(GtaAlertsController::class);

    // 8a. Authenticated user gets their saved IDs (newest first)
    $user4 = User::factory()->create();
    SavedAlert::factory()->create(['user_id' => $user4->id, 'alert_id' => 'fire:MTEST_BOOT_A']);
    SavedAlert::factory()->create(['user_id' => $user4->id, 'alert_id' => 'police:MTEST_BOOT_B']);

    $authInertiaReq = makeInertiaRequest($user4, '/');
    $inertiaResponse = $gtaController->__invoke($authInertiaReq, $alertsQuery);

    // Convert to an HTTP response with the Inertia JSON envelope
    $httpResponse = $inertiaResponse->toResponse($authInertiaReq);
    $inertiaJson = json_decode($httpResponse->getContent(), true);

    assertTrue(is_array($inertiaJson), 'Inertia response is valid JSON');
    assertTrue(isset($inertiaJson['props']), 'Inertia JSON has props key');
    assertTrue(isset($inertiaJson['props']['saved_alert_ids']), 'Inertia props contain saved_alert_ids');

    $bootstrapIds = $inertiaJson['props']['saved_alert_ids'];
    assertTrue(is_array($bootstrapIds), 'saved_alert_ids is an array');
    assertEqual(count($bootstrapIds), 2, 'saved_alert_ids has 2 entries for authenticated user');
    // Newest first: police:MTEST_BOOT_B was saved second so it comes first
    assertEqual($bootstrapIds[0], 'police:MTEST_BOOT_B', 'saved_alert_ids[0] is the most recently saved ID');
    assertEqual($bootstrapIds[1], 'fire:MTEST_BOOT_A', 'saved_alert_ids[1] is the earlier saved ID');

    logInfo('Authenticated user Inertia bootstrap verified.', ['saved_alert_ids' => $bootstrapIds]);

    // 8b. Authenticated user with no saved alerts gets an empty array
    $user5 = User::factory()->create();
    $authEmptyInertiaReq = makeInertiaRequest($user5, '/');
    $authEmptyResponse = $gtaController->__invoke($authEmptyInertiaReq, $alertsQuery);
    $authEmptyJson = json_decode($authEmptyResponse->toResponse($authEmptyInertiaReq)->getContent(), true);

    assertEqual(
        $authEmptyJson['props']['saved_alert_ids'] ?? null,
        [],
        'authenticated user with no saved alerts gets empty saved_alert_ids'
    );

    logInfo('Empty authenticated bootstrap verified.');

    // 8c. Guest (no auth) gets an empty array
    $guestInertiaReq = makeInertiaRequest(null, '/');
    $guestInertiaResponse = $gtaController->__invoke($guestInertiaReq, $alertsQuery);
    $guestJson = json_decode($guestInertiaResponse->toResponse($guestInertiaReq)->getContent(), true);

    assertTrue(isset($guestJson['props']['saved_alert_ids']), 'guest Inertia props contain saved_alert_ids key');
    assertEqual(
        $guestJson['props']['saved_alert_ids'],
        [],
        'guest receives empty saved_alert_ids in Inertia props'
    );

    logInfo('Guest Inertia bootstrap verified.');

    logInfo('Section 8 complete.');

    // =========================================================================
    // SECTION 9: saved_alert_ids scoped to authenticated user only
    // =========================================================================
    logInfo('--- Section 9: saved_alert_ids scoped to authenticated user ---');

    $ownerUser = User::factory()->create();
    $otherUser = User::factory()->create();

    SavedAlert::factory()->create(['user_id' => $ownerUser->id, 'alert_id' => 'fire:MTEST_OWNER_ID']);
    SavedAlert::factory()->create(['user_id' => $otherUser->id, 'alert_id' => 'fire:MTEST_OTHER_ID']);

    $ownerInertiaReq = makeInertiaRequest($ownerUser, '/');
    $ownerInertiaResponse = $gtaController->__invoke($ownerInertiaReq, $alertsQuery);
    $ownerJson = json_decode($ownerInertiaResponse->toResponse($ownerInertiaReq)->getContent(), true);

    $ownerBootstrapIds = $ownerJson['props']['saved_alert_ids'];
    assertTrue(in_array('fire:MTEST_OWNER_ID', $ownerBootstrapIds, true), 'owner sees their own saved ID');
    assertTrue(! in_array('fire:MTEST_OTHER_ID', $ownerBootstrapIds, true), 'owner does NOT see other user saved ID');

    logInfo('Saved_alert_ids owner scoping verified.', [
        'owner_ids' => $ownerBootstrapIds,
    ]);

    logInfo('Section 9 complete.');

    // =========================================================================
    // All sections passed
    // =========================================================================
    logInfo('=== All sections passed. Phase 2 Saved Alert Read Model & Feed Hydration verified. ===');

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
