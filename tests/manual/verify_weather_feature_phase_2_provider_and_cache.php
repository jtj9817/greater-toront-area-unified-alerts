<?php

/**
 * Manual Test: Weather Feature – Phase 2: Weather Provider & Cache Service
 * Generated: 2026-03-25
 * Purpose: Verify EnvironmentCanadaWeatherProvider (coordinate resolution,
 *          JSON parsing, alert levels, error handling), WeatherFetchService
 *          (provider chain, container binding), and WeatherCacheService
 *          (fast cache hit, DB cache hit, full miss, expired cache bypass).
 *
 * Run via:
 *   ./scripts/run-manual-test.sh tests/manual/verify_weather_feature_phase_2_provider_and_cache.php
 */

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALLOW_ROOT_MANUAL_TESTS') !== '1') {
    fwrite(STDERR, "Error: Do not run manual tests as root.\n");
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
use App\Services\Weather\DTOs\WeatherData;
use App\Services\Weather\Exceptions\WeatherFetchException;
use App\Services\Weather\Providers\EnvironmentCanadaWeatherProvider;
use App\Services\Weather\WeatherCacheService;
use App\Services\Weather\WeatherFetchService;
use Carbon\Carbon;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

$testRunId = 'weather_phase_2_verify_'.Carbon::now()->format('Y_m_d_His');
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
    echo "  \xE2\x9C\x93 {$msg}\n";
}

