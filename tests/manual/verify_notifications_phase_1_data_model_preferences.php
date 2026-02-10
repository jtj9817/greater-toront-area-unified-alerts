<?php

/**
 * Manual Test: Notifications - Phase 1 Data Model & Preferences
 * Generated: 2026-02-10
 * Purpose: Verify Phase 1 deliverables for notification preferences backend:
 * - Schema + index expectations for notification tables
 * - NotificationPreference + NotificationLog model behavior
 * - Preference validation rules (including review-fix regressions)
 * - Settings route auth wiring and controller-level GET/PATCH behavior
 */

require __DIR__.'/../../vendor/autoload.php';

// Default manual verification runs to testing so Laravel loads `.env.testing`.
// Preserve an explicitly provided APP_ENV value if the caller set one.
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}

// Some manual test environments do not provide APP_KEY in `.env.testing`.
// Use a deterministic testing-only fallback so app boot does not fail.
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

// Prevent production execution.
if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALLOW_ROOT_MANUAL_TESTS') !== '1') {
    fwrite(STDERR, "Error: Do not run manual tests as root. Use `./vendor/bin/sail shell` (or `./vendor/bin/sail php ...`).\n");
    fwrite(STDERR, "If you really need root, re-run with ALLOW_ROOT_MANUAL_TESTS=1 (not recommended).\n");
    exit(1);
}

// Manual tests can delete data; only allow the dedicated testing database.
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

use App\Http\Controllers\Settings\NotificationPreferenceController;
use App\Http\Requests\Settings\NotificationPreferenceUpdateRequest;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

$testRunId = 'notifications_phase_1_data_model_preferences_'.Carbon::now()->format('Y_m_d_His');
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
function decodeJsonResponse(\Illuminate\Http\JsonResponse $response): array
{
    $decoded = $response->getData(true);
    assertTrue(is_array($decoded), 'response payload decodes to array');

    return $decoded;
}

/**
 * @return array<int, string>
 */
function validationErrorKeys(array $payload, bool $partial): array
{
    $validator = Validator::make($payload, NotificationPreference::validationRules($partial));
    $validator->fails();

    return $validator->errors()->keys();
}

/**
 * @return NotificationPreferenceUpdateRequest
 */
