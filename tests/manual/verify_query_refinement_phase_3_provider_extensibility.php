<?php

/**
 * Manual Test: Query Refinement 20260203 - Phase 3 Provider Extensibility (Tagged Injection)
 * Generated: 2026-02-04
 * Purpose: Verify UnifiedAlertsQuery resolves AlertSelectProviders via container tags and remains
 * extensible without modifications:
 * - Provider list is resolved via the `alerts.select-providers` tag.
 * - A newly tagged provider contributes rows to the unified feed.
 * - Ordering + status filters remain deterministic with the additional provider.
 * - Empty provider lists return an empty paginator (defensive behavior).
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution
if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Database\Seeders\UnifiedAlertsTestSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$testRunId = 'query_refinement_phase_3_provider_extensibility_'.Carbon::now()->format('Y_m_d_His');
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
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

function assertEqual(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        $message = "Assertion failed for {$label}.";
        logError($message, ['expected' => $expected, 'actual' => $actual]);
        throw new \RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
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

$exitCode = 0;
$txStarted = false;

try {
    // Manual scripts are often executed via Sail (MySQL host = "mysql").
    // Provide a clearer error if Docker/Sail isn't running.
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        throw new \RuntimeException(
            "Database connection failed. If you're using Sail, ensure Docker is running and execute: ./vendor/bin/sail php tests/manual/verify_query_refinement_phase_3_provider_extensibility.php",
            previous: $e
        );
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: Query Refinement Phase 3 (Provider Extensibility) ===');

    logInfo('Step 1: Defensive behavior when there are no providers');
    $emptyQuery = new UnifiedAlertsQuery(providers: [], mapper: new UnifiedAlertMapper);
    $emptyResults = $emptyQuery->paginate(perPage: 50, status: 'all');
    assertEqual($emptyResults->total(), 0, 'empty providers => total 0');
    assertEqual(count($emptyResults->items()), 0, 'empty providers => items empty');

    logInfo('Step 2: Prepare mixed dataset via UnifiedAlertsTestSeeder');

    FireIncident::query()->delete();
    PoliceCall::query()->delete();

    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    Artisan::call('db:seed', ['--class' => UnifiedAlertsTestSeeder::class]);

    assertEqual(FireIncident::count(), 4, 'fire_incidents count');
    assertEqual(PoliceCall::count(), 4, 'police_calls count');

    logInfo('Step 3: Resolve UnifiedAlertsQuery from the container (tagged providers)');
    $alerts = app(UnifiedAlertsQuery::class);
    $baseline = $alerts->paginate(perPage: 50, status: 'all');
    assertEqual($baseline->total(), 8, 'baseline total');

    logInfo('Step 4: Tag a new provider and ensure it contributes rows without modifying the query');

    app()->bind('alerts.providers.manual_fake', function () {
        return new class implements AlertSelectProvider
        {
            public function select(): Builder
            {
                return DB::query()->selectRaw(
                    "? as id,\n                    ? as source,\n                    ? as external_id,\n                    ? as is_active,\n                    ? as timestamp,\n                    ? as title,\n                    ? as location_name,\n                    ? as lat,\n                    ? as lng,\n                    ? as meta",
                    [
                        'manual_fake:1',
                        'manual_fake',
                        '1',
                        1,
                        '2026-02-02 14:00:00',
                        'MANUAL FAKE ALERT',
                        null,
                        null,
                        null,
                        null,
                    ],
                );
            }
        };
    });

    app()->tag(['alerts.providers.manual_fake'], 'alerts.select-providers');
    app()->forgetInstance(UnifiedAlertsQuery::class);

    $alertsWithFake = app(UnifiedAlertsQuery::class);
    $results = $alertsWithFake->paginate(perPage: 50, status: 'all');

    assertEqual($results->total(), 9, 'total includes fake provider row');

    $ids = collect($results->items())->map(fn ($a) => $a->id)->values()->all();
    logInfo('Unified IDs (with fake provider)', ['ids' => $ids]);

    assertEqual($ids[0], 'manual_fake:1', 'fake provider row is first (latest timestamp)');
    assertTrue(in_array('manual_fake:1', $ids, true), 'fake provider row exists in results');

    logInfo('Step 5: Verify status filters remain correct with the additional provider');
    $active = $alertsWithFake->paginate(perPage: 50, status: 'active');
    assertEqual($active->total(), 5, 'active includes fake row');

    $cleared = $alertsWithFake->paginate(perPage: 50, status: 'cleared');
    assertEqual($cleared->total(), 4, 'cleared excludes fake row');

    logInfo('=== Manual Test Completed Successfully ===');
} catch (\Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    // Best-effort cleanup; avoid masking the original failure with a cleanup exception.
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
            // Swallow rollback failures (e.g., DB unreachable) to preserve the original failure signal.
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
