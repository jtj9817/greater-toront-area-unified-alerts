<?php

/**
 * Manual Test: Query Refinement 20260203 - Phase 5 Type-Safe Boundary
 * Generated: 2026-02-05
 * Purpose: Verify type-safe contracts (AlertStatus/AlertSource/Criteria/AlertId)
 * are enforced at the service boundary and controller integration points.
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution
if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALLOW_ROOT_MANUAL_TESTS') !== '1') {
    fwrite(STDERR, "Error: Do not run manual tests as root. Use `./vendor/bin/sail shell` (or `./vendor/bin/sail php ...`).\n");
    fwrite(STDERR, "If you really need root, re-run with ALLOW_ROOT_MANUAL_TESTS=1 (not recommended).\n");
    exit(1);
}

// Manual tests can delete data; only allow the dedicated testing database (no overrides).
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

use App\Http\Controllers\GtaAlertsController;
use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Database\Seeders\UnifiedAlertsTestSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

$testRunId = 'query_refinement_phase_5_type_safe_boundary_'.Carbon::now()->format('Y_m_d_His');
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

function assertThrows(string $label, callable $fn, string $expectedClass): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        assertTrue(is_a($e, $expectedClass), "{$label}: throws {$expectedClass}", [
            'actual_class' => $e::class,
            'message' => $e->getMessage(),
        ]);

        return;
    }

    throw new \RuntimeException("Assertion failed: {$label} did not throw {$expectedClass}.");
}

$exitCode = 0;
$txStarted = false;

try {
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        throw new \RuntimeException(
            "Database connection failed. If you're using Sail, ensure Docker is running and execute: ./vendor/bin/sail php tests/manual/verify_query_refinement_phase_5_type_safe_boundary.php",
            previous: $e
        );
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: Query Refinement Phase 5 (Type-Safe Boundary) ===');

    logInfo('Step 1: Criteria validation (status/perPage/page bounds)');
    assertThrows('criteria rejects invalid status', fn () => new UnifiedAlertsCriteria(status: 'invalid'), \InvalidArgumentException::class);
    assertThrows('criteria rejects perPage=0', fn () => new UnifiedAlertsCriteria(perPage: 0), \InvalidArgumentException::class);
    assertThrows('criteria rejects page=0', fn () => new UnifiedAlertsCriteria(page: 0), \InvalidArgumentException::class);

    logInfo('Step 2: Seeding mixed dataset via UnifiedAlertsTestSeeder');

    FireIncident::query()->delete();
    PoliceCall::query()->delete();

    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    Artisan::call('db:seed', ['--class' => UnifiedAlertsTestSeeder::class]);

    assertEqual(FireIncident::count(), 4, 'fire_incidents count');
    assertEqual(PoliceCall::count(), 4, 'police_calls count');

    logInfo('Step 3: UnifiedAlertsQuery honors explicit page criteria');
    $alerts = app(UnifiedAlertsQuery::class);
    $page2 = $alerts->paginate(new UnifiedAlertsCriteria(status: 'all', perPage: 3, page: 2));

    assertEqual($page2->currentPage(), 2, 'page 2 current page');
    assertEqual($page2->perPage(), 3, 'page 2 perPage');

    $page2Ids = collect($page2->items())->map(fn ($a) => $a->id)->values()->all();
    assertEqual($page2Ids, ['police:900002', 'fire:FIRE-0003', 'police:900003'], 'page 2 ids');

    logInfo('Step 4: AlertId enforces canonical {source}:{externalId}');
    $query = new UnifiedAlertsQuery(
        providers: [
            new class implements AlertSelectProvider
            {
                public function source(): string
                {
                    return 'fire';
                }

                public function select(UnifiedAlertsCriteria $criteria): Builder
                {
                    return DB::query()->selectRaw(
                        "? as id,\n                        ? as source,\n                        ? as external_id,\n                        ? as is_active,\n                        ? as timestamp,\n                        ? as title,\n                        ? as location_name,\n                        ? as lat,\n                        ? as lng,\n                        ? as meta",
                        [
                            'fire:WRONG',
                            $this->source(),
                            'RIGHT',
                            1,
                            '2026-02-02 12:00:00',
                            'TEST ALERT',
                            null,
                            null,
                            null,
                            null,
                        ],
                    );
                }
            },
        ],
        mapper: new UnifiedAlertMapper,
    );

    $derived = $query->paginate(new UnifiedAlertsCriteria(status: 'all', perPage: 50));
    assertEqual($derived->items()[0]->id, 'fire:RIGHT', 'alert id derived from source/externalId');

    logInfo('Step 5: Controller validates status and emits filters.status');
    $controller = app(GtaAlertsController::class);
    $activeRequest = Request::create('/', 'GET', ['status' => 'active']);
    $activeResponse = app()->call($controller, ['request' => $activeRequest]);

    $activeReflection = new ReflectionClass($activeResponse);
    $activePropsProperty = $activeReflection->getProperty('props');
    $activePropsProperty->setAccessible(true);
    $activeProps = $activePropsProperty->getValue($activeResponse);

    $activePayload = $activeProps['alerts']->toResponse($activeRequest)->getData(true);
    assertEqual(count($activePayload['data']), 4, 'alerts count (status=active)');
    assertEqual($activeProps['filters']['status'] ?? null, 'active', 'filters.status = active');

    assertThrows(
        'controller rejects invalid status',
        fn () => app()->call($controller, ['request' => Request::create('/', 'GET', ['status' => 'invalid'])]),
        ValidationException::class,
    );

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
        echo "\nResult: PASS\nLogs at: {$logFileRelative}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFileRelative}\n";
    }

    exit($exitCode);
}