function makeValidatedPreferenceRequest(User $user, array $payload): NotificationPreferenceUpdateRequest
{
    $request = NotificationPreferenceUpdateRequest::create(
        '/settings/notifications',
        'PATCH',
        $payload,
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json']
    );

    $request->setContainer(app());
    $request->setRedirector(app('redirect'));
    $request->setUserResolver(static fn (): User => $user);
    $request->validateResolved();

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

    if (! Schema::hasTable('notification_preferences') || ! Schema::hasTable('notification_logs')) {
        logInfo('notification tables missing; running migrations for testing database');
        Artisan::call('migrate', ['--force' => true]);
        logInfo('Migration output', ['output' => trim(Artisan::output())]);
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: Notifications Phase 1 Data Model & Preferences ===');

    logInfo('Phase 1: Schema and index verification');
    assertTrue(Schema::hasTable('notification_preferences'), 'notification_preferences table exists');
    assertTrue(Schema::hasColumns('notification_preferences', [
        'id',
        'user_id',
        'alert_type',
        'severity_threshold',
        'geofences',
        'subscribed_routes',
        'digest_mode',
        'push_enabled',
        'created_at',
        'updated_at',
    ]), 'notification_preferences has required columns');

    assertTrue(Schema::hasTable('notification_logs'), 'notification_logs table exists');
    assertTrue(Schema::hasColumns('notification_logs', [
        'id',
        'user_id',
        'alert_id',
        'delivery_method',
        'status',
        'sent_at',
        'read_at',
        'dismissed_at',
        'metadata',
        'created_at',
        'updated_at',
    ]), 'notification_logs has required columns');

    assertTrue(Schema::hasIndex('notification_logs', ['user_id', 'status', 'sent_at']), 'notification_logs has user_id+status+sent_at composite index');
    assertTrue(Schema::hasIndex('notification_logs', ['status']), 'notification_logs has status index');
    assertTrue(Schema::hasIndex('notification_logs', ['sent_at']), 'notification_logs has sent_at index');
    assertTrue(! Schema::hasIndex('notification_logs', ['user_id']), 'notification_logs does not have redundant single-column user_id index');

    logInfo('Phase 2: NotificationPreference model defaults, casts, and validation rules');

    $defaults = NotificationPreference::defaultAttributes();
    assertEqual($defaults['alert_type'] ?? null, 'all', 'default alert_type');
    assertEqual($defaults['severity_threshold'] ?? null, 'all', 'default severity_threshold');
    assertEqual($defaults['geofences'] ?? null, [], 'default geofences');
    assertEqual($defaults['subscribed_routes'] ?? null, [], 'default subscribed_routes');
    assertEqual($defaults['digest_mode'] ?? null, false, 'default digest_mode');
    assertEqual($defaults['push_enabled'] ?? null, true, 'default push_enabled');

    $preferenceModel = new NotificationPreference([
        'geofences' => [['name' => 'Downtown', 'lat' => 43.65, 'lng' => -79.38, 'radius_km' => 2]],
        'subscribed_routes' => ['1', '501'],
        'digest_mode' => 1,
        'push_enabled' => 0,
    ]);

    assertEqual($preferenceModel->getFillable(), [
        'user_id',
        'alert_type',
        'severity_threshold',
        'geofences',
        'subscribed_routes',
        'digest_mode',
        'push_enabled',
    ], 'notification preference fillable attributes');
    assertTrue(is_array($preferenceModel->geofences), 'geofences cast to array');
    assertTrue(is_array($preferenceModel->subscribed_routes), 'subscribed_routes cast to array');
    assertTrue($preferenceModel->digest_mode === true, 'digest_mode cast to boolean true');
    assertTrue($preferenceModel->push_enabled === false, 'push_enabled cast to boolean false');

    $validRuleErrors = validationErrorKeys([
        'alert_type' => 'transit',
        'severity_threshold' => 'major',
        'geofences' => [
            ['name' => 'Home', 'lat' => 43.7001, 'lng' => -79.4163, 'radius_km' => 1.5],
        ],
        'subscribed_routes' => ['1', 'GO-LW'],
        'digest_mode' => false,
        'push_enabled' => true,
    ], partial: false);
    assertEqual($validRuleErrors, [], 'full validation accepts valid payload');

    $invalidRuleErrors = validationErrorKeys([
        'alert_type' => 'weather',
        'severity_threshold' => 'urgent',
        'geofences' => [['name' => 'Incomplete Geofence']],
        'subscribed_routes' => [123],
        'digest_mode' => 'yes',
        'push_enabled' => 'enabled',
    ], partial: false);

    foreach ([
        'alert_type',
        'severity_threshold',
        'geofences.0.lat',
        'geofences.0.lng',
        'geofences.0.radius_km',
        'subscribed_routes.0',
        'digest_mode',
        'push_enabled',
    ] as $errorKey) {
        assertTrue(in_array($errorKey, $invalidRuleErrors, true), "invalid payload includes error key: {$errorKey}", [
            'errors' => $invalidRuleErrors,
        ]);
    }

    $unknownGeofenceKeyErrors = validationErrorKeys([
        'alert_type' => 'all',
        'severity_threshold' => 'all',
        'geofences' => [[
            'name' => 'Home',
            'lat' => 43.7,
            'lng' => -79.4,
            'radius_km' => 2,
            'extra' => 'not-allowed',
        ]],
        'subscribed_routes' => [],
        'digest_mode' => false,
        'push_enabled' => true,
    ], partial: false);
    assertTrue(in_array('geofences.0', $unknownGeofenceKeyErrors, true), 'unknown geofence keys are rejected');

    $partialValidErrors = validationErrorKeys([
        'severity_threshold' => 'critical',
    ], partial: true);
    assertEqual($partialValidErrors, [], 'partial validation accepts single-field updates');

    $partialNullErrors = validationErrorKeys([
        'alert_type' => null,
        'severity_threshold' => null,
        'digest_mode' => null,
        'push_enabled' => null,
    ], partial: true);
    foreach (['alert_type', 'severity_threshold', 'digest_mode', 'push_enabled'] as $errorKey) {
        assertTrue(in_array($errorKey, $partialNullErrors, true), "partial validation rejects null: {$errorKey}");
    }

    logInfo('Phase 3: NotificationLog model casts and scopes');

    $logModel = new NotificationLog([
        'sent_at' => '2026-02-10 10:00:00',
        'read_at' => '2026-02-10 10:02:00',
        'dismissed_at' => null,
        'metadata' => ['source' => 'fire'],
    ]);

    assertEqual($logModel->getFillable(), [
        'user_id',
        'alert_id',
        'delivery_method',
        'status',
        'sent_at',
        'read_at',
        'dismissed_at',
        'metadata',
    ], 'notification log fillable attributes');
    assertTrue($logModel->sent_at instanceof DateTimeInterface, 'sent_at cast to DateTime');
    assertTrue($logModel->read_at instanceof DateTimeInterface, 'read_at cast to DateTime');
    assertTrue($logModel->dismissed_at === null, 'dismissed_at remains null');
    assertTrue(is_array($logModel->metadata), 'metadata cast to array');

    $scopeUser = User::factory()->create();
    NotificationLog::factory()->create(['user_id' => $scopeUser->id, 'read_at' => null, 'dismissed_at' => null]);
    NotificationLog::factory()->create(['user_id' => $scopeUser->id, 'read_at' => null, 'dismissed_at' => null]);
    NotificationLog::factory()->create(['user_id' => $scopeUser->id, 'read_at' => now(), 'dismissed_at' => now()]);
    assertEqual(NotificationLog::query()->unread()->count(), 2, 'unread scope count');
    assertEqual(NotificationLog::query()->undismissed()->count(), 2, 'undismissed scope count');

    logInfo('Phase 4: Preference management route + controller behavior');

    $showRoute = Route::getRoutes()->getByName('notifications.show');
    $updateRoute = Route::getRoutes()->getByName('notifications.update');
    assertTrue($showRoute !== null, 'notifications.show route exists');
    assertTrue($updateRoute !== null, 'notifications.update route exists');

    if ($showRoute !== null) {
        assertEqual($showRoute->uri(), 'settings/notifications', 'notifications.show URI');
        assertTrue(in_array('GET', $showRoute->methods(), true), 'notifications.show supports GET');
        assertTrue(in_array('auth', $showRoute->gatherMiddleware(), true), 'notifications.show is protected by auth middleware');
    }

    if ($updateRoute !== null) {
        assertEqual($updateRoute->uri(), 'settings/notifications', 'notifications.update URI');
        assertTrue(in_array('PATCH', $updateRoute->methods(), true), 'notifications.update supports PATCH');
        assertTrue(in_array('auth', $updateRoute->gatherMiddleware(), true), 'notifications.update is protected by auth middleware');
    }

    $controller = app(NotificationPreferenceController::class);
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    NotificationPreference::factory()->create([
        'user_id' => $otherUser->id,
        'alert_type' => 'emergency',
        'severity_threshold' => 'critical',
        'subscribed_routes' => ['GO-KI'],
    ]);

    $showRequestA = Illuminate\Http\Request::create(
        '/settings/notifications',
        'GET',
        [],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json']
    );
    $showRequestA->setUserResolver(static fn (): User => $user);

    $showPayload = decodeJsonResponse($controller->show($showRequestA));
    assertEqual($showPayload['data']['alert_type'] ?? null, 'all', 'show returns default alert_type');
    assertEqual($showPayload['data']['severity_threshold'] ?? null, 'all', 'show returns default severity_threshold');
    assertEqual($showPayload['data']['geofences'] ?? null, [], 'show returns default geofences');
    assertEqual($showPayload['data']['subscribed_routes'] ?? null, [], 'show returns default subscribed_routes');
    assertEqual($showPayload['data']['digest_mode'] ?? null, false, 'show returns default digest_mode');
    assertEqual($showPayload['data']['push_enabled'] ?? null, true, 'show returns default push_enabled');
    assertEqual(NotificationPreference::query()->where('user_id', $user->id)->count(), 1, 'show firstOrCreate creates a single preference row');

    $showRequestB = Illuminate\Http\Request::create(
        '/settings/notifications',
        'GET',
        [],
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json']
    );
    $showRequestB->setUserResolver(static fn (): User => $user);
    $controller->show($showRequestB);
    assertEqual(NotificationPreference::query()->where('user_id', $user->id)->count(), 1, 'repeated show does not create duplicate rows');

    $fullUpdatePayload = [
        'alert_type' => 'transit',
        'severity_threshold' => 'major',
        'geofences' => [
            ['name' => 'Home', 'lat' => 43.7001, 'lng' => -79.4163, 'radius_km' => 2],
        ],
        'subscribed_routes' => ['1', '501', 'GO-LW'],
        'digest_mode' => true,
        'push_enabled' => false,
    ];

    $fullUpdateRequest = makeValidatedPreferenceRequest($user, $fullUpdatePayload);
    $fullUpdateResponse = decodeJsonResponse($controller->update($fullUpdateRequest));

    assertEqual($fullUpdateResponse['data']['alert_type'] ?? null, 'transit', 'full update sets alert_type');
    assertEqual($fullUpdateResponse['data']['severity_threshold'] ?? null, 'major', 'full update sets severity_threshold');
    assertEqual($fullUpdateResponse['data']['subscribed_routes'] ?? null, ['1', '501', 'GO-LW'], 'full update sets subscribed_routes');
    assertEqual($fullUpdateResponse['data']['digest_mode'] ?? null, true, 'full update sets digest_mode');
    assertEqual($fullUpdateResponse['data']['push_enabled'] ?? null, false, 'full update sets push_enabled');

    $storedAfterFullUpdate = NotificationPreference::query()->where('user_id', $user->id)->firstOrFail();
    assertEqual($storedAfterFullUpdate->severity_threshold, 'major', 'DB stored severity after full update');
    assertEqual($storedAfterFullUpdate->alert_type, 'transit', 'DB stored alert_type after full update');
    assertEqual($storedAfterFullUpdate->subscribed_routes, ['1', '501', 'GO-LW'], 'DB stored subscribed routes after full update');
    assertTrue($storedAfterFullUpdate->digest_mode === true, 'DB stored digest_mode after full update');
    assertTrue($storedAfterFullUpdate->push_enabled === false, 'DB stored push_enabled after full update');

    $partialUpdateRequest = makeValidatedPreferenceRequest($user, [
        'severity_threshold' => 'critical',
    ]);
    $partialUpdateResponse = decodeJsonResponse($controller->update($partialUpdateRequest));

    assertEqual($partialUpdateResponse['data']['alert_type'] ?? null, 'transit', 'partial update preserves alert_type');
    assertEqual($partialUpdateResponse['data']['severity_threshold'] ?? null, 'critical', 'partial update updates severity_threshold');
    assertEqual($partialUpdateResponse['data']['subscribed_routes'] ?? null, ['1', '501', 'GO-LW'], 'partial update preserves subscribed_routes');
    assertEqual($partialUpdateResponse['data']['digest_mode'] ?? null, true, 'partial update preserves digest_mode');
    assertEqual($partialUpdateResponse['data']['push_enabled'] ?? null, false, 'partial update preserves push_enabled');
    assertEqual(NotificationPreference::query()->where('user_id', $user->id)->count(), 1, 'repeated update does not create duplicate rows');

    $otherUserPreference = NotificationPreference::query()->where('user_id', $otherUser->id)->firstOrFail();
    assertEqual($otherUserPreference->severity_threshold, 'critical', 'other user preference remains unchanged');
    assertEqual($otherUserPreference->alert_type, 'emergency', 'other user alert type remains unchanged');

    try {
        makeValidatedPreferenceRequest($user, [
            'geofences' => [['name' => 'Incomplete Geofence']],
        ]);

        throw new RuntimeException('Expected invalid geofence payload to fail validation.');
    } catch (ValidationException $e) {
        $errors = array_keys($e->errors());
        foreach (['geofences.0.lat', 'geofences.0.lng', 'geofences.0.radius_km'] as $errorKey) {
            assertTrue(in_array($errorKey, $errors, true), "update request validation catches {$errorKey}", [
                'errors' => $errors,
            ]);
        }
    }

    try {
        makeValidatedPreferenceRequest($user, [
            'alert_type' => 'weather',
            'push_enabled' => 'enabled',
        ]);

        throw new RuntimeException('Expected invalid alert_type/push_enabled payload to fail validation.');
    } catch (ValidationException $e) {
        $errors = array_keys($e->errors());
        assertTrue(in_array('alert_type', $errors, true), 'update request validation catches alert_type');
        assertTrue(in_array('push_enabled', $errors, true), 'update request validation catches push_enabled');
    }

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
} finally {
    if ($txStarted && DB::transactionLevel() > 0) {
        DB::rollBack();
        logInfo('Rolled back transaction; no persistent data changes were kept.');
    }

    logInfo('Manual test log file', ['path' => $logFileRelative]);
}

exit($exitCode);
