<?php

/**
 * Manual Test: Weather Feature – Phase 1: Database Foundation & Domain
 * Generated: 2026-03-25
 * Purpose: Verify GtaPostalCode (normalize, search, nearestFsa), WeatherCache
 *          (isFresh, findValid), and Weather service contracts are functional
 *          against a live testing database.
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

use App\Models\GtaPostalCode;
use App\Models\WeatherCache;
use App\Services\Weather\Contracts\WeatherProvider;
use App\Services\Weather\DTOs\WeatherData;
use App\Services\Weather\Exceptions\WeatherFetchException;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$testRunId = 'weather_phase_1_verify_'.Carbon::now()->format('Y_m_d_His');
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

function assert_not_null($actual, $label)
{
    if ($actual !== null) {
        logOk("{$label} is not null");
    } else {
        logFail("{$label}: expected non-null, got null");
    }
}

function assert_null($actual, $label)
{
    if ($actual === null) {
        logOk("{$label} is null");
    } else {
        logFail("{$label}: expected null, got ".json_encode($actual));
    }
}

function assert_gt($actual, $min, $label)
{
    if ($actual > $min) {
        logOk("{$label} → {$actual} (> {$min})");
    } else {
        logFail("{$label}: expected > {$min}, got {$actual}");
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

function assert_false($actual, $label)
{
    if ($actual === false) {
        logOk("{$label} is false");
    } else {
        logFail("{$label}: expected false, got ".json_encode($actual));
    }
}

try {
    DB::beginTransaction();

    logInfo('=== Starting Manual Test: Weather Feature Phase 1 ===');

    // -----------------------------------------------------------------------
    // STEP 1: gta_postal_codes reference data
    // -----------------------------------------------------------------------
    logInfo('');
    logInfo('Step 1: gta_postal_codes seed data');

    $totalFsas = GtaPostalCode::count();
    assert_gt($totalFsas, 150, 'Total FSA records');

    $torontoCount = GtaPostalCode::where('municipality', 'Toronto')->count();
    assert_gt($torontoCount, 50, 'Toronto (M-code) FSA records');

    $mississaugaCount = GtaPostalCode::where('municipality', 'Mississauga')->count();
    assert_gt($mississaugaCount, 10, 'Mississauga FSA records');

    $m5v = GtaPostalCode::where('fsa', 'M5V')->first();
    assert_not_null($m5v, 'M5V record exists');
    if ($m5v) {
        assert_eq($m5v->municipality, 'Toronto', 'M5V municipality');
        assert_eq($m5v->neighbourhood, 'Waterfront Communities', 'M5V neighbourhood');
        assert_true(abs($m5v->lat - 43.6406) < 0.01, 'M5V lat approx 43.6406');
        assert_true(abs($m5v->lng - (-79.3961)) < 0.01, 'M5V lng approx -79.3961');
    }

    logInfo("  Seed data totals: {$totalFsas} FSAs, {$torontoCount} Toronto, {$mississaugaCount} Mississauga");

    // -----------------------------------------------------------------------
    // STEP 2: GtaPostalCode::normalize()
    // -----------------------------------------------------------------------
    logInfo('');
    logInfo('Step 2: GtaPostalCode::normalize()');

    assert_eq(GtaPostalCode::normalize('M5V 1A1'), 'M5V', 'normalize full postal code');
    assert_eq(GtaPostalCode::normalize('m5v'), 'M5V', 'normalize lowercase FSA');
    assert_eq(GtaPostalCode::normalize('M5V'), 'M5V', 'normalize already-normalised');
    assert_eq(GtaPostalCode::normalize('m 5 v'), 'M5V', 'normalize with internal spaces');
    assert_eq(GtaPostalCode::normalize('l4c 2B3'), 'L4C', 'normalize L-code postal code');
    assert_eq(GtaPostalCode::normalize('L1H9T4'), 'L1H', 'normalize no-space postal code');

    // -----------------------------------------------------------------------
    // STEP 3: GtaPostalCode::search()
    // -----------------------------------------------------------------------
    logInfo('');
    logInfo('Step 3: GtaPostalCode::search()');

    // Exact FSA search
    $exactResults = GtaPostalCode::search('M5V')->get();
    assert_gt($exactResults->count(), 0, 'search("M5V") returns results');
    assert_eq($exactResults->first()->fsa, 'M5V', 'search("M5V") first result is M5V (ranked first)');

    // FSA normalisation in search
    $normalizedResults = GtaPostalCode::search('m5v 1a1')->get();
    assert_gt($normalizedResults->count(), 0, 'search("m5v 1a1") returns results');
    assert_eq($normalizedResults->first()->fsa, 'M5V', 'search("m5v 1a1") normalises to M5V');

    // Municipality substring search
    $missResults = GtaPostalCode::search('Mississauga')->get();
    assert_gt($missResults->count(), 5, 'search("Mississauga") returns > 5 results');
    $allMiss = $missResults->every(fn ($r) => $r->municipality === 'Mississauga');
    assert_true($allMiss, 'search("Mississauga") all results are Mississauga');

    // Neighbourhood substring search
    $waterfrontResults = GtaPostalCode::search('Waterfront')->get();
    assert_gt($waterfrontResults->count(), 0, 'search("Waterfront") returns results');
    $waterfrontFsas = $waterfrontResults->pluck('fsa')->toArray();
    logInfo("  Waterfront FSAs: ".implode(', ', $waterfrontFsas));

    // Brampton search
    $bramptonResults = GtaPostalCode::search('Brampton')->get();
    assert_gt($bramptonResults->count(), 5, 'search("Brampton") returns > 5 results');

    // No match
    $noResults = GtaPostalCode::search('Zzznotarealplace999')->get();
    assert_eq($noResults->count(), 0, 'search("Zzznotarealplace999") returns 0 results');

    // -----------------------------------------------------------------------
    // STEP 4: GtaPostalCode::nearestFsa()
    // -----------------------------------------------------------------------
    logInfo('');
    logInfo('Step 4: GtaPostalCode::nearestFsa()');

    // Bremner Blvd area (very close to M5V centroid 43.6406, -79.3961)
    $cnTower = GtaPostalCode::nearestFsa(43.640, -79.396);
    assert_not_null($cnTower, 'nearestFsa near M5V centroid returns a result');
    if ($cnTower) {
        assert_eq($cnTower->fsa, 'M5V', 'nearestFsa near M5V centroid → M5V');
        logInfo("  M5V-area nearest: {$cnTower->fsa} ({$cnTower->neighbourhood})");
    }

    // Scarborough Village area → should be M1B (43.8113, -79.1949)
    $scarborough = GtaPostalCode::nearestFsa(43.812, -79.195);
    assert_not_null($scarborough, 'nearestFsa near Scarborough Village returns a result');
    if ($scarborough) {
        assert_eq($scarborough->fsa, 'M1B', 'nearestFsa near Scarborough Village → M1B');
        logInfo("  Scarborough nearest: {$scarborough->fsa} ({$scarborough->neighbourhood})");
    }

    // Mississauga (Port Credit) → should resolve to an L5x code
    $portCredit = GtaPostalCode::nearestFsa(43.5520, -79.5767);
    assert_not_null($portCredit, 'nearestFsa near Port Credit returns a result');
    if ($portCredit) {
        assert_eq($portCredit->fsa, 'L5G', 'nearestFsa near Port Credit → L5G');
        logInfo("  Port Credit nearest: {$portCredit->fsa} ({$portCredit->neighbourhood})");
    }

    // -----------------------------------------------------------------------
    // STEP 5: WeatherCache::isFresh()
    // -----------------------------------------------------------------------
    logInfo('');
    logInfo('Step 5: WeatherCache::isFresh()');

    $freshCache = new WeatherCache([
        'fsa' => 'M5V',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 18.5, 'condition' => 'Partly Cloudy'],
        'fetched_at' => now()->subMinutes(10),
    ]);
    assert_true($freshCache->isFresh(), 'isFresh() true for 10-min-old entry (TTL=30)');
    assert_false($freshCache->isFresh(5), 'isFresh(5) false for 10-min-old entry');
    assert_true($freshCache->isFresh(15), 'isFresh(15) true for 10-min-old entry');

    $staleCache = new WeatherCache([
        'fsa' => 'M5V',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 15.0],
        'fetched_at' => now()->subMinutes(31),
    ]);
    assert_false($staleCache->isFresh(), 'isFresh() false for 31-min-old entry (TTL=30)');

    $boundaryCache = new WeatherCache([
        'fsa' => 'M5V',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 12.0],
        'fetched_at' => now()->subMinutes(WeatherCache::TTL_MINUTES),
    ]);
    assert_false($boundaryCache->isFresh(), 'isFresh() false exactly at TTL boundary');

    // -----------------------------------------------------------------------
    // STEP 6: WeatherCache::findValid() (DB interaction, inside transaction)
    // -----------------------------------------------------------------------
    logInfo('');
    logInfo('Step 6: WeatherCache::findValid()');

    Carbon::setTestNow(now());

    // Create a fresh entry
    WeatherCache::create([
        'fsa' => 'M5V',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 18.5, 'condition' => 'Partly Cloudy'],
        'fetched_at' => now()->subMinutes(10),
    ]);

    $valid = WeatherCache::findValid('M5V', 'environment_canada');
    assert_not_null($valid, 'findValid() returns entry for fresh M5V cache');
    if ($valid) {
        assert_eq($valid->fsa, 'M5V', 'findValid() returns correct FSA');
        assert_eq($valid->provider, 'environment_canada', 'findValid() returns correct provider');
        assert_eq((float) $valid->payload['temperature'], 18.5, 'findValid() payload intact');
    }

    // Expired entry – should not be returned by findValid
    WeatherCache::create([
        'fsa' => 'M1B',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 14.0],
        'fetched_at' => now()->subMinutes(WeatherCache::TTL_MINUTES + 5),
    ]);

    $expired = WeatherCache::findValid('M1B', 'environment_canada');
    assert_null($expired, 'findValid() returns null for expired M1B entry');

    // Different provider – should not match
    $wrongProvider = WeatherCache::findValid('M5V', 'other_provider');
    assert_null($wrongProvider, 'findValid() returns null for different provider');

    // Different FSA – should not match
    $wrongFsa = WeatherCache::findValid('L4C', 'environment_canada');
    assert_null($wrongFsa, 'findValid() returns null for different FSA');

    // Multiple entries – most recent is returned
    WeatherCache::create([
        'fsa' => 'M5V',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 22.0, 'condition' => 'Sunny'],
        'fetched_at' => now()->subMinutes(2),
    ]);

    $latest = WeatherCache::findValid('M5V', 'environment_canada');
    assert_not_null($latest, 'findValid() returns entry when multiple valid entries exist');
    if ($latest) {
        // MySQL JSON may decode 22.0 as integer 22; cast to float for comparison
        assert_eq((float) $latest->payload['temperature'], 22.0, 'findValid() returns most recent entry (22.0°)');
    }

    Carbon::setTestNow();

    // -----------------------------------------------------------------------
    // STEP 7: WeatherData DTO
    // -----------------------------------------------------------------------
    logInfo('');
    logInfo('Step 7: WeatherData DTO');

    $dto = new WeatherData(
        fsa: 'M5V',
        provider: 'environment_canada',
        temperature: 18.5,
        humidity: 65.0,
        windSpeed: '15 km/h',
        windDirection: 'NW',
        condition: 'Partly Cloudy',
        alertLevel: 'yellow',
        alertText: 'Wind Warning in effect',
        fetchedAt: new DateTimeImmutable,
    );

    assert_eq($dto->fsa, 'M5V', 'WeatherData::fsa');
    assert_eq($dto->provider, 'environment_canada', 'WeatherData::provider');
    assert_eq($dto->temperature, 18.5, 'WeatherData::temperature');
    assert_eq($dto->humidity, 65.0, 'WeatherData::humidity');
    assert_eq($dto->windSpeed, '15 km/h', 'WeatherData::windSpeed');
    assert_eq($dto->condition, 'Partly Cloudy', 'WeatherData::condition');
    assert_eq($dto->alertLevel, 'yellow', 'WeatherData::alertLevel');
    assert_eq($dto->alertText, 'Wind Warning in effect', 'WeatherData::alertText');
    assert_true($dto->fetchedAt instanceof DateTimeImmutable, 'WeatherData::fetchedAt is DateTimeImmutable');

    // Nullable fields
    $minimalDto = new WeatherData(
        fsa: 'M1B',
        provider: 'environment_canada',
        temperature: null,
        humidity: null,
        windSpeed: null,
        windDirection: null,
        condition: null,
        alertLevel: null,
        alertText: null,
        fetchedAt: new DateTimeImmutable,
    );
    assert_null($minimalDto->temperature, 'WeatherData nullable temperature');
    assert_null($minimalDto->alertLevel, 'WeatherData nullable alertLevel');

    // -----------------------------------------------------------------------
    // STEP 8: WeatherProvider interface & WeatherFetchException
    // -----------------------------------------------------------------------
    logInfo('');
    logInfo('Step 8: WeatherProvider interface and WeatherFetchException');

    $interfaceExists = interface_exists(WeatherProvider::class);
    assert_true($interfaceExists, 'WeatherProvider interface exists');

    if ($interfaceExists) {
        $reflection = new ReflectionClass(WeatherProvider::class);
        assert_true($reflection->isInterface(), 'WeatherProvider is an interface');
        assert_true($reflection->hasMethod('fetch'), 'WeatherProvider has fetch() method');
        assert_true($reflection->hasMethod('name'), 'WeatherProvider has name() method');

        $fetchMethod = $reflection->getMethod('fetch');
        $fetchParams = $fetchMethod->getParameters();
        assert_eq(count($fetchParams), 1, 'WeatherProvider::fetch() has 1 parameter');
        if (count($fetchParams) === 1) {
            assert_eq($fetchParams[0]->getName(), 'fsa', 'WeatherProvider::fetch() param is $fsa');
        }
    }

    $exception = new WeatherFetchException('M5V', 'environment_canada', 'HTTP 503');
    assert_eq($exception->fsa, 'M5V', 'WeatherFetchException::fsa property');
    assert_eq($exception->provider, 'environment_canada', 'WeatherFetchException::provider property');
    assert_true(
        str_contains($exception->getMessage(), 'M5V'),
        'WeatherFetchException message contains FSA'
    );
    assert_true(
        str_contains($exception->getMessage(), 'environment_canada'),
        'WeatherFetchException message contains provider'
    );
    assert_true(
        str_contains($exception->getMessage(), 'HTTP 503'),
        'WeatherFetchException message contains reason'
    );
    assert_true($exception instanceof RuntimeException, 'WeatherFetchException extends RuntimeException');

    logInfo("  Exception message: \"{$exception->getMessage()}\"");

    // -----------------------------------------------------------------------
    // SUMMARY
    // -----------------------------------------------------------------------
    logInfo('');
    logInfo('=== Manual Test Completed ===');

} catch (\Exception $e) {
    logFail('Unexpected exception: '.$e->getMessage(), [
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    Carbon::setTestNow();
    DB::rollBack();
    logInfo('Transaction rolled back (database preserved).');

    echo "\n";
    echo "Results: {$passed} passed, {$failed} failed\n";
    echo ($failed === 0 ? '✓ All checks passed.' : "✗ {$failed} check(s) failed — review output above.")."\n";
    echo "Full logs at: {$logFileRelative}\n";
}
