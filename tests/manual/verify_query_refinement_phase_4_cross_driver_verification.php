<?php

/**
 * Manual Test: Query Refinement 20260203 - Phase 4 Cross-Driver Verification (MySQL)
 * Generated: 2026-02-05
 * Purpose: Verify provider SQL branches and unified query behavior on MySQL:
 * - Fire/Police providers return MySQL-formatted values (id, location, JSON meta).
 * - UnifiedAlertsQuery executes deterministically against MySQL.
 */

use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\FireAlertSelectProvider;
use App\Services\Alerts\Providers\PoliceAlertSelectProvider;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\UnifiedAlertsTestSeeder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

$testRunId = 'query_refinement_phase_4_cross_driver_verification_'.Carbon::now()->format('Y_m_d_His');
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
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        throw new \RuntimeException(
            "Database connection failed. If you're using Sail, ensure Docker is running and execute: ./vendor/bin/sail php tests/manual/verify_query_refinement_phase_4_cross_driver_verification.php",
            previous: $e
        );
    }

    if (DB::getDriverName() !== 'mysql') {
        throw new \RuntimeException('This manual test requires DB_CONNECTION=mysql.');
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: Query Refinement Phase 4 (Cross-Driver/MySQL) ===');

    logInfo('Step 1: Verify FireAlertSelectProvider MySQL output');
    $fire = FireIncident::factory()->create([
        'event_num' => 'FIRE-MYSQL-1',
        'event_type' => 'ALARM',
        'prime_street' => 'Yonge St',
        'cross_streets' => 'Dundas St',
        'dispatch_time' => CarbonImmutable::parse('2026-02-02 12:34:00'),
        'alarm_level' => 3,
        'beat' => '12A',
        'units_dispatched' => 'P1',
        'is_active' => true,
    ]);

    $fireRow = (new FireAlertSelectProvider)
        ->select()
        ->where('event_num', $fire->event_num)
        ->first();

    assertTrue($fireRow !== null, 'fire provider row exists');
    assertEqual($fireRow->id, 'fire:FIRE-MYSQL-1', 'fire provider id format');
    assertEqual($fireRow->location_name, 'Yonge St / Dundas St', 'fire provider location format');

    $fireMeta = UnifiedAlertMapper::decodeMeta($fireRow->meta);
    assertEqual($fireMeta['event_num'] ?? null, 'FIRE-MYSQL-1', 'fire provider meta event_num');
    assertEqual($fireMeta['alarm_level'] ?? null, 3, 'fire provider meta alarm_level');

    logInfo('Step 2: Verify PoliceAlertSelectProvider MySQL output');
    $police = PoliceCall::factory()->create([
        'object_id' => 4242,
        'call_type_code' => 'THEFT',
        'call_type' => 'THEFT OVER',
        'division' => 'D51',
        'cross_streets' => 'Queen St - Spadina Ave',
        'latitude' => 43.6500,
        'longitude' => -79.3800,
        'occurrence_time' => CarbonImmutable::parse('2026-02-02 13:00:00'),
        'is_active' => false,
    ]);

    $policeRow = (new PoliceAlertSelectProvider)
        ->select()
        ->where('object_id', $police->object_id)
        ->first();

    assertTrue($policeRow !== null, 'police provider row exists');
    assertEqual($policeRow->id, 'police:4242', 'police provider id format');
    assertEqual($policeRow->location_name, 'Queen St - Spadina Ave', 'police provider location');

    $policeMeta = UnifiedAlertMapper::decodeMeta($policeRow->meta);
    assertEqual($policeMeta['object_id'] ?? null, 4242, 'police provider meta object_id');
    assertEqual($policeMeta['division'] ?? null, 'D51', 'police provider meta division');

    logInfo('Step 3: Verify UnifiedAlertsQuery ordering on MySQL');
    FireIncident::query()->delete();
    PoliceCall::query()->delete();

    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    Artisan::call('db:seed', ['--class' => UnifiedAlertsTestSeeder::class]);

    $results = app(UnifiedAlertsQuery::class)->paginate(perPage: 50, status: 'all');
    assertEqual($results->total(), 8, 'unified alerts total');

    $ids = collect($results->items())->map(fn ($a) => $a->id)->values()->all();
    assertEqual($ids, [
        'fire:FIRE-0001',
        'police:900001',
        'fire:FIRE-0002',
        'police:900002',
        'fire:FIRE-0003',
        'police:900003',
        'fire:FIRE-0004',
        'police:900004',
    ], 'unified alerts ordering');

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
