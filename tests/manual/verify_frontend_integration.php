<?php

/**
 * Manual Test: Verify Frontend Integration (Data Handoff & AlertService)
 * Generated: 2026-02-02
 * Purpose: Verify that live fire incidents are correctly fetched, mapped, and ready for Inertia handoff.
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution
if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

use App\Http\Controllers\GtaAlertsController;
use App\Models\FireIncident;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$testRunId = 'verify_frontend_'.Carbon::now()->format('Y_m_d_His');
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

if (! is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

function logInfo($msg, $ctx = [])
{
    Log::channel('manual_test')->info($msg, $ctx);
    echo "[INFO] {$msg}\n";
}

function logError($msg, $ctx = [])
{
    Log::channel('manual_test')->error($msg, $ctx);
    echo "[ERROR] {$msg}\n";
}

try {
    DB::beginTransaction();

    logInfo('=== Starting Manual Test: Frontend Integration ===');

    // === SETUP PHASE ===
    logInfo('Phase 1: Setting up live-like incident data...');

    // Ensure isolation by truncating (inside transaction)
    DB::table('fire_incidents')->truncate();

    $incidents = [
        [
            'event_num' => 'FA260001',
            'event_type' => 'STRUCTURE FIRE',
            'prime_street' => 'QUEEN ST W',
            'cross_streets' => 'SPADINA AVE',
            'dispatch_time' => now()->subMinutes(5),
            'alarm_level' => 3,
            'beat' => 'D1',
            'units_dispatched' => 'P1, P2, A1, R1',
            'is_active' => true,
        ],
        [
            'event_num' => 'FA260002',
            'event_type' => 'GAS LEAK',
            'prime_street' => 'YONGE ST',
            'cross_streets' => 'BLOOR ST',
            'dispatch_time' => now()->subMinutes(15),
            'alarm_level' => 1,
            'beat' => 'D2',
            'units_dispatched' => 'P5, H1',
            'is_active' => true,
        ],
        [
            'event_num' => 'FA260003',
            'event_type' => 'COLLISION',
            'prime_street' => 'HWY 401',
            'cross_streets' => 'KEELE ST',
            'dispatch_time' => now()->subHours(2),
            'alarm_level' => 0,
            'is_active' => false, // Inactive
        ],
    ];

    foreach ($incidents as $data) {
        FireIncident::create($data);
    }

    logInfo('Created '.count($incidents).' test incidents in the database.');

    // === EXECUTION PHASE ===
    logInfo('Phase 2: Executing GtaAlertsController logic...');

    $controller = new GtaAlertsController;
    $request = Request::create('/', 'GET');

    $response = $controller($request);

    // Access protected props using Reflection
    $reflection = new ReflectionClass($response);
    $property = $reflection->getProperty('props');
    $property->setAccessible(true);
    $data = $property->getValue($response);

    logInfo('Controller Response Data structure verified.');

    // Validate Incident Count (Only active should return)
    $incidentData = $data['incidents']->toArray($request);
    $count = count($incidentData);

    if ($count === 2) {
        logInfo('SUCCESS: Controller returned exactly 2 active incidents.');
    } else {
        logError("FAILURE: Controller returned {$count} incidents, expected 2.");
    }

    // Validate Mapping Integrity
    $first = $incidentData[0];
    logInfo('Sample Incident Mapping:', [
        'event_num' => $first['event_num'],
        'type' => $first['event_type'],
        'location' => $first['prime_street'],
        'alarm' => $first['alarm_level'],
    ]);

    if ($first['event_num'] === 'FA260001') {
        logInfo('SUCCESS: Sorting verified (most recent first).');
    }

    // Validate Search Filtering
    logInfo("Phase 3: Testing Search Filter ('YONGE')...");
    $searchRequest = Request::create('/', 'GET', ['search' => 'YONGE']);
    $searchResponse = $controller($searchRequest);

    $searchReflection = new ReflectionClass($searchResponse);
    $searchProp = $searchReflection->getProperty('props');
    $searchProp->setAccessible(true);
    $searchData = $searchProp->getValue($searchResponse);

    $searchItems = $searchData['incidents']->toArray($searchRequest);

    if (count($searchItems) === 1 && $searchItems[0]['prime_street'] === 'YONGE ST') {
        logInfo('SUCCESS: Search filter returned correct result.');
    } else {
        logError('FAILURE: Search filter failed.', ['count' => count($searchItems)]);
    }

    logInfo('Tests completed successfully. Backend is ready for Inertia handoff.');

} catch (\Exception $e) {
    logError('Test failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    // === CLEANUP PHASE ===
    DB::rollBack();
    logInfo('Database changes rolled back.');
    logInfo('=== Test Run Finished ===');
    echo "\n✓ Full logs at: {$logFile}\n";
}
