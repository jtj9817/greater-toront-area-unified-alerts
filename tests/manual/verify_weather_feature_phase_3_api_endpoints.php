<?php

/**
 * Manual Test: Weather Feature – Phase 3: Backend API Endpoints
 * Generated: 2026-03-25
 * Purpose: Verify PostalCodeSearchController (GET /api/postal-codes),
 *          PostalCodeResolveCoordsController (POST /api/postal-codes/resolve-coords),
 *          and WeatherController + WeatherResource (GET /api/weather).
 *          Controllers are dispatched in-process via Request::create(); a mock
 *          WeatherCacheService is bound to the container for deterministic weather
 *          responses without hitting the live Environment Canada API.
 *
 * Run via:
 *   ./scripts/run-manual-test.sh tests/manual/verify_weather_feature_phase_3_api_endpoints.php
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

use App\Http\Controllers\Weather\PostalCodeResolveCoordsController;
use App\Http\Controllers\Weather\PostalCodeSearchController;
use App\Http\Controllers\Weather\WeatherController;
use App\Models\GtaPostalCode;
use App\Services\Weather\DTOs\WeatherData;
use App\Services\Weather\Exceptions\WeatherFetchException;
use App\Services\Weather\Providers\EnvironmentCanadaWeatherProvider;
use App\Services\Weather\WeatherCacheService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

// === Logging setup ===
$testRunId = 'weather_phase_3_verify_'.Carbon::now()->format('Y_m_d_His');
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

// =========================================================
// Helper functions
// =========================================================

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
        logOk("{$label} is not null → ".json_encode($actual));
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

function assert_contains($haystack, $needle, $label)
{
    if (in_array($needle, (array) $haystack, true)) {
        logOk("{$label} contains ".json_encode($needle));
    } else {
        logFail("{$label}: ".json_encode($needle).' not found in '.json_encode($haystack));
    }
}

function assert_has_key($array, $key, $label)
{
    if (array_key_exists($key, (array) $array)) {
        logOk("{$label} has key '{$key}'");
    } else {
        logFail("{$label}: missing key '{$key}' in ".json_encode(array_keys((array) $array)));
    }
}

/** Assert that an array key is present AND its value is null (distinguishes null from missing). */
function assert_null_key($array, $key, $label)
{
    $arr = (array) $array;

    if (! array_key_exists($key, $arr)) {
        logFail("{$label}: key '{$key}' missing from response (expected null)");
    } elseif ($arr[$key] !== null) {
        logFail("{$label}: expected null for key '{$key}', got ".json_encode($arr[$key]));
    } else {
        logOk("{$label} key '{$key}' is null (key present)");
    }
}

function assert_key_absent($array, $key, $label)
{
    if (! array_key_exists($key, (array) $array)) {
        logOk("{$label} does not have key '{$key}' (snake_case enforced)");
    } else {
        logFail("{$label}: unexpected camelCase key '{$key}' present");
    }
}

/**
 * Dispatch PostalCodeSearchController in-process with the given GET params.
 *
 * @return array{status:int, body:array}
 */
function dispatchSearch(array $params): array
{
    $request = Request::create('/api/postal-codes', 'GET', $params);
    $request->headers->set('Accept', 'application/json');

    try {
        $response = app(PostalCodeSearchController::class)($request);

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true) ?? [],
        ];
    } catch (ValidationException $e) {
        return ['status' => 422, 'body' => ['message' => $e->getMessage(), 'errors' => $e->errors()]];
    }
}

/**
 * Dispatch PostalCodeResolveCoordsController in-process with the given POST params.
 *
 * @return array{status:int, body:array}
 */
function dispatchResolve(array $params): array
{
    $request = Request::create('/api/postal-codes/resolve-coords', 'POST', $params);
    $request->headers->set('Accept', 'application/json');

    try {
        $response = app(PostalCodeResolveCoordsController::class)($request);

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true) ?? [],
        ];
    } catch (ValidationException $e) {
        return ['status' => 422, 'body' => ['message' => $e->getMessage(), 'errors' => $e->errors()]];
    }
}

/**
 * Dispatch WeatherController in-process with the given GET params.
 * Resolves WeatherCacheService from the container, so bind a mock before calling
 * if you want deterministic results.
 *
 * @return array{status:int, body:array}
 */
