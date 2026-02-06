<?php

/**
 * Manual Test: TTC Transit Integration - Phase 3 Unified Backend Integration
 * Generated: 2026-02-06
 * Purpose: Verify transit provider mapping, controller freshness aggregation, and
 * scheduler registration for `transit:fetch-alerts`.
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
// Use a deterministic, testing-only fallback so encrypted middleware/session
// bootstrapping does not fail during Inertia request handling.
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
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\TransitAlertSelectProvider;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

$testRunId = 'ttc_phase_3_backend_integration_'.Carbon::now()->format('Y_m_d_His');
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

    if (! Schema::hasTable('transit_alerts')) {
        logInfo('transit_alerts missing; running migrations for testing database');
        Artisan::call('migrate', ['--force' => true]);
        logInfo('Migration output', ['output' => trim(Artisan::output())]);
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: TTC Transit Phase 3 Backend Integration ===');

    logInfo('Phase 1: Verify TransitAlertSelectProvider unified mapping');

    TransitAlert::query()->delete();

    $seededTransit = TransitAlert::factory()->create([
        'external_id' => 'api:phase3-transit-1',
        'source_feed' => 'live-api',
        'route_type' => 'Subway',
        'route' => '1',
        'title' => 'Line 1 early closure',
        'description' => 'No subway service between Finch and Eglinton.',
        'severity' => 'Critical',
        'effect' => 'REDUCED_SERVICE',
        'alert_type' => 'Planned',
        'direction' => 'Both Ways',
        'cause' => 'Other',
        'stop_start' => 'Finch',
        'stop_end' => 'Eglinton',
        'url' => 'https://www.ttc.ca/service-alerts',
        'active_period_start' => CarbonImmutable::parse('2026-02-06 11:59:00'),
        'is_active' => true,
    ]);

    $providerRow = (new TransitAlertSelectProvider)->select()
        ->where('external_id', 'api:phase3-transit-1')
        ->first();

    assertTrue($providerRow !== null, 'transit provider returns row for persisted transit alert');
    assertEqual($providerRow->id, 'transit:api:phase3-transit-1', 'provider.id');
    assertEqual($providerRow->source, 'transit', 'provider.source');
    assertEqual((string) $providerRow->external_id, 'api:phase3-transit-1', 'provider.external_id');
    assertEqual((int) $providerRow->is_active, 1, 'provider.is_active');
    assertEqual((string) $providerRow->timestamp, $seededTransit->active_period_start?->format('Y-m-d H:i:s'), 'provider.timestamp');
    assertEqual($providerRow->title, 'Line 1 early closure', 'provider.title');
    assertEqual($providerRow->location_name, 'Route 1: Finch to Eglinton', 'provider.location_name');
    assertEqual($providerRow->lat, null, 'provider.lat');
    assertEqual($providerRow->lng, null, 'provider.lng');

    $meta = UnifiedAlertMapper::decodeMeta($providerRow->meta);
    assertEqual($meta['route_type'] ?? null, 'Subway', 'provider.meta.route_type');
    assertEqual($meta['route'] ?? null, '1', 'provider.meta.route');
    assertEqual($meta['severity'] ?? null, 'Critical', 'provider.meta.severity');
    assertEqual($meta['effect'] ?? null, 'REDUCED_SERVICE', 'provider.meta.effect');
    assertEqual($meta['source_feed'] ?? null, 'live-api', 'provider.meta.source_feed');
    assertEqual($meta['alert_type'] ?? null, 'Planned', 'provider.meta.alert_type');
    assertEqual($meta['description'] ?? null, 'No subway service between Finch and Eglinton.', 'provider.meta.description');
    assertEqual($meta['url'] ?? null, 'https://www.ttc.ca/service-alerts', 'provider.meta.url');
    assertEqual($meta['direction'] ?? null, 'Both Ways', 'provider.meta.direction');
    assertEqual($meta['cause'] ?? null, 'Other', 'provider.meta.cause');

    logInfo('Phase 2: Verify home payload freshness and transit inclusion');

    FireIncident::query()->delete();
    PoliceCall::query()->delete();

    $fireLatest = CarbonImmutable::parse('2026-02-06 11:55:00');
    $policeLatest = CarbonImmutable::parse('2026-02-06 11:56:00');
    $transitLatest = CarbonImmutable::parse('2026-02-06 11:59:30');

    FireIncident::factory()->create([
        'event_num' => 'PH3-FIRE-1',
        'event_type' => 'STRUCTURE FIRE',
        'dispatch_time' => CarbonImmutable::parse('2026-02-06 11:45:00'),
        'feed_updated_at' => $fireLatest,
        'is_active' => true,
    ]);

    PoliceCall::factory()->create([
        'object_id' => 930001,
        'call_type' => 'ASSAULT',
        'occurrence_time' => CarbonImmutable::parse('2026-02-06 11:50:00'),
        'feed_updated_at' => $policeLatest,
        'is_active' => true,
    ]);

    TransitAlert::query()
        ->where('external_id', 'api:phase3-transit-1')
        ->update(['feed_updated_at' => $transitLatest]);

    $payload = makeInertiaPayload();

    assertEqual($payload['component'] ?? null, 'gta-alerts', 'home inertia component');

    $latestFeedUpdatedAt = $payload['props']['latest_feed_updated_at'] ?? null;
    assertEqual($latestFeedUpdatedAt, $transitLatest->toIso8601String(), 'latest_feed_updated_at prefers newest transit feed time');

    $alertRows = $payload['props']['alerts']['data'] ?? null;
    assertTrue(is_array($alertRows), 'home payload includes alerts.data array');

    $transitRow = collect($alertRows)->first(fn (array $row): bool => ($row['source'] ?? null) === 'transit');
    assertTrue(is_array($transitRow), 'home payload includes transit source row');
    assertEqual($transitRow['id'] ?? null, 'transit:api:phase3-transit-1', 'transit row id in home payload');
    assertEqual($transitRow['external_id'] ?? null, 'api:phase3-transit-1', 'transit row external_id in home payload');

    logInfo('Phase 3: Verify schedule registration for transit fetch command');

    $schedule = app(Schedule::class);
    $transitEvent = collect($schedule->events())
        ->first(fn ($event) => is_string($event->command) && str_contains($event->command, 'transit:fetch-alerts'));

    assertTrue($transitEvent !== null, 'transit:fetch-alerts exists in scheduler');
    assertEqual($transitEvent->expression, '*/5 * * * *', 'transit schedule runs every five minutes');
    assertTrue($transitEvent->withoutOverlapping === true, 'transit schedule enables withoutOverlapping');

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    try {
        Carbon::setTestNow();
    } catch (Throwable) {
    }

    if ($txStarted) {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                logInfo('Transaction rolled back (Database preserved).');
            }
        } catch (Throwable) {
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
