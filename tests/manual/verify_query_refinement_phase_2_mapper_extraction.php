<?php

/**
 * Manual Test: Query Refinement 20260203 - Phase 2 Mapper Extraction
 * Generated: 2026-02-04
 * Purpose: Verify UnifiedAlertMapper contracts are enforced deterministically:
 * - Meta decoding (null/empty/invalid/scalar/object/array) never leaks exceptions
 * - Location construction rules (null vs name-only vs coords-only; zero coords preserved)
 * - Timestamp contract is fail-fast (missing/unparseable throws InvalidArgumentException)
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

use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

$testRunId = 'query_refinement_phase_2_mapper_extraction_'.Carbon::now()->format('Y_m_d_His');
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

function assertThrows(string $label, callable $fn, string $expectedClass): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        assertTrue($e instanceof $expectedClass, "{$label} throws {$expectedClass}", [
            'actual_class' => get_class($e),
            'message' => $e->getMessage(),
        ]);

        return;
    }

    throw new \RuntimeException("Assertion failed: {$label} expected exception {$expectedClass} but none was thrown.");
}

$exitCode = 0;

try {
    logInfo('=== Starting Manual Test: Query Refinement Phase 2 (Mapper Extraction) ===');

    $mapper = new UnifiedAlertMapper;

    logInfo('Step 1: Verifying meta decoding contract');
    $metaCases = [
        'null meta' => [null, []],
        'empty meta string' => ['', []],
        'invalid json string' => ['{', []],
        'valid json object string' => ['{"k":1}', ['k' => 1]],
        'valid json array string' => ['[1,2]', [1, 2]],
        'valid json scalar string' => ['"k"', []],
    ];

    foreach ($metaCases as $label => [$meta, $expected]) {
        assertEqual(UnifiedAlertMapper::decodeMeta($meta), $expected, "meta case: {$label}");
    }

    $metaArray = ['a' => 1, 'b' => ['c' => 2]];
    assertEqual(UnifiedAlertMapper::decodeMeta($metaArray), $metaArray, 'meta arrays return as-is');

    logInfo('Step 2: Verifying location construction rules');
    $noLocation = $mapper->fromRow((object) [
        'id' => 'fire:nolocation',
        'source' => 'fire',
        'external_id' => 'nolocation',
        'is_active' => 1,
        'timestamp' => '2026-02-02 12:00:00',
        'title' => 'TEST',
        'location_name' => null,
        'lat' => null,
        'lng' => null,
        'meta' => null,
    ]);
    assertTrue($noLocation->location === null, 'location null when all fields null');

    $coordsOnly = $mapper->fromRow((object) [
        'id' => 'fire:coords',
        'source' => 'fire',
        'external_id' => 'coords',
        'is_active' => 1,
        'timestamp' => '2026-02-02 12:00:00',
        'title' => 'TEST',
        'location_name' => null,
        'lat' => 43.65,
        'lng' => -79.38,
        'meta' => null,
    ]);
    assertTrue($coordsOnly->location !== null, 'coords-only => location not null');
    assertEqual($coordsOnly->location?->name, null, 'coords-only => location.name null');
    assertEqual($coordsOnly->location?->lat, 43.65, 'coords-only => location.lat float');
    assertEqual($coordsOnly->location?->lng, -79.38, 'coords-only => location.lng float');

    $zeroCoords = $mapper->fromRow((object) [
        'id' => 'fire:zero',
        'source' => 'fire',
        'external_id' => 'zero',
        'is_active' => 1,
        'timestamp' => '2026-02-02 12:00:00',
        'title' => 'TEST',
        'location_name' => null,
        'lat' => 0.0,
        'lng' => 0.0,
        'meta' => null,
    ]);
    assertTrue($zeroCoords->location !== null, 'zero-coords => location not null');
    assertEqual($zeroCoords->location?->lat, 0.0, 'zero-coords => location.lat is 0.0');
    assertEqual($zeroCoords->location?->lng, 0.0, 'zero-coords => location.lng is 0.0');

    logInfo('Step 3: Verifying timestamp contract is fail-fast');
    assertThrows('timestamp missing', fn () => $mapper->fromRow((object) [
        'id' => 'fire:ts-missing',
        'source' => 'fire',
        'external_id' => '1',
        'is_active' => 1,
        'timestamp' => null,
        'title' => 'TEST',
    ]), \InvalidArgumentException::class);

    assertThrows('timestamp not parseable', fn () => $mapper->fromRow((object) [
        'id' => 'fire:ts-bad',
        'source' => 'fire',
        'external_id' => '1',
        'is_active' => 1,
        'timestamp' => 'not-a-timestamp',
        'title' => 'TEST',
    ]), \InvalidArgumentException::class);

    logInfo('=== Manual Test Completed Successfully ===');
} catch (\Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    logInfo('=== Test Run Finished ===');

    if ($exitCode === 0) {
        echo "\nResult: PASS\nLogs at: {$logFileRelative}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFileRelative}\n";
    }

    exit($exitCode);
}
