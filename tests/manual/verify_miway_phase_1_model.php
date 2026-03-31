<?php

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALLOW_ROOT_MANUAL_TESTS') !== '1') {
    fwrite(STDERR, "Error: Do not run manual tests as root.\n");
    fwrite(STDERR, "If you really need root, re-run with ALLOW_ROOT_MANUAL_TESTS=1 (not recommended).\n");
    exit(1);
}

$expectedDatabase = 'gta_alerts_testing';
$connection = config('database.default');
$currentDatabase = config("database.connections.{$connection}.database");

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing.\n");
}

if ($currentDatabase !== $expectedDatabase) {
    exit("Error: Manual tests must use the '{$expectedDatabase}' database (current: {$currentDatabase}).\n");
}

umask(002);

use App\Models\MiwayAlert;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

$testRunId = 'miway_phase_1_verify_'.Carbon::now()->format('Y_m_d_His');
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

$passed = 0;
$failed = 0;

function logInfo($msg, $ctx = [])
{
    Log::channel('manual_test')->info($msg, $ctx);
    echo "[INFO] {$msg}\n";
}

function logOk($msg)
{
    global $passed;
    $passed++;
    Log::channel('manual_test')->info("[PASS] {$msg}");
    echo "  ✓ {$msg}\n";
}

function logFail($msg, $ctx = [])
{
    global $failed;
    $failed++;
    Log::channel('manual_test')->error("[FAIL] {$msg}", $ctx);
    echo "  ✗ {$msg}\n";
}

function assert_eq($actual, $expected, $label)
{
    if ($actual === $expected) {
        logOk("{$label} → ".json_encode($actual));
    } else {
        logFail("{$label}: expected ".json_encode($expected).', got '.json_encode($actual));
    }
}

function assert_true($actual, $label)
{
    if ($actual === true) {
        logOk("{$label} is true");
    } else {
        logFail("{$label}: expected true, got ".json_encode($actual));
    }
}

try {
    DB::beginTransaction();

    logInfo('=== Starting Manual Test: MiwayAlert Feature Phase 1 ===');

    logInfo('');
    logInfo('Step 1: Verify miway_alerts schema');

    $hasTable = Schema::hasTable('miway_alerts');
    assert_true($hasTable, "Schema has table 'miway_alerts'");

    $columns = Schema::getColumnListing('miway_alerts');
    $expectedColumns = [
        'id', 'external_id', 'header_text', 'description_text',
        'cause', 'effect', 'starts_at', 'ends_at', 'url', 'detour_pdf_url',
        'is_active', 'feed_updated_at', 'created_at', 'updated_at',
    ];

    foreach ($expectedColumns as $col) {
        assert_true(in_array($col, $columns), "Column '{$col}' exists");
    }

    $indexes = Schema::getIndexes('miway_alerts');
    $indexNames = array_column($indexes, 'name');
    assert_true(in_array('miway_alerts_external_id_unique', $indexNames), "Index 'miway_alerts_external_id_unique' exists");
    assert_true(in_array('miway_alerts_is_active_index', $indexNames), "Index 'miway_alerts_is_active_index' exists");

    logInfo('');
    logInfo('Step 2: Verify MiwayAlert behavior');

    $alert1 = MiwayAlert::factory()->create(['external_id' => 'miway:123', 'is_active' => true]);
    $alert2 = MiwayAlert::factory()->create(['external_id' => 'miway:456', 'is_active' => false]);

    assert_true($alert1->starts_at instanceof \DateTimeInterface, 'starts_at is cast to datetime');
    assert_true($alert1->ends_at instanceof \DateTimeInterface, 'ends_at is cast to datetime');
    assert_true($alert1->feed_updated_at instanceof \DateTimeInterface, 'feed_updated_at is cast to datetime');
    assert_true(is_bool($alert1->is_active), 'is_active is cast to boolean');

    logInfo('');
    logInfo('Step 3: Verify scopeActive');

    $activeCountJustCreated = MiwayAlert::whereIn('id', [$alert1->id, $alert2->id])->active()->count();
    assert_eq($activeCountJustCreated, 1, 'scopeActive() returns only active records');

    logInfo('');
    logInfo('=== Manual Test Completed ===');

} catch (\Exception $e) {
    logFail('Unexpected exception: '.$e->getMessage(), [
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    DB::rollBack();
    logInfo('Transaction rolled back (database preserved).');

    echo "\n";
    echo "Results: {$passed} passed, {$failed} failed\n";
    echo ($failed === 0 ? '✓ All checks passed.' : "✗ {$failed} check(s) failed — review output above.")."\n";
    echo "Full logs at: {$logFileRelative}\n";
}
