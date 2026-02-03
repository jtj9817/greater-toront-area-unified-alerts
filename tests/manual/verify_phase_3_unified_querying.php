<?php
/**
 * Manual Test: Phase 3 Unified Querying (UNION + Pagination + Resource Shape)
 * Generated: 2026-02-03
 * Purpose: Verify UnifiedAlertsQuery returns a mixed feed with deterministic ordering and that
 * UnifiedAlertResource maps DTOs into the expected transport JSON shape.
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution
if (app()->environment('production')) {
    die("Error: Cannot run manual tests in production!\n");
}

use App\Http\Resources\UnifiedAlertResource;
use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Database\Seeders\UnifiedAlertsTestSeeder;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\{Artisan, DB, Log};

$testRunId = 'phase_3_unified_querying_' . Carbon::now()->format('Y_m_d_His');
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

try {
    // Manual scripts are usually executed via Sail (MySQL host = "mysql").
    // Provide a clearer error if Docker/Sail isn't running.
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        throw new \RuntimeException(
            "Database connection failed. If you're using Sail, ensure Docker is running and execute: ./vendor/bin/sail php tests/manual/verify_phase_3_unified_querying.php",
            previous: $e
        );
    }

    DB::beginTransaction();
    logInfo('=== Starting Manual Test: Phase 3 Unified Querying ===');

    logInfo('Step 1: Preparing mixed dataset via UnifiedAlertsTestSeeder');

    FireIncident::query()->delete();
    PoliceCall::query()->delete();

    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    Artisan::call('db:seed', ['--class' => UnifiedAlertsTestSeeder::class]);

    assertEqual(FireIncident::count(), 4, 'fire_incidents count');
    assertEqual(PoliceCall::count(), 4, 'police_calls count');

    logInfo('Step 2: Verifying UnifiedAlertsQuery (status=all, page=1)');

    $alerts = app(UnifiedAlertsQuery::class);
    $page1 = $alerts->paginate(perPage: 3, status: 'all');
    assertEqual($page1->total(), 8, 'unified total');
    assertEqual($page1->count(), 3, 'page 1 count');

    $page1Ids = collect($page1->items())->map(fn ($a) => $a->id)->values()->all();
    logInfo('Page 1 IDs', ['ids' => $page1Ids]);
    assertEqual($page1Ids, ['fire:FIRE-0001', 'police:900001', 'fire:FIRE-0002'], 'page 1 ordering');

    logInfo('Step 3: Verifying pagination (status=all, page=2)');

    Paginator::currentPageResolver(fn () => 2);
    $page2 = $alerts->paginate(perPage: 3, status: 'all');
    assertEqual($page2->currentPage(), 2, 'page 2 current page');

    $page2Ids = collect($page2->items())->map(fn ($a) => $a->id)->values()->all();
    logInfo('Page 2 IDs', ['ids' => $page2Ids]);
    assertEqual($page2Ids, ['police:900002', 'fire:FIRE-0003', 'police:900003'], 'page 2 ordering');

    logInfo('Step 4: Verifying status filters');

    Paginator::currentPageResolver(fn () => 1);
    $active = $alerts->paginate(perPage: 50, status: 'active');
    assertEqual($active->total(), 4, 'active total');

    $cleared = $alerts->paginate(perPage: 50, status: 'cleared');
    assertEqual($cleared->total(), 4, 'cleared total');

    logInfo('Step 5: Verifying UnifiedAlertResource mapping for the first item');
    $first = $page1->items()[0];

    $payload = (new UnifiedAlertResource($first))->toArray(Request::create('/', 'GET'));

    assertEqual($payload['id'], 'fire:FIRE-0001', 'resource.id');
    assertEqual($payload['source'], 'fire', 'resource.source');
    assertEqual($payload['external_id'], 'FIRE-0001', 'resource.external_id');
    assertEqual($payload['is_active'], true, 'resource.is_active');
    assertEqual($payload['title'], 'STRUCTURE FIRE', 'resource.title');

    logInfo('Sample resource payload', ['payload' => $payload]);

    logInfo('=== Manual Test Completed Successfully ===');
} catch (\Throwable $e) {
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    Paginator::currentPageResolver(fn () => 1);
    Carbon::setTestNow();
    DB::rollBack();

    logInfo('Transaction rolled back (Database preserved).');
    logInfo('=== Test Run Finished ===');
    echo "\n✓ Full logs at: {$logFile}\n";
}