function dispatchWeather(array $params): array
{
    $request = Request::create('/api/weather', 'GET', $params);
    $request->headers->set('Accept', 'application/json');

    try {
        $weatherCache = app(WeatherCacheService::class);
        $response = app(WeatherController::class)($request, $weatherCache);

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode($response->getContent(), true) ?? [],
        ];
    } catch (ValidationException $e) {
        return ['status' => 422, 'body' => ['message' => $e->getMessage(), 'errors' => $e->errors()]];
    }
}

/** Build a deterministic WeatherData stub. */
function makeWeatherStub(
    string $fsa = 'M5V',
    float $temperature = 15.5,
    ?string $alertLevel = null,
    ?string $alertText = null,
): WeatherData {
    return new WeatherData(
        fsa: $fsa,
        provider: EnvironmentCanadaWeatherProvider::NAME,
        temperature: $temperature,
        humidity: 65.5,   // non-integer float survives JSON round-trip as float
        windSpeed: '20 km/h',
        windDirection: 'NW',
        condition: 'Mostly Cloudy',
        alertLevel: $alertLevel,
        alertText: $alertText,
        fetchedAt: new DateTimeImmutable('2026-03-25T12:00:00+00:00'),
    );
}

// =========================================================
// Preflight: confirm gta_postal_codes table is seeded
// =========================================================
echo "\n=== Phase 3 Manual Verification: Backend API Endpoints ===\n";
echo "Run ID: {$testRunId}\n\n";

logInfo('Preflight: confirming gta_postal_codes seed data');
$seedCount = GtaPostalCode::count();

if ($seedCount < 150) {
    exit("Preflight FAILED: gta_postal_codes has only {$seedCount} rows (expected ≥150). Run migrations first.\n");
}

logInfo("gta_postal_codes row count: {$seedCount} — OK\n");

// =========================================================
// Group 1: GET /api/postal-codes (PostalCodeSearchController)
// =========================================================
echo "Group 1: GET /api/postal-codes (PostalCodeSearchController)\n";
echo str_repeat('-', 60)."\n";

// --- Validation: missing q ---
logInfo('1.1 Missing q parameter');
$r = dispatchSearch([]);
assert_eq($r['status'], 422, 'missing q → 422');
assert_has_key($r['body']['errors'] ?? [], 'q', 'missing q errors');

// --- Validation: q too long ---
logInfo('1.2 q exceeds max length (121 chars)');
$r = dispatchSearch(['q' => str_repeat('x', 121)]);
assert_eq($r['status'], 422, 'q=121chars → 422');
assert_has_key($r['body']['errors'] ?? [], 'q', 'q too long errors');

// --- Short query returns empty data (no validation error) ---
logInfo('1.3 Single-character query returns 200 + empty data');
$r = dispatchSearch(['q' => 'M']);
assert_eq($r['status'], 200, 'q=M → 200');
assert_eq($r['body']['data'] ?? 'missing', [], 'q=M → data=[]');

// --- FSA exact match ---
logInfo('1.4 FSA exact match: q=M5V');
$r = dispatchSearch(['q' => 'M5V']);
assert_eq($r['status'], 200, 'q=M5V → 200');
$fsas = array_column($r['body']['data'] ?? [], 'fsa');
assert_contains($fsas, 'M5V', 'FSA M5V in results');

// --- Exact match ranked first ---
logInfo('1.5 Exact FSA match ranked first');
assert_eq($r['body']['data'][0]['fsa'] ?? null, 'M5V', 'M5V ranked first');

// --- Result row structure ---
logInfo('1.6 Result row structure');
$row = $r['body']['data'][0] ?? [];
foreach (['fsa', 'municipality', 'neighbourhood', 'lat', 'lng'] as $field) {
    assert_has_key($row, $field, 'result row');
}

// --- Municipality partial match ---
logInfo('1.7 Municipality partial match: q=Mississauga');
$r = dispatchSearch(['q' => 'Mississauga']);
assert_eq($r['status'], 200, 'q=Mississauga → 200');
$municipalities = array_unique(array_column($r['body']['data'] ?? [], 'municipality'));
assert_contains($municipalities, 'Mississauga', 'Mississauga municipality in results');

