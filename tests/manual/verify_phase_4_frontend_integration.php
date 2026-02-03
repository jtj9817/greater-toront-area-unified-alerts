<?php
/**
 * Manual Test: Phase 4 Frontend Integration (GtaAlertsController hard switch)
 * Generated: 2026-02-03
 * Purpose: Verify GtaAlertsController returns the unified `alerts` prop (not `incidents`),
 * supports status filtering, and includes a mixed feed suitable for the GTA Alerts Inertia page.
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution
if (app()->environment('production')) {
    die("Error: Cannot run manual tests in production!\n");
}

use App\Http\Controllers\GtaAlertsController;
use App\Models\FireIncident;
use App\Models\PoliceCall;
use Carbon\Carbon;
use Database\Seeders\UnifiedAlertsTestSeeder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\{Artisan, DB, Log};

$testRunId = 'phase_4_frontend_integration_' . Carbon::now()->format('Y_m_d_His');
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

if (! is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

function logInfo(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->info($msg, $ctx);
    echo "[INFO] {$msg}\n";
}

function logError(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->error($msg, $ctx);
    echo "[ERROR] {$msg}\n";
}

function assertTrue(bool $condition, string $label, array $ctx = []): void
{
    if (! $condition) {
        $message = "Assertion failed: {$label}.";
        logError($message, $ctx);
        throw new \RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function assertEqual(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        $message = "Assertion failed for {$label}.";
        logError($message, ['expected' => $expected, 'actual' => $actual]);
        throw new \RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

$exitCode = 0;
$txStarted = false;

try {
    // Manual scripts are usually executed via Sail (MySQL host = "mysql").
    // Provide a clearer error if Docker/Sail isn't running.
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        throw new \RuntimeException(
            "Database connection failed. If you're using Sail, ensure Docker is running and execute: ./vendor/bin/sail php tests/manual/verify_phase_4_frontend_integration.php",
            previous: $e
        );
    }

    DB::beginTransaction();
    $txStarted = true;
    logInfo('=== Starting Manual Test: Phase 4 Frontend Integration ===');

    logInfo('Step 1: Seeding mixed dataset via UnifiedAlertsTestSeeder');

    FireIncident::query()->delete();
    PoliceCall::query()->delete();

    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    Artisan::call('db:seed', ['--class' => UnifiedAlertsTestSeeder::class]);

    assertEqual(FireIncident::count(), 4, 'fire_incidents count');
    assertEqual(PoliceCall::count(), 4, 'police_calls count');

    logInfo('Step 2: Executing GtaAlertsController (status=all)');

    $controller = app(GtaAlertsController::class);
    $request = Request::create('/', 'GET');
    $response = $controller($request);

    $reflection = new ReflectionClass($response);
    $property = $reflection->getProperty('props');
    $property->setAccessible(true);
    $props = $property->getValue($response);

    assertTrue(isset($props['alerts']), 'props contains alerts');
    assertTrue(! isset($props['incidents']), 'props does not contain incidents');

    $collection = $props['alerts'];
    $payload = $collection->toArray($request);

    assertTrue(is_array($payload) && isset($payload['data']) && is_array($payload['data']), 'alerts payload has data');
    assertEqual(count($payload['data']), 8, 'alerts count (status=all)');

    $ids = collect($payload['data'])->map(fn (array $row) => $row['id'])->values()->all();
    logInfo('Sample IDs', ['ids' => array_slice($ids, 0, 5)]);
    assertEqual($ids[0], 'fire:FIRE-0001', 'first id ordering');

    logInfo('Step 3: Verifying status filter (status=active)');

    Paginator::currentPageResolver(fn () => 1);
    $activeRequest = Request::create('/', 'GET', ['status' => 'active']);
    $activeResponse = $controller($activeRequest);

    $activeReflection = new ReflectionClass($activeResponse);
    $activePropsProperty = $activeReflection->getProperty('props');
    $activePropsProperty->setAccessible(true);
    $activeProps = $activePropsProperty->getValue($activeResponse);

    $activePayload = $activeProps['alerts']->toArray($activeRequest);
    assertEqual(count($activePayload['data']), 4, 'alerts count (status=active)');
    assertEqual($activeProps['filters']['status'] ?? null, 'active', 'filters.status = active');

    logInfo('=== Manual Test Completed Successfully ===');
} catch (\Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    try {
        Paginator::currentPageResolver(fn () => 1);
    } catch (\Throwable) {
    }

    try {
        Carbon::setTestNow();
    } catch (\Throwable) {
    }

    if ($txStarted) {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                logInfo('Transaction rolled back (Database preserved).');
            }
        } catch (\Throwable) {
        }
    }

    logInfo('=== Test Run Finished ===');

    if ($exitCode === 0) {
        echo "\nResult: PASS\nLogs at: {$logFile}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFile}\n";
    }

    exit($exitCode);
}

