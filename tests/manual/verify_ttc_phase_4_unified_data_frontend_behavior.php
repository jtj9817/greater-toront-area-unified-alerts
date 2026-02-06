<?php

/**
 * Manual Test: TTC Transit Integration - Phase 4 Unified Data + Frontend Behavior
 * Generated: 2026-02-06
 * Purpose: Verify seeded mixed-feed ordering/status behavior and transit-aware
 * payload data required by frontend mapping (severity/effect/route metadata).
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
// Use a deterministic testing-only fallback so middleware/session bootstrapping
// does not fail when making Inertia requests through the HTTP kernel.
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

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Database\Seeders\UnifiedAlertsTestSeeder;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

$testRunId = 'ttc_phase_4_unified_data_frontend_'.Carbon::now()->format('Y_m_d_His');
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
function makeInertiaPayload(array $query = []): array
{
    $httpKernel = app(HttpKernel::class);
    $inertiaMiddleware = app(HandleInertiaRequests::class);

    $request = Request::create('/', 'GET', $query, [], [], [
        'HTTP_X_INERTIA' => 'true',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $version = $inertiaMiddleware->version($request);
    if (is_string($version) && $version !== '') {
        $request->headers->set('X-Inertia-Version', $version);
    }

    $response = $httpKernel->handle($request);

    if (method_exists($httpKernel, 'terminate')) {
        $httpKernel->terminate($request, $response);
    }

    if ($response->getStatusCode() === 409) {
        $location = $response->headers->get('X-Inertia-Location') ?? $response->headers->get('Location');
        $suffix = $location ? " Location: {$location}" : '';

        throw new RuntimeException("Inertia asset version mismatch (409).{$suffix}");
    }

    $payload = json_decode($response->getContent() ?: 'null', true);

    if (! is_array($payload)) {
        throw new RuntimeException('Expected Inertia JSON payload but got non-JSON response.');
    }

    return $payload;
}

$exitCode = 0;
$txStarted = false;

try {
    // Setup: verify DB connectivity and schema prerequisites.
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

    foreach (['fire_incidents', 'police_calls', 'transit_alerts'] as $table) {
        if (! Schema::hasTable($table)) {
            logInfo("{$table} missing; running migrations for testing database");
            Artisan::call('migrate', ['--force' => true]);
            logInfo('Migration output', ['output' => trim(Artisan::output())]);
            break;
        }
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: TTC Transit Phase 4 Unified Data + Frontend Behavior ===');

    logInfo('Phase 1: Setup deterministic mixed-feed data');

    FireIncident::query()->delete();
    PoliceCall::query()->delete();
    TransitAlert::query()->delete();

    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    Artisan::call('db:seed', ['--class' => UnifiedAlertsTestSeeder::class]);

    assertEqual(FireIncident::count(), 4, 'fire_incidents seeded count');
    assertEqual(PoliceCall::count(), 4, 'police_calls seeded count');
    assertEqual(TransitAlert::count(), 2, 'transit_alerts seeded count');
    assertEqual((int) TransitAlert::query()->where('is_active', true)->count(), 1, 'transit active count');
    assertEqual((int) TransitAlert::query()->where('is_active', false)->count(), 1, 'transit cleared count');

    logInfo('Phase 2: Verify unified query ordering and status filters');

    $allResults = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', perPage: 50)
    );
    $allIds = collect($allResults->items())->map(fn ($row) => $row->id)->values()->all();

    assertEqual($allResults->total(), 10, 'status=all total count');
    assertEqual($allIds, [
        'fire:FIRE-0001',
        'police:900001',
        'transit:api:TR-0001',
        'fire:FIRE-0002',
        'police:900002',
        'fire:FIRE-0003',
        'police:900003',
        'transit:sxa:TR-0002',
        'fire:FIRE-0004',
        'police:900004',
    ], 'status=all deterministic id ordering');

    $activeResults = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'active', perPage: 50)
    );
    $activeIds = collect($activeResults->items())->map(fn ($row) => $row->id)->values()->all();

    assertEqual($activeResults->total(), 5, 'status=active total count');
    assertEqual($activeIds, [
        'fire:FIRE-0001',
        'police:900001',
        'transit:api:TR-0001',
        'fire:FIRE-0002',
        'police:900002',
    ], 'status=active deterministic id ordering');

    $clearedResults = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'cleared', perPage: 50)
    );
    $clearedIds = collect($clearedResults->items())->map(fn ($row) => $row->id)->values()->all();

    assertEqual($clearedResults->total(), 5, 'status=cleared total count');
    assertEqual($clearedIds, [
        'fire:FIRE-0003',
        'police:900003',
        'transit:sxa:TR-0002',
        'fire:FIRE-0004',
        'police:900004',
    ], 'status=cleared deterministic id ordering');

    logInfo('Phase 3: Verify Inertia payload shape and transit metadata fields');

    // Ensure transit freshness wins max(feed_updated_at) across all three feeds.
    $transitLatest = Carbon::now()->subMinutes(2);
    TransitAlert::query()
        ->where('external_id', 'api:TR-0001')
        ->update(['feed_updated_at' => $transitLatest]);

    $payloadAll = makeInertiaPayload();
    assertEqual($payloadAll['component'] ?? null, 'gta-alerts', 'home inertia component');

    $rowsAll = $payloadAll['props']['alerts']['data'] ?? null;
    assertTrue(is_array($rowsAll), 'payload contains alerts.data array');
    assertEqual(count($rowsAll), 10, 'home alerts.data count (status=all)');
    assertEqual($payloadAll['props']['latest_feed_updated_at'] ?? null, $transitLatest->toIso8601String(), 'latest_feed_updated_at prefers newest transit feed');

    $transitRow = collect($rowsAll)->first(fn (array $row): bool => ($row['id'] ?? null) === 'transit:api:TR-0001');
    assertTrue(is_array($transitRow), 'home payload includes active transit row');
    assertEqual($transitRow['source'] ?? null, 'transit', 'transit row source');
    assertEqual($transitRow['external_id'] ?? null, 'api:TR-0001', 'transit row external_id');
    assertEqual($transitRow['is_active'] ?? null, true, 'transit row active flag');
    assertEqual($transitRow['meta']['route_type'] ?? null, 'Subway', 'transit meta.route_type');
    assertEqual($transitRow['meta']['severity'] ?? null, 'Critical', 'transit meta.severity');
    assertEqual($transitRow['meta']['effect'] ?? null, 'REDUCED_SERVICE', 'transit meta.effect');
    assertEqual($transitRow['meta']['stop_start'] ?? null, 'Finch', 'transit meta.stop_start');
    assertEqual($transitRow['meta']['stop_end'] ?? null, 'Eglinton', 'transit meta.stop_end');

    Paginator::currentPageResolver(fn () => 1);
    $payloadActive = makeInertiaPayload(['status' => 'active']);
    $rowsActive = $payloadActive['props']['alerts']['data'] ?? null;
    assertTrue(is_array($rowsActive), 'payload contains alerts.data array for status=active');
    assertEqual(count($rowsActive), 5, 'home alerts.data count (status=active)');
    assertEqual($payloadActive['props']['filters']['status'] ?? null, 'active', 'filters.status for active');

    $payloadCleared = makeInertiaPayload(['status' => 'cleared']);
    $rowsCleared = $payloadCleared['props']['alerts']['data'] ?? null;
    assertTrue(is_array($rowsCleared), 'payload contains alerts.data array for status=cleared');
    assertEqual(count($rowsCleared), 5, 'home alerts.data count (status=cleared)');
    assertEqual($payloadCleared['props']['filters']['status'] ?? null, 'cleared', 'filters.status for cleared');
    assertTrue(
        collect($rowsCleared)->contains(fn (array $row): bool => ($row['id'] ?? null) === 'transit:sxa:TR-0002'),
        'cleared payload includes cleared transit row'
    );

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    // Cleanup: always reset global state and rollback transaction.
    try {
        Paginator::currentPageResolver(fn () => 1);
    } catch (Throwable) {
    }

    try {
        Carbon::setTestNow();
    } catch (Throwable) {
    }

    if ($txStarted) {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                logInfo('Transaction rolled back (database preserved).');
            }
        } catch (Throwable $e) {
            logError('Rollback failed', ['message' => $e->getMessage()]);
        }
    }

    logInfo('=== Test Run Finished ===');

    if ($exitCode === 0) {
        echo "\nResult: PASS\nLogs at: {$logFileRelative}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFileRelative}\n";
    }

    exit($exitCode);
}