// --- Neighbourhood partial match ---
logInfo('1.8 Neighbourhood partial match: q=Waterfront');
$r = dispatchSearch(['q' => 'Waterfront']);
assert_eq($r['status'], 200, 'q=Waterfront → 200');
assert_true(count($r['body']['data'] ?? []) > 0, 'Waterfront returns at least 1 result');

// --- No match ---
logInfo('1.9 No-match query returns empty data');
$r = dispatchSearch(['q' => 'NoSuchPlaceXYZ99']);
assert_eq($r['status'], 200, 'no-match → 200');
assert_eq($r['body']['data'] ?? 'missing', [], 'no-match → data=[]');

// --- Optional limit parameter ---
logInfo('1.10 limit=3 caps results to 3');
$r = dispatchSearch(['q' => 'Toronto', 'limit' => 3]);
assert_eq($r['status'], 200, 'limit=3 → 200');
assert_eq(count($r['body']['data'] ?? []), 3, 'limit=3 → exactly 3 results');

// --- Limit out of range ---
logInfo('1.11 limit=51 returns 422');
$r = dispatchSearch(['q' => 'Toronto', 'limit' => 51]);
assert_eq($r['status'], 422, 'limit=51 → 422');
assert_has_key($r['body']['errors'] ?? [], 'limit', 'limit=51 errors');

// --- Limit lower bound ---
logInfo('1.12 limit=1 returns exactly 1 result');
$r = dispatchSearch(['q' => 'Toronto', 'limit' => 1]);
assert_eq($r['status'], 200, 'limit=1 → 200');
assert_eq(count($r['body']['data'] ?? []), 1, 'limit=1 → exactly 1 result');

echo "\n";

// =========================================================
// Group 2: POST /api/postal-codes/resolve-coords
// =========================================================
echo "Group 2: POST /api/postal-codes/resolve-coords (PostalCodeResolveCoordsController)\n";
echo str_repeat('-', 60)."\n";

// --- Missing lat ---
logInfo('2.1 Missing lat');
$r = dispatchResolve(['lng' => -79.3961]);
assert_eq($r['status'], 422, 'missing lat → 422');
assert_has_key($r['body']['errors'] ?? [], 'lat', 'missing lat errors');

// --- Missing lng ---
logInfo('2.2 Missing lng');
$r = dispatchResolve(['lat' => 43.6406]);
assert_eq($r['status'], 422, 'missing lng → 422');
assert_has_key($r['body']['errors'] ?? [], 'lng', 'missing lng errors');

// --- Non-numeric lat ---
logInfo('2.3 Non-numeric lat');
$r = dispatchResolve(['lat' => 'not-a-number', 'lng' => -79.3961]);
assert_eq($r['status'], 422, 'non-numeric lat → 422');
assert_has_key($r['body']['errors'] ?? [], 'lat', 'non-numeric lat errors');

// --- Non-numeric lng ---
logInfo('2.4 Non-numeric lng');
$r = dispatchResolve(['lat' => 43.6406, 'lng' => 'abc']);
assert_eq($r['status'], 422, 'non-numeric lng → 422');
assert_has_key($r['body']['errors'] ?? [], 'lng', 'non-numeric lng errors');

// --- Lat too high (London, UK) ---
logInfo('2.5 Lat=51.5 (London, UK) — out of GTA bounding box');
$r = dispatchResolve(['lat' => 51.5, 'lng' => -0.1]);
assert_eq($r['status'], 422, 'lat=51.5 → 422');

// --- Lat too low (New York) ---
logInfo('2.6 Lat=40.7 (New York) — below GTA south bound');
$r = dispatchResolve(['lat' => 40.7, 'lng' => -74.0]);
assert_eq($r['status'], 422, 'lat=40.7 → 422');

// --- Lng too far east ---
logInfo('2.7 Lng=-78.0 — east of GTA bounding box');
$r = dispatchResolve(['lat' => 43.7, 'lng' => -78.0]);
assert_eq($r['status'], 422, 'lng=-78.0 → 422');

// --- Lng too far west ---
logInfo('2.8 Lng=-81.0 — west of GTA bounding box');
$r = dispatchResolve(['lat' => 43.7, 'lng' => -81.0]);
assert_eq($r['status'], 422, 'lng=-81.0 → 422');

