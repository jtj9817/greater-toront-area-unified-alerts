<?php
/**
 * Manual Test: Phase 1 Foundations (DTOs & Seeders)
 * Generated: 2026-02-02
 * Purpose: Verify that the Unified Alerts DTOs and UnifiedAlertsTestSeeder are functional.
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution
if (app()->environment('production')) {
    die("Error: Cannot run manual tests in production!\n");
}

use Illuminate\Support\Facades\{DB, Log, Artisan};
use Carbon\Carbon;
use App\Services\Alerts\DTOs\AlertLocation;
use App\Services\Alerts\DTOs\UnifiedAlert;
use App\Models\FireIncident;
use App\Models\PoliceCall;
use Database\Seeders\UnifiedAlertsTestSeeder;

$testRunId = 'phase_1_verify_' . Carbon::now()->format('Y_m_d_His');
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

function logInfo($msg, $ctx = []) {
    Log::channel('manual_test')->info($msg, $ctx);
    echo "[INFO] {$msg}\n";
}

function logError($msg, $ctx = []) {
    Log::channel('manual_test')->error($msg, $ctx);
    echo "[ERROR] {$msg}\n";
}

try {
    // We use a transaction to avoid polluting the database, though we'll also verify seeding.
    DB::beginTransaction();
    
    logInfo("=== Starting Manual Test: Phase 1 Foundations ===");

    // === PHASE 1: DTO VERIFICATION ===
    logInfo("Step 1: Verifying DTO Instantiation");
    
    $location = new AlertLocation(
        name: 'Test Location',
        lat: 43.6532,
        lng: -79.3832,
        postalCode: 'M5H'
    );
    
    $alert = new UnifiedAlert(
        id: 'fire:TEST123',
        source: 'fire',
        externalId: 'TEST123',
        isActive: true,
        timestamp: now()->toImmutable(),
        title: 'TEST ALERT',
        location: $location,
        meta: ['test' => true]
    );

    if ($alert->id === 'fire:TEST123' && $alert->location->name === 'Test Location') {
        logInfo("DTO Verification Passed: Correctly instantiated and accessible.");
    } else {
        throw new \Exception("DTO Verification Failed: Data mismatch.");
    }

    // === PHASE 2: SEEDER VERIFICATION ===
    logInfo("Step 2: Verifying UnifiedAlertsTestSeeder");
    
    // Clear existing for a clean count in this transaction
    FireIncident::query()->delete();
    PoliceCall::query()->delete();
    
    logInfo("Running UnifiedAlertsTestSeeder...");
    Artisan::call('db:seed', ['--class' => UnifiedAlertsTestSeeder::class]);
    
    $stats = [
        'Fire Active' => FireIncident::where('is_active', true)->count(),
        'Fire Cleared' => FireIncident::where('is_active', false)->count(),
        'Police Active' => PoliceCall::where('is_active', true)->count(),
        'Police Cleared' => PoliceCall::where('is_active', false)->count(),
    ];
    
    logInfo("Seeding Stats:", $stats);
    
    foreach ($stats as $key => $count) {
        if ($count === 0) {
            throw new \Exception("Seeder Verification Failed: {$key} has 0 records.");
        }
    }
    
    logInfo("Seeder Verification Passed: Mixed dataset generated.");

    logInfo("=== Manual Test Completed Successfully ===");
    
} catch (\Exception $e) {
    logError("Manual Test Failed", [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
} finally {
    // Rollback to keep DB clean
    DB::rollBack();
    logInfo("Transaction rolled back (Database preserved).");
    logInfo("=== Test Run Finished ===");
    echo "\n✓ Full logs at: {$logFile}\n";
}