function logFail($msg, $ctx = [])
{
    global $failed;
    $failed++;
    Log::channel('manual_test')->error("[FAIL] {$msg}", $ctx);
    echo "  \xE2\x9C\x97 {$msg}\n";
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

function assert_gt($actual, $min, $label)
{
    if ($actual > $min) {
        logOk("{$label} → {$actual} (> {$min})");
    } else {
        logFail("{$label}: expected > {$min}, got {$actual}");
    }
}

function assert_contains(string $haystack, string $needle, string $label)
{
    if (str_contains($haystack, $needle)) {
        logOk("{$label} contains '{$needle}'");
    } else {
        logFail("{$label}: expected to contain '{$needle}', got '{$haystack}'");
    }
}

function assert_approx(float $actual, float $expected, string $label, float $epsilon = 0.001)
{
    if (abs($actual - $expected) < $epsilon) {
        logOk("{$label} ≈ {$expected} (got {$actual})");
    } else {
        logFail("{$label}: expected ≈ {$expected}, got {$actual}");
    }
}

function assert_instance_of($actual, string $class, string $label)
{
    if ($actual instanceof $class) {
        logOk("{$label} instanceof ".class_basename($class));
    } else {
        logFail("{$label}: expected instanceof {$class}, got ".(is_object($actual) ? get_class($actual) : gettype($actual)));
    }
}

function assert_throws(callable $callable, string $exceptionClass, string $label): ?\Throwable
{
    try {
        $callable();
        logFail("{$label}: expected {$exceptionClass} but no exception was thrown");

        return null;
    } catch (\Throwable $e) {
        if ($e instanceof $exceptionClass) {
            logOk("{$label}: threw ".class_basename($exceptionClass));

            return $e;
        }
        logFail("{$label}: expected {$exceptionClass}, got ".get_class($e).': '.$e->getMessage());

        return $e;
    }
}

/** Load a JSON fixture file from tests/fixtures/weather/. */
function fixture(string $name): string
{
    return (string) file_get_contents(__DIR__.'/../fixtures/weather/'.$name.'.json');
}

// ============================================================================
// SINGLE HTTP FAKE — registered once, configured per-test via $httpState
// Http::fake() accumulates callbacks, so we register one mutable closure.
// ============================================================================

$httpState = ['type' => 'response', 'body' => '', 'status' => 200, 'exception' => null];
$httpCallCount = 0;
$lastRequestUrl = null;

Http::fake(['*' => function ($request) use (&$httpState, &$httpCallCount, &$lastRequestUrl) {
    $httpCallCount++;
    $lastRequestUrl = $request->url();

    if ($httpState['type'] === 'throw') {
        throw $httpState['exception'];
    }

    return Http::response($httpState['body'] ?? '', $httpState['status'] ?? 200);
}]);

/** Configure the shared Http fake to return a body+status. Resets counters. */
function fakeResponse(string $body, int $status = 200): void
{
    global $httpState, $httpCallCount, $lastRequestUrl;
    $httpState = ['type' => 'response', 'body' => $body, 'status' => $status, 'exception' => null];
    $httpCallCount = 0;
    $lastRequestUrl = null;
}

/** Configure the shared Http fake to throw a connection exception. Resets counters. */
function fakeThrow(\Throwable $exception): void
{
    global $httpState, $httpCallCount, $lastRequestUrl;
    $httpState = ['type' => 'throw', 'body' => '', 'status' => 0, 'exception' => $exception];
    $httpCallCount = 0;
    $lastRequestUrl = null;
}

/** Reset HTTP call counters without changing the response. */
function resetHttpCounters(): void
{
    global $httpCallCount, $lastRequestUrl;
    $httpCallCount = 0;
    $lastRequestUrl = null;
}

// ============================================================================
// BOOTSTRAP
// ============================================================================

try {
    DB::beginTransaction();
    Cache::flush();

    logInfo('=== Starting Manual Test: Weather Feature Phase 2 ===');

    // -------------------------------------------------------------------------
    // SECTION 1: EnvironmentCanadaWeatherProvider – contracts & coordinate resolution
    // -------------------------------------------------------------------------
    logInfo('');
    logInfo('--- Section 1: EnvironmentCanadaWeatherProvider – contracts & coordinates ---');

    $provider = new EnvironmentCanadaWeatherProvider;

    assert_eq($provider->name(), EnvironmentCanadaWeatherProvider::NAME, 'name()');
    assert_eq(EnvironmentCanadaWeatherProvider::NAME, 'environment_canada', 'NAME constant');

    // M5V exists in seeded gta_postal_codes
    $m5v = GtaPostalCode::where('fsa', 'M5V')->first();
    assert_not_null($m5v, 'M5V row exists in gta_postal_codes (seeded by migration)');

    if ($m5v) {
        // Verify URL uses the seeded lat/lng
        fakeResponse(fixture('no-alert'));
        $provider->fetch('M5V');
        assert_contains((string) $lastRequestUrl, '/api/app/v3/en/Location/', 'URL contains EC API path');
        assert_contains((string) $lastRequestUrl, (string) $m5v->lat, 'URL contains M5V latitude from seeded data');
        assert_contains((string) $lastRequestUrl, '?type=city', 'URL contains ?type=city param');
    }

    // Lowercase + full postal code normalizes to M5V
    fakeResponse(fixture('no-alert'));
    $provider->fetch('m5v 1a1');
    assert_contains((string) $lastRequestUrl, (string) ($m5v->lat ?? '43.6'), 'Lowercase "m5v 1a1" resolves via FSA normalization');

    // Unknown FSA falls back to default coords from config
    fakeResponse(fixture('no-alert'));
    $provider->fetch('X9Z');
    $defaultLat = (string) config('weather.environment_canada.default_coords.lat', 43.6532);
    assert_contains((string) $lastRequestUrl, $defaultLat, 'Unknown FSA "X9Z" uses default_coords.lat');

    // -------------------------------------------------------------------------
    // SECTION 2: EnvironmentCanadaWeatherProvider – no-alert JSON parsing
    // -------------------------------------------------------------------------
    logInfo('');
    logInfo('--- Section 2: EnvironmentCanadaWeatherProvider – no-alert JSON parsing ---');

    fakeResponse(fixture('no-alert'));
    $data = $provider->fetch('M5V');

    assert_instance_of($data, WeatherData::class, 'fetch() returns WeatherData');
    assert_eq($data->fsa, 'M5V', 'WeatherData::fsa');
    assert_eq($data->provider, 'environment_canada', 'WeatherData::provider');
    assert_eq($data->temperature, 22.456, 'WeatherData::temperature (metricUnrounded)');
    assert_eq($data->humidity, 65.0, 'WeatherData::humidity');
    assert_eq($data->windSpeed, '20 km/h', 'WeatherData::windSpeed');
    assert_eq($data->windDirection, 'NW', 'WeatherData::windDirection');
    assert_eq($data->condition, 'Mostly Cloudy', 'WeatherData::condition');
    assert_null($data->alertLevel, 'WeatherData::alertLevel (no alert)');
    assert_null($data->alertText, 'WeatherData::alertText (no alert)');
    assert_instance_of($data->fetchedAt, DateTimeImmutable::class, 'WeatherData::fetchedAt');

    // Metric fallback when metricUnrounded absent
    $noMetricUnrounded = json_decode(fixture('no-alert'), true);
    unset($noMetricUnrounded['observation']['temperature']['metricUnrounded']);
    fakeResponse(json_encode($noMetricUnrounded));
    $data = $provider->fetch('M5V');
    assert_eq($data->temperature, 22.0, 'Temperature falls back to metric when metricUnrounded absent');

    // -------------------------------------------------------------------------
    // SECTION 3: EnvironmentCanadaWeatherProvider – alert level parsing
    // -------------------------------------------------------------------------
    logInfo('');
    logInfo('--- Section 3: EnvironmentCanadaWeatherProvider – alert level parsing ---');

    // Yellow
    fakeResponse(fixture('yellow-alert'));
    $data = $provider->fetch('M5V');
    assert_eq($data->alertLevel, 'yellow', 'yellow-alert: alertLevel');
    assert_eq($data->alertText, 'SPECIAL WEATHER STATEMENT IN EFFECT', 'yellow-alert: alertText (bannerText)');

    // alertHeaderText fallback (bannerText removed)
    $yellowNoBanner = json_decode(fixture('yellow-alert'), true);
    unset($yellowNoBanner['alert']['alerts'][0]['bannerText']);
    fakeResponse(json_encode($yellowNoBanner));
    $data = $provider->fetch('M5V');
    assert_eq($data->alertLevel, 'yellow', 'alertHeaderText fallback: alertLevel');
    assert_not_null($data->alertText, 'alertHeaderText fallback: alertText not null');

    // Orange
    fakeResponse(fixture('orange-alert'));
    $data = $provider->fetch('M5V');
    assert_eq($data->alertLevel, 'orange', 'orange-alert: alertLevel');
    assert_not_null($data->alertText, 'orange-alert: alertText present');

    // Red
    fakeResponse(fixture('red-alert'));
    $data = $provider->fetch('M5V');
    assert_eq($data->alertLevel, 'red', 'red-alert: alertLevel');
    assert_not_null($data->alertText, 'red-alert: alertText present');

    // Unknown severity → null
    $unknownSeverity = json_decode(fixture('yellow-alert'), true);
    $unknownSeverity['alert']['mostSevere'] = 'green';
    fakeResponse(json_encode($unknownSeverity));
    $data = $provider->fetch('M5V');
    assert_null($data->alertLevel, 'Unknown severity "green": alertLevel null');
    assert_null($data->alertText, 'Unknown severity "green": alertText null');

    // Malformed payload → graceful nulls, no exception
    fakeResponse(fixture('malformed-payload'));
    $data = $provider->fetch('M5V');
    assert_null($data->temperature, 'malformed-payload: temperature null');
    assert_null($data->humidity, 'malformed-payload: humidity null');
    assert_null($data->condition, 'malformed-payload: condition null');
    assert_null($data->alertLevel, 'malformed-payload: alertLevel null');

    // List-root payload: [{ ... }] → uses first element
    $listRoot = [json_decode(fixture('no-alert'), true)];
    fakeResponse(json_encode($listRoot));
    $data = $provider->fetch('M5V');
    assert_eq($data->temperature, 22.456, 'List-root payload: temperature parsed from first element');

    // -------------------------------------------------------------------------
    // SECTION 4: EnvironmentCanadaWeatherProvider – error handling
    // -------------------------------------------------------------------------
    logInfo('');
    logInfo('--- Section 4: EnvironmentCanadaWeatherProvider – error handling ---');

    // HTTP 500
    fakeResponse('Internal Server Error', 500);
    $ex = assert_throws(fn () => $provider->fetch('M5V'), WeatherFetchException::class, 'HTTP 500 throws WeatherFetchException');
    if ($ex instanceof WeatherFetchException) {
        assert_eq($ex->fsa, 'M5V', '  → exception::fsa');
        assert_eq($ex->provider, 'environment_canada', '  → exception::provider');
    }

    // HTTP 404
    fakeResponse('Not Found', 404);
    assert_throws(fn () => $provider->fetch('M5V'), WeatherFetchException::class, 'HTTP 404 throws WeatherFetchException');

    // Invalid JSON
    fakeResponse('not valid json{{{{');
    assert_throws(fn () => $provider->fetch('M5V'), WeatherFetchException::class, 'Invalid JSON throws WeatherFetchException');

    // API error string
    fakeResponse(fixture('api-error'));
    assert_throws(fn () => $provider->fetch('M5V'), WeatherFetchException::class, 'API error string throws WeatherFetchException');

    // Empty body
    fakeResponse('');
    assert_throws(fn () => $provider->fetch('M5V'), WeatherFetchException::class, 'Empty response body throws WeatherFetchException');

    // Empty JSON object
    fakeResponse('{}');
    assert_throws(fn () => $provider->fetch('M5V'), WeatherFetchException::class, 'Empty JSON object {} throws WeatherFetchException');

    // Connection failure
    fakeThrow(new ConnectionException('Connection refused'));
    assert_throws(fn () => $provider->fetch('M5V'), WeatherFetchException::class, 'Connection failure throws WeatherFetchException');

    // -------------------------------------------------------------------------
    // SECTION 5: WeatherFetchService – container binding & provider chain
    // -------------------------------------------------------------------------
    logInfo('');
    logInfo('--- Section 5: WeatherFetchService – container binding & provider chain ---');

    $fetchService = app(WeatherFetchService::class);
    assert_instance_of($fetchService, WeatherFetchService::class, 'Container resolves WeatherFetchService');

    $providers = $fetchService->getProviders();
    assert_gt(count($providers), 0, 'WeatherFetchService::getProviders() returns ≥1 provider');
    assert_eq($providers[0]->name(), 'environment_canada', 'First provider name is environment_canada');

    // Singleton check
    assert_true($fetchService === app(WeatherFetchService::class), 'WeatherFetchService is a singleton');

    // Successful fetch via service
    fakeResponse(fixture('no-alert'));
    try {
        $data = $fetchService->fetch('M5V');
        assert_instance_of($data, WeatherData::class, 'WeatherFetchService::fetch() returns WeatherData');
        assert_eq($data->provider, 'environment_canada', 'WeatherData::provider from service');
    } catch (\Throwable $e) {
        logFail('WeatherFetchService::fetch() threw unexpectedly: '.$e->getMessage());
    }

    // All providers fail → throws WeatherFetchException
    fakeResponse('Server Error', 500);
    $ex = assert_throws(fn () => $fetchService->fetch('M5V'), WeatherFetchException::class, 'WeatherFetchService throws when all providers fail');
    if ($ex instanceof WeatherFetchException) {
        assert_eq($ex->fsa, 'M5V', '  → aggregate exception::fsa');
    }

    // -------------------------------------------------------------------------
    // SECTION 6: WeatherCacheService – full miss → upstream + stores in both caches
    // -------------------------------------------------------------------------
    logInfo('');
    logInfo('--- Section 6: WeatherCacheService – full miss path ---');

    Cache::flush();
    WeatherCache::where('fsa', 'M5V')->delete();

    $cacheService = app(WeatherCacheService::class);
    assert_instance_of($cacheService, WeatherCacheService::class, 'Container resolves WeatherCacheService');

    fakeResponse(fixture('no-alert'));
    try {
        $data = $cacheService->get('M5V');
        assert_instance_of($data, WeatherData::class, 'Full miss: get() returns WeatherData');
        assert_eq($data->fsa, 'M5V', 'Full miss: WeatherData::fsa');
        assert_eq($data->temperature, 22.456, 'Full miss: WeatherData::temperature from upstream');
        assert_eq($httpCallCount, 1, 'Full miss: upstream called exactly once');
    } catch (\Throwable $e) {
        logFail('Full miss: get() threw unexpectedly: '.$e->getMessage());
    }

    // DB cache row created
    $dbRow = WeatherCache::where('fsa', 'M5V')->where('provider', 'environment_canada')->first();
    assert_not_null($dbRow, 'Full miss: WeatherCache DB row created');
    if ($dbRow) {
        assert_eq($dbRow->fsa, 'M5V', 'DB row: fsa');
        assert_eq($dbRow->provider, 'environment_canada', 'DB row: provider');
        assert_not_null($dbRow->payload['temperature'] ?? null, 'DB row: payload.temperature present');
        assert_not_null($dbRow->payload['fetched_at'] ?? null, 'DB row: payload.fetched_at present');
        assert_true($dbRow->isFresh(), 'DB row: isFresh() true immediately after creation');
    }

    // Fast cache populated
    $fastCached = Cache::get('weather.current.M5V');
    assert_instance_of($fastCached, WeatherData::class, 'Full miss: fast cache populated');

    // Second get() hits fast cache — upstream not called again
    resetHttpCounters();
    try {
        $cacheService->get('M5V');
        assert_eq($httpCallCount, 0, 'Second get() served from fast cache (upstream not called)');
    } catch (\Throwable $e) {
        logFail('Second get() threw unexpectedly: '.$e->getMessage());
    }

    // -------------------------------------------------------------------------
    // SECTION 7: WeatherCacheService – DB cache hit (fast cache cold)
    // -------------------------------------------------------------------------
    logInfo('');
    logInfo('--- Section 7: WeatherCacheService – DB cache hit ---');

    Cache::flush();
    WeatherCache::where('fsa', 'L5A')->delete();

    // Seed a fresh DB cache row
    WeatherCache::create([
        'fsa' => 'L5A',
        'provider' => 'environment_canada',
        'payload' => [
            'temperature' => 18.5,
            'humidity' => 70.0,
            'wind_speed' => '10 km/h',
            'wind_direction' => 'SW',
            'condition' => 'Sunny',
            'alert_level' => null,
            'alert_text' => null,
            'fetched_at' => now()->subMinutes(5)->toAtomString(),
        ],
        'fetched_at' => now()->subMinutes(5),
    ]);

    // Configure fake with valid response, but track if it's called
    fakeResponse(fixture('no-alert'));
    try {
        $data = $cacheService->get('L5A');
        assert_eq($httpCallCount, 0, 'DB cache hit: upstream not called');
        assert_instance_of($data, WeatherData::class, 'DB cache hit: returns WeatherData');
        assert_eq($data->fsa, 'L5A', 'DB cache hit: fsa');
        assert_eq($data->temperature, 18.5, 'DB cache hit: temperature from DB payload');
        assert_eq($data->condition, 'Sunny', 'DB cache hit: condition from DB payload');
        assert_null($data->alertLevel, 'DB cache hit: alertLevel null from DB payload');
    } catch (\Throwable $e) {
        logFail('DB cache hit: get() threw unexpectedly: '.$e->getMessage());
    }

    // Fast cache repopulated after DB hit
    $fastCached = Cache::get('weather.current.L5A');
    assert_instance_of($fastCached, WeatherData::class, 'DB cache hit: fast cache repopulated');

    // -------------------------------------------------------------------------
    // SECTION 8: WeatherCacheService – fast cache hit
    // -------------------------------------------------------------------------
    logInfo('');
    logInfo('--- Section 8: WeatherCacheService – fast cache hit ---');

    Cache::flush();

    $stubbedData = new WeatherData(
        fsa: 'M9W',
        provider: 'environment_canada',
        temperature: 15.0,
        humidity: 80.0,
        windSpeed: '5 km/h',
        windDirection: 'N',
        condition: 'Overcast',
        alertLevel: null,
        alertText: null,
        fetchedAt: new DateTimeImmutable,
    );
    Cache::put('weather.current.M9W', $stubbedData, now()->addMinutes(30));

    resetHttpCounters();
    try {
        $data = $cacheService->get('M9W');
        assert_true($data === $stubbedData, 'Fast cache hit: returns identical object (===)');
        assert_eq($httpCallCount, 0, 'Fast cache hit: upstream not called');
        assert_eq($data->fsa, 'M9W', 'Fast cache hit: fsa');
        assert_eq($data->temperature, 15.0, 'Fast cache hit: temperature');
    } catch (\Throwable $e) {
        logFail('Fast cache hit: get() threw unexpectedly: '.$e->getMessage());
    }

    // -------------------------------------------------------------------------
    // SECTION 9: WeatherCacheService – expired DB cache bypassed
    // -------------------------------------------------------------------------
    logInfo('');
    logInfo('--- Section 9: WeatherCacheService – expired DB cache bypassed ---');

    Cache::flush();
    WeatherCache::where('fsa', 'M1B')->delete();

    // Create an expired row (beyond TTL)
    WeatherCache::create([
        'fsa' => 'M1B',
        'provider' => 'environment_canada',
        'payload' => [
            'temperature' => 5.0,
            'humidity' => 50.0,
            'wind_speed' => '30 km/h',
            'wind_direction' => 'N',
            'condition' => 'Clear',
            'alert_level' => null,
            'alert_text' => null,
            'fetched_at' => now()->subMinutes(WeatherCache::TTL_MINUTES + 10)->toAtomString(),
        ],
        'fetched_at' => now()->subMinutes(WeatherCache::TTL_MINUTES + 10),
    ]);

    $expiredRow = WeatherCache::where('fsa', 'M1B')->first();
    assert_not_null($expiredRow, 'Expired row exists in DB');
    if ($expiredRow) {
        assert_false($expiredRow->isFresh(), 'Expired row: isFresh() false');
        assert_null(WeatherCache::findValid('M1B', 'environment_canada'), 'Expired row: findValid() returns null');
    }

    // get() must bypass expired row and call upstream
    fakeResponse(fixture('yellow-alert'));
    try {
        $data = $cacheService->get('M1B');
        assert_eq($httpCallCount, 1, 'Expired cache bypass: upstream called once');
        assert_instance_of($data, WeatherData::class, 'Expired cache bypass: returns WeatherData');
        assert_approx($data->temperature, 15.789, 'Expired cache bypass: temperature from upstream (not stale 5.0)');
        assert_eq($data->alertLevel, 'yellow', 'Expired cache bypass: alertLevel from upstream');
    } catch (\Throwable $e) {
        logFail('Expired cache bypass: get() threw unexpectedly: '.$e->getMessage());
    }

    // Fresh DB row created after bypass
    $freshRow = WeatherCache::where('fsa', 'M1B')->orderByDesc('fetched_at')->first();
    assert_not_null($freshRow, 'Expired cache bypass: new WeatherCache row created');
    if ($freshRow) {
        assert_true($freshRow->isFresh(), 'New row after bypass: isFresh() true');
    }

    // -------------------------------------------------------------------------
    // SECTION 10: WeatherCache – TTL boundary semantics
    // -------------------------------------------------------------------------
    logInfo('');
    logInfo('--- Section 10: WeatherCache – TTL boundary semantics ---');

    // Inside TTL: row is fresh and findValid returns it
    $freshEntry = WeatherCache::create([
        'fsa' => 'M3H',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 20.0, 'fetched_at' => now()->toAtomString()],
        'fetched_at' => now()->subMinutes(WeatherCache::TTL_MINUTES - 1),
    ]);
    assert_true($freshEntry->isFresh(), 'isFresh() true when 1 min inside TTL');
    assert_not_null(WeatherCache::findValid('M3H', 'environment_canada'), 'findValid() returns row inside TTL');

    // At exact boundary: strict > means expired
    Carbon::setTestNow(now());
    $boundaryEntry = WeatherCache::create([
        'fsa' => 'M3J',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 20.0, 'fetched_at' => now()->toAtomString()],
        'fetched_at' => now()->subMinutes(WeatherCache::TTL_MINUTES),
    ]);
    assert_false($boundaryEntry->isFresh(), 'isFresh() false at exact TTL boundary');
    assert_null(WeatherCache::findValid('M3J', 'environment_canada'), 'findValid() null at exact TTL boundary');
    Carbon::setTestNow();

    // -------------------------------------------------------------------------
    // SECTION 11: WeatherCacheService – DB payload roundtrip integrity
    // -------------------------------------------------------------------------
    logInfo('');
    logInfo('--- Section 11: WeatherCacheService – DB payload roundtrip ---');

    Cache::flush();
    WeatherCache::where('fsa', 'M6G')->delete();

    // Upstream yellow-alert populates DB cache
    fakeResponse(fixture('yellow-alert'));
    try {
        $cacheService->get('M6G');
    } catch (\Throwable $e) {
        logFail('Roundtrip: initial get() threw: '.$e->getMessage());
    }

    $row = WeatherCache::where('fsa', 'M6G')->where('provider', 'environment_canada')->first();
    assert_not_null($row, 'Roundtrip: DB row created for M6G');

    if ($row) {
        $p = $row->payload;
        assert_true(array_key_exists('temperature', $p), 'Roundtrip payload: temperature key');
        assert_true(array_key_exists('humidity', $p), 'Roundtrip payload: humidity key');
        assert_true(array_key_exists('wind_speed', $p), 'Roundtrip payload: wind_speed key');
        assert_true(array_key_exists('wind_direction', $p), 'Roundtrip payload: wind_direction key');
        assert_true(array_key_exists('condition', $p), 'Roundtrip payload: condition key');
        assert_true(array_key_exists('alert_level', $p), 'Roundtrip payload: alert_level key');
        assert_true(array_key_exists('alert_text', $p), 'Roundtrip payload: alert_text key');
        assert_true(array_key_exists('fetched_at', $p), 'Roundtrip payload: fetched_at key');

        // Flush fast cache and re-fetch from DB only
        Cache::flush();
        fakeResponse(fixture('no-alert')); // Would return different data if upstream called
        resetHttpCounters();

        try {
            $hydrated = $cacheService->get('M6G');
            assert_eq($httpCallCount, 0, 'Roundtrip: second get() hits DB (no upstream call)');
            assert_eq($hydrated->alertLevel, 'yellow', 'Roundtrip: hydrated alertLevel from DB');
            assert_eq($hydrated->alertText, 'SPECIAL WEATHER STATEMENT IN EFFECT', 'Roundtrip: hydrated alertText from DB');
            assert_approx($hydrated->temperature ?? 0.0, 15.789, 'Roundtrip: hydrated temperature from DB');
        } catch (\Throwable $e) {
            logFail('Roundtrip: second get() threw: '.$e->getMessage());
        }
    }

} catch (\Throwable $e) {
    logFail('Unexpected fatal exception: '.$e->getMessage(), [
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    // -------------------------------------------------------------------------
    // CLEANUP
    // -------------------------------------------------------------------------
    DB::rollBack();
    Cache::flush();

    $logRelative = 'storage/logs/manual_tests/'.basename($logFile);
    logInfo('');
    logInfo('=== Phase 2 Manual Verification Complete ===');
    logInfo("Passed: {$passed}  Failed: {$failed}");
    logInfo("Full log: {$logRelative}");

    echo "\n";
    echo "=============================================\n";
    echo " Phase 2 Verification Summary\n";
    echo "=============================================\n";
    echo " Passed : {$passed}\n";
    echo " Failed : {$failed}\n";
    echo "=============================================\n";
    echo " Full log: {$logRelative}\n";
    echo "\n";

    if ($failed > 0) {
        exit(1);
    }
}