// --- Valid GTA coordinates (exact M5V centroid) ---
logInfo('2.9 Exact M5V centroid coordinates (43.6406, -79.3961)');
$r = dispatchResolve(['lat' => 43.6406, 'lng' => -79.3961]);
assert_eq($r['status'], 200, 'M5V centroid coords → 200');
assert_eq($r['body']['data']['fsa'] ?? null, 'M5V', 'M5V centroid resolves to M5V');

// --- Resolve response structure ---
logInfo('2.10 Resolve response structure');
$data = $r['body']['data'] ?? [];
foreach (['fsa', 'municipality', 'neighbourhood', 'lat', 'lng'] as $field) {
    assert_has_key($data, $field, 'resolve response data');
}

// --- Data types in resolve response ---
logInfo('2.11 Resolve data types');
assert_true(is_string($data['fsa'] ?? null), 'resolve data.fsa is string');
assert_true(is_string($data['municipality'] ?? null), 'resolve data.municipality is string');
assert_true(is_float($data['lat'] ?? null) || is_int($data['lat'] ?? null), 'resolve data.lat is numeric');
assert_true(is_float($data['lng'] ?? null) || is_int($data['lng'] ?? null), 'resolve data.lng is numeric');

// --- M4K centroid coordinates (Danforth) resolve to M4K ---
logInfo('2.12 M4K centroid coordinates (43.6797, -79.3535) resolve to M4K');
$r = dispatchResolve(['lat' => 43.6797, 'lng' => -79.3535]);
assert_eq($r['status'], 200, 'M4K centroid coords → 200');
assert_eq($r['body']['data']['fsa'] ?? null, 'M4K', 'M4K centroid resolves to M4K');

echo "\n";

// =========================================================
// Group 3: GET /api/weather (WeatherController + WeatherResource)
// =========================================================
echo "Group 3: GET /api/weather (WeatherController + WeatherResource)\n";
echo str_repeat('-', 60)."\n";

// --- Missing fsa ---
logInfo('3.1 Missing fsa parameter');
$r = dispatchWeather([]);
assert_eq($r['status'], 422, 'missing fsa → 422');
assert_has_key($r['body']['errors'] ?? [], 'fsa', 'missing fsa errors');

// --- Invalid FSA format: ZZZ (Z is not a digit in position 2) ---
logInfo('3.2 ZZZ fails postal code regex (position 2 must be digit)');
$r = dispatchWeather(['fsa' => 'ZZZ']);
assert_eq($r['status'], 422, 'fsa=ZZZ → 422');
assert_has_key($r['body']['errors'] ?? [], 'fsa', 'ZZZ errors');

// --- Malformed FSA: M5VXYZ (trailing garbage) ---
logInfo('3.3 M5VXYZ fails postal code regex (trailing garbage)');
$r = dispatchWeather(['fsa' => 'M5VXYZ']);
assert_eq($r['status'], 422, 'fsa=M5VXYZ → 422');
assert_has_key($r['body']['errors'] ?? [], 'fsa', 'M5VXYZ errors');

// --- FSA not in GTA table: A9A (valid format, not a GTA FSA) ---
logInfo('3.4 A9A passes regex but is not in gta_postal_codes → 422');
$r = dispatchWeather(['fsa' => 'A9A']);
assert_eq($r['status'], 422, 'fsa=A9A (not in GTA) → 422');
assert_has_key($r['body']['errors'] ?? [], 'fsa', 'A9A (not in GTA) fsa errors');

// --- Valid M5V with mock service → 200 ---
logInfo('3.5 Valid FSA M5V with mock WeatherCacheService → 200');
app()->instance(WeatherCacheService::class, new class extends WeatherCacheService
{
    public function __construct() {}

    public function get(string $fsa): WeatherData
    {
        return makeWeatherStub($fsa);
    }
});

$r = dispatchWeather(['fsa' => 'M5V']);
assert_eq($r['status'], 200, 'fsa=M5V with mock → 200');

// --- WeatherResource: all 10 keys present ---
logInfo('3.6 WeatherResource shape — all 10 keys present');
$weatherData = $r['body']['data'] ?? [];
$requiredKeys = ['fsa', 'provider', 'temperature', 'humidity', 'wind_speed', 'wind_direction', 'condition', 'alert_level', 'alert_text', 'fetched_at'];
foreach ($requiredKeys as $key) {
    assert_has_key($weatherData, $key, 'WeatherResource');
}

