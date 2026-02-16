<?php

/**
 * Manual Test: Scene Intel - Phase 3 API (Polling-First)
 * Generated: 2026-02-14
 * Purpose: Verify Scene Intel Phase 3 API deliverables:
 * - Public timeline endpoint contract and chronological ordering
 * - Protected manual-note endpoint route middleware and validation
 * - Manual-note authorization Gate behavior (default + allowlist mode)
 */

require __DIR__.'/../../vendor/autoload.php';

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

use App\Enums\IncidentUpdateType;
use App\Http\Controllers\SceneIntelController;
use App\Http\Requests\SceneIntel\StoreSceneIntelEntryRequest;
use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

$testRunId = 'scene_intel_phase_3_api_polling_first_'.Carbon::now()->format('Y_m_d_His');
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
function decodeJsonResponse(Illuminate\Http\JsonResponse $response): array
{
    $decoded = json_decode((string) $response->getContent(), true);

    if (! is_array($decoded)) {
        throw new RuntimeException('Expected JSON response body to decode to an array.');
    }

    return $decoded;
}

function routeHasMiddleware(Illuminate\Routing\Route $route, string $needle): bool
{
    return in_array($needle, $route->gatherMiddleware(), true);
}

$exitCode = 0;
$txStarted = false;

try {
    try {
        Illuminate\Support\Facades\DB::connection()->getPdo();
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

    if (! Schema::hasTable('fire_incidents') || ! Schema::hasTable('incident_updates')) {
        logInfo('Required tables missing; running migrations for testing database');
        Artisan::call('migrate', ['--force' => true]);
        logInfo('Migration output', ['output' => trim(Artisan::output())]);
    }

    Illuminate\Support\Facades\DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: Scene Intel Phase 3 API (Polling-First) ===');

    logInfo('Phase 1: Route and middleware verification');
    $timelineRoute = app('router')->getRoutes()->getByName('api.incidents.intel.timeline');
    assertTrue($timelineRoute !== null, 'timeline route is registered');

    if ($timelineRoute !== null) {
        assertEqual($timelineRoute->uri(), 'api/incidents/{eventNum}/intel', 'timeline route URI');
        assertEqual($timelineRoute->methods(), ['GET', 'HEAD'], 'timeline route methods');
    }

    $storeRoute = app('router')->getRoutes()->getByName('api.incidents.intel.store');
    assertTrue($storeRoute !== null, 'store route is registered');

    if ($storeRoute !== null) {
        assertEqual($storeRoute->uri(), 'api/incidents/{eventNum}/intel', 'store route URI');
        assertEqual($storeRoute->methods(), ['POST'], 'store route methods');
        assertTrue(routeHasMiddleware($storeRoute, 'auth'), 'store route uses auth middleware');
        assertTrue(routeHasMiddleware($storeRoute, 'verified'), 'store route uses verified middleware');
        assertTrue(
            routeHasMiddleware($storeRoute, 'can:scene-intel.create-manual-entry'),
            'store route uses scene-intel gate middleware'
        );
    }

    logInfo('Phase 2: Timeline payload and ordering verification');
    $incident = FireIncident::factory()->create([
        'event_num' => 'F26061001',
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'update_type' => IncidentUpdateType::RESOURCE_STATUS,
        'content' => 'Unit P101 dispatched',
        'metadata' => ['unit_code' => 'P101', 'status' => 'dispatched'],
        'created_at' => Carbon::parse('2026-02-14 11:01:00'),
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'update_type' => IncidentUpdateType::ALARM_CHANGE,
        'content' => 'Alarm level increased from 1 to 2',
        'metadata' => ['previous_level' => 1, 'new_level' => 2, 'direction' => 'up'],
        'created_at' => Carbon::parse('2026-02-14 11:00:00'),
    ]);

    $controller = app(SceneIntelController::class);
    $timelineResponse = $controller->timeline($incident->event_num);
    $timelinePayload = decodeJsonResponse($timelineResponse);

    assertEqual($timelineResponse->getStatusCode(), 200, 'timeline response status is 200');
    assertEqual($timelinePayload['meta']['event_num'] ?? null, $incident->event_num, 'timeline meta event_num');
    assertEqual($timelinePayload['meta']['count'] ?? null, 2, 'timeline meta count');
    assertEqual($timelinePayload['data'][0]['type'] ?? null, 'alarm_change', 'timeline first item type');
    assertEqual($timelinePayload['data'][1]['type'] ?? null, 'resource_status', 'timeline second item type');

    logInfo('Phase 3: Validation and authorization gate verification');
    $verifiedUser = User::factory()->create([
        'email' => 'dispatcher@example.test',
    ]);
    $unverifiedUser = User::factory()->unverified()->create([
        'email' => 'new.user@example.test',
    ]);

    config(['scene_intel.manual_entry.allowed_emails' => []]);
    assertTrue(
        Gate::forUser($verifiedUser)->denies('scene-intel.create-manual-entry'),
        'verified user denied by default when allowlist is empty'
    );
    assertTrue(
        Gate::forUser($unverifiedUser)->denies('scene-intel.create-manual-entry'),
        'unverified user denied by gate'
    );

    config(['scene_intel.manual_entry.allowed_emails' => ['ops@example.test']]);
    assertTrue(
        Gate::forUser($verifiedUser)->denies('scene-intel.create-manual-entry'),
        'verified user denied when not in allowlist'
    );

    config(['scene_intel.manual_entry.allowed_emails' => ['dispatcher@example.test']]);
    assertTrue(
        Gate::forUser($verifiedUser)->allows('scene-intel.create-manual-entry'),
        'verified allowlisted user is allowed by gate'
    );

    $invalidRequest = StoreSceneIntelEntryRequest::create(
        "/api/incidents/{$incident->event_num}/intel",
        'POST',
        ['content' => '   ', 'metadata' => 'invalid']
    );
    $invalidRequest->setContainer(app());
    $invalidRequest->setRedirector(app('redirect'));
    $invalidRequest->setUserResolver(fn (): User => $verifiedUser);

    $invalidValidationTriggered = false;

    try {
        $invalidRequest->validateResolved();
    } catch (ValidationException $e) {
        $invalidValidationTriggered = true;
        $errors = $e->validator->errors()->toArray();
        assertTrue(array_key_exists('content', $errors), 'blank content validation error exists');
        assertTrue(array_key_exists('metadata', $errors), 'metadata type validation error exists');
    }

    assertTrue($invalidValidationTriggered, 'invalid request triggers validation exception');

    logInfo('Phase 4: Manual store flow verification');
    config(['scene_intel.manual_entry.allowed_emails' => ['dispatcher@example.test']]);

    $validRequest = StoreSceneIntelEntryRequest::create(
        "/api/incidents/{$incident->event_num}/intel",
        'POST',
        [
            'content' => '  Fire is under control  ',
            'metadata' => ['note_source' => 'manual_test'],
        ]
    );
    $validRequest->setContainer(app());
    $validRequest->setRedirector(app('redirect'));
    $validRequest->setUserResolver(fn (): User => $verifiedUser);
    $validRequest->validateResolved();

    $storeResponse = $controller->store($validRequest, $incident->event_num);
    $storePayload = decodeJsonResponse($storeResponse);

    assertEqual($storeResponse->getStatusCode(), 201, 'store response status is 201');
    assertEqual($storePayload['data']['type'] ?? null, 'manual_note', 'store response update type');
    assertEqual($storePayload['data']['content'] ?? null, 'Fire is under control', 'store trims and returns content');

    $storedUpdate = IncidentUpdate::query()
        ->forIncident($incident->event_num)
        ->where('update_type', IncidentUpdateType::MANUAL_NOTE)
        ->latest('id')
        ->first();

    assertTrue($storedUpdate !== null, 'manual note persisted to incident_updates');

    if ($storedUpdate !== null) {
        assertEqual($storedUpdate->source, 'manual', 'manual note source');
        assertEqual($storedUpdate->created_by, $verifiedUser->id, 'manual note created_by');
        assertEqual($storedUpdate->content, 'Fire is under control', 'manual note trimmed content persisted');
    }

    logInfo('=== Scene Intel Phase 3 API manual verification completed successfully ===');
    logInfo('Manual test log file', ['path' => $logFileRelative]);
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual verification failed', [
        'exception' => $e::class,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    if ($txStarted) {
        Illuminate\Support\Facades\DB::rollBack();
    }

    if ($exitCode === 0) {
        echo "\nSUCCESS: Scene Intel Phase 3 API manual verification passed.\n";
        echo "Log: {$logFileRelative}\n";
    } else {
        echo "\nFAILED: Scene Intel Phase 3 API manual verification failed.\n";
        echo "Check log: {$logFileRelative}\n";
    }
}

exit($exitCode);
