<?php

/**
 * Manual Test: Phase 2 Provider Implementations
 * Generated: 2026-02-03
 * Purpose: Verify Fire, Police, and Transit providers map unified columns correctly.
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
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\FireAlertSelectProvider;
use App\Services\Alerts\Providers\PoliceAlertSelectProvider;
use App\Services\Alerts\Providers\TransitAlertSelectProvider;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$testRunId = 'phase_2_providers_'.Carbon::now()->format('Y_m_d_His');
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

function decodeMeta(mixed $value): array
{
    return UnifiedAlertMapper::decodeMeta($value);
}

try {
    DB::beginTransaction();

    logInfo('=== Starting Manual Test: Phase 2 Provider Implementations ===');
    logInfo('Step 1: Preparing test data');

    FireIncident::query()->delete();
    PoliceCall::query()->delete();

    $fireIncident = FireIncident::factory()->create([
        'event_num' => 'F90001',
        'event_type' => 'HIGHRISE FIRE',
        'prime_street' => 'Bay St',
        'cross_streets' => 'Bloor St',
        'dispatch_time' => CarbonImmutable::parse('2026-02-03 09:15:00'),
        'alarm_level' => 3,
        'beat' => '5B',
        'units_dispatched' => 'P10, P11',
        'is_active' => true,
    ]);

    $policeCall = PoliceCall::factory()->create([
        'object_id' => 9911,
        'call_type_code' => 'ASSAULT',
        'call_type' => 'ASSAULT IN PROGRESS',
        'division' => 'D14',
        'cross_streets' => 'King St - Bathurst St',
        'latitude' => 43.645,
        'longitude' => -79.401,
        'occurrence_time' => CarbonImmutable::parse('2026-02-03 10:05:00'),
        'is_active' => false,
    ]);

    logInfo('Created test records', [
        'fire_incident_id' => $fireIncident->id,
        'police_call_id' => $policeCall->id,
    ]);

    logInfo('Step 2: Verifying FireAlertSelectProvider');

    $fireRow = (new FireAlertSelectProvider)->select()->first();
    if ($fireRow === null) {
        throw new \RuntimeException('Fire provider returned no rows.');
    }

    assertEqual($fireRow->id, 'fire:F90001', 'fire.id');
    assertEqual($fireRow->source, 'fire', 'fire.source');
    assertEqual((string) $fireRow->external_id, 'F90001', 'fire.external_id');
    assertEqual((int) $fireRow->is_active, 1, 'fire.is_active');
    assertEqual((string) $fireRow->timestamp, $fireIncident->dispatch_time->format('Y-m-d H:i:s'), 'fire.timestamp');
    assertEqual($fireRow->title, 'HIGHRISE FIRE', 'fire.title');
    assertEqual($fireRow->location_name, 'Bay St / Bloor St', 'fire.location_name');
    assertEqual($fireRow->lat, null, 'fire.lat');
    assertEqual($fireRow->lng, null, 'fire.lng');

    $fireMeta = decodeMeta($fireRow->meta);
    assertEqual($fireMeta['alarm_level'] ?? null, 3, 'fire.meta.alarm_level');
    assertEqual($fireMeta['units_dispatched'] ?? null, 'P10, P11', 'fire.meta.units_dispatched');
    assertEqual($fireMeta['beat'] ?? null, '5B', 'fire.meta.beat');
    assertEqual($fireMeta['event_num'] ?? null, 'F90001', 'fire.meta.event_num');

    logInfo('Step 3: Verifying PoliceAlertSelectProvider');

    $policeRow = (new PoliceAlertSelectProvider)->select()->first();
    if ($policeRow === null) {
        throw new \RuntimeException('Police provider returned no rows.');
    }

    assertEqual($policeRow->id, 'police:9911', 'police.id');
    assertEqual($policeRow->source, 'police', 'police.source');
    assertEqual((string) $policeRow->external_id, '9911', 'police.external_id');
    assertEqual((int) $policeRow->is_active, 0, 'police.is_active');
    assertEqual((string) $policeRow->timestamp, $policeCall->occurrence_time->format('Y-m-d H:i:s'), 'police.timestamp');
    assertEqual($policeRow->title, 'ASSAULT IN PROGRESS', 'police.title');
    assertEqual($policeRow->location_name, 'King St - Bathurst St', 'police.location_name');
    assertEqual((float) $policeRow->lat, 43.645, 'police.lat');
    assertEqual((float) $policeRow->lng, -79.401, 'police.lng');

    $policeMeta = decodeMeta($policeRow->meta);
    assertEqual($policeMeta['division'] ?? null, 'D14', 'police.meta.division');
    assertEqual($policeMeta['call_type_code'] ?? null, 'ASSAULT', 'police.meta.call_type_code');
    assertEqual($policeMeta['object_id'] ?? null, 9911, 'police.meta.object_id');

    logInfo('Step 4: Verifying TransitAlertSelectProvider placeholder');

    $transitRows = (new TransitAlertSelectProvider)->select()->get();
    if ($transitRows->isNotEmpty()) {
        throw new \RuntimeException('Transit provider returned rows when it should be empty.');
    }

    logInfo('Transit provider returned empty results as expected.');
    logInfo('=== Manual Test Completed Successfully ===');
} catch (\Throwable $e) {
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    DB::rollBack();
    logInfo('Transaction rolled back (Database preserved).');
    logInfo('=== Test Run Finished ===');
    echo "\n✓ Full logs at: {$logFileRelative}\n";
}