// --- WeatherResource: snake_case enforced (no camelCase leakage) ---
logInfo('3.7 WeatherResource uses snake_case (no camelCase keys)');
assert_key_absent($weatherData, 'windSpeed', 'WeatherResource');
assert_key_absent($weatherData, 'windDirection', 'WeatherResource');
assert_key_absent($weatherData, 'alertLevel', 'WeatherResource');
assert_key_absent($weatherData, 'alertText', 'WeatherResource');
assert_key_absent($weatherData, 'fetchedAt', 'WeatherResource');

// --- WeatherResource: field values ---
logInfo('3.8 WeatherResource field values');
assert_eq($weatherData['fsa'] ?? null, 'M5V', 'data.fsa');
assert_eq($weatherData['provider'] ?? null, EnvironmentCanadaWeatherProvider::NAME, 'data.provider');
assert_eq($weatherData['temperature'] ?? null, 15.5, 'data.temperature');
assert_eq($weatherData['humidity'] ?? null, 65.5, 'data.humidity');
assert_eq($weatherData['wind_speed'] ?? null, '20 km/h', 'data.wind_speed');
assert_eq($weatherData['wind_direction'] ?? null, 'NW', 'data.wind_direction');
assert_eq($weatherData['condition'] ?? null, 'Mostly Cloudy', 'data.condition');
assert_null_key($weatherData, 'alert_level', 'data.alert_level (no alert)');
assert_null_key($weatherData, 'alert_text', 'data.alert_text (no alert)');

// --- WeatherResource: fetched_at is ISO 8601 ---
logInfo('3.9 fetched_at is a valid ISO 8601 string');
$fetchedAt = $weatherData['fetched_at'] ?? null;
assert_not_null($fetchedAt, 'data.fetched_at');
assert_true(is_string($fetchedAt), 'data.fetched_at is string');
assert_true($fetchedAt !== '' && strtotime($fetchedAt) !== false, "data.fetched_at is ISO 8601 parseable → {$fetchedAt}");

// --- FSA normalization: lowercase 3-char input ---
logInfo('3.10 FSA normalization: lowercase "m5v" → M5V');
app()->instance(WeatherCacheService::class, new class extends WeatherCacheService
{
    public function __construct() {}

    public function get(string $fsa): WeatherData
    {
        if ($fsa !== 'M5V') {
            throw new \RuntimeException("Expected normalized FSA 'M5V', got '{$fsa}'");
        }

        return makeWeatherStub('M5V');
    }
});

$r = dispatchWeather(['fsa' => 'm5v']);
assert_eq($r['status'], 200, 'fsa=m5v normalizes to M5V → 200');

// --- FSA normalization: full postal code "M5V 1A1" ---
logInfo('3.11 FSA normalization: full postal code "M5V 1A1" → M5V');
$r = dispatchWeather(['fsa' => 'M5V 1A1']);
assert_eq($r['status'], 200, 'fsa=M5V 1A1 normalizes to M5V → 200');
assert_eq($r['body']['data']['fsa'] ?? null, 'M5V', 'normalized M5V 1A1 → fsa=M5V');

// --- Alert fields populated when provider returns alert data ---
logInfo('3.12 Alert fields populated when provider returns orange alert');
app()->instance(WeatherCacheService::class, new class extends WeatherCacheService
{
    public function __construct() {}

    public function get(string $fsa): WeatherData
    {
        return makeWeatherStub($fsa, 2.0, 'orange', 'Freezing rain warning in effect.');
    }
});

$r = dispatchWeather(['fsa' => 'M5V']);
assert_eq($r['status'], 200, 'alert data → 200');
assert_eq($r['body']['data']['alert_level'] ?? null, 'orange', 'data.alert_level=orange');
assert_eq($r['body']['data']['alert_text'] ?? null, 'Freezing rain warning in effect.', 'data.alert_text matches');

// --- Yellow alert ---
logInfo('3.13 Yellow alert level');
app()->instance(WeatherCacheService::class, new class extends WeatherCacheService
{
    public function __construct() {}

    public function get(string $fsa): WeatherData
    {
        return makeWeatherStub($fsa, 8.0, 'yellow', 'Special weather statement.');
    }
});
$r = dispatchWeather(['fsa' => 'M5V']);
assert_eq($r['body']['data']['alert_level'] ?? null, 'yellow', 'data.alert_level=yellow');

// --- Red alert ---
logInfo('3.14 Red alert level');
app()->instance(WeatherCacheService::class, new class extends WeatherCacheService
{
    public function __construct() {}

    public function get(string $fsa): WeatherData
    {
        return makeWeatherStub($fsa, -15.0, 'red', 'Extreme cold warning.');
    }
});
$r = dispatchWeather(['fsa' => 'M5V']);
assert_eq($r['body']['data']['alert_level'] ?? null, 'red', 'data.alert_level=red');

// --- Null fields are explicitly present and null (not absent) ---
logInfo('3.15 Null optional fields are present as null (not absent)');
app()->instance(WeatherCacheService::class, new class extends WeatherCacheService
{
    public function __construct() {}

    public function get(string $fsa): WeatherData
    {
        return new WeatherData(
            fsa: $fsa,
            provider: EnvironmentCanadaWeatherProvider::NAME,
            temperature: null,
            humidity: null,
            windSpeed: null,
            windDirection: null,
            condition: null,
            alertLevel: null,
            alertText: null,
            fetchedAt: new DateTimeImmutable,
        );
    }
});
$r = dispatchWeather(['fsa' => 'M5V']);
assert_eq($r['status'], 200, 'all-null WeatherData → 200');
$d = $r['body']['data'] ?? [];
assert_null_key($d, 'temperature', 'null temperature present as null');
assert_null_key($d, 'humidity', 'null humidity present as null');
assert_null_key($d, 'wind_speed', 'null wind_speed present as null');
assert_null_key($d, 'condition', 'null condition present as null');

// --- 503 on WeatherFetchException ---
logInfo('3.16 503 when WeatherCacheService throws WeatherFetchException');
app()->instance(WeatherCacheService::class, new class extends WeatherCacheService
{
    public function __construct() {}

    public function get(string $fsa): WeatherData
    {
        throw new WeatherFetchException($fsa, 'environment_canada', 'connection refused by remote host');
    }
});

$r = dispatchWeather(['fsa' => 'M5V']);
assert_eq($r['status'], 503, 'WeatherFetchException → 503');
assert_has_key($r['body'] ?? [], 'message', '503 response has message key');
assert_eq($r['body']['message'] ?? null, 'Weather data is temporarily unavailable.', '503 message text');
assert_true(! isset($r['body']['errors']), '503 response has no errors key (not a validation error)');

// Restore real WeatherCacheService singleton factory
app()->forgetInstance(WeatherCacheService::class);

echo "\n";

// =========================================================
// Group 4: Route registration sanity
// =========================================================
echo "Group 4: Route registration sanity\n";
echo str_repeat('-', 60)."\n";

logInfo('4.1 Checking route names are registered');
$router = app('router');
$routes = $router->getRoutes();

assert_not_null($routes->getByName('api.postal-codes.search'), "route 'api.postal-codes.search' registered");
assert_not_null($routes->getByName('api.postal-codes.resolve-coords'), "route 'api.postal-codes.resolve-coords' registered");
assert_not_null($routes->getByName('api.weather'), "route 'api.weather' registered");

logInfo('4.2 Checking HTTP methods');
$searchRoute = $routes->getByName('api.postal-codes.search');
assert_contains($searchRoute?->methods() ?? [], 'GET', 'api.postal-codes.search method');

$resolveRoute = $routes->getByName('api.postal-codes.resolve-coords');
assert_contains($resolveRoute?->methods() ?? [], 'POST', 'api.postal-codes.resolve-coords method');

$weatherRoute = $routes->getByName('api.weather');
assert_contains($weatherRoute?->methods() ?? [], 'GET', 'api.weather method');

echo "\n";

// =========================================================
// Summary
// =========================================================
$total = $passed + $failed;
echo str_repeat('=', 60)."\n";
echo "Results: {$passed}/{$total} checks passed";
if ($failed > 0) {
    echo " ({$failed} FAILED)";
}
echo "\n";
echo "Logs: {$logFile}\n";
echo str_repeat('=', 60)."\n\n";

Log::channel('manual_test')->info('=== Verification Complete ===', [
    'passed' => $passed,
    'failed' => $failed,
    'total' => $total,
]);

exit($failed > 0 ? 1 : 0);
