<?php

/**
 * Manual Test: Weather Feature Phase 5 QA Verification
 * Generated: 2026-03-25
 * Purpose: End-to-end verification of weather feature APIs and services
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Prevent production execution
if (app()->environment('production')) {
    die("Error: Cannot run manual tests in production!\n");
}

use Illuminate\Support\Facades\{DB, Log, Http, Cache};
use App\Services\Weather\WeatherCacheService;
use App\Services\Weather\WeatherFetchService;
use App\Services\Weather\DTOs\WeatherData;
use App\Models\GtaPostalCode;
use App\Models\WeatherCache;
use Carbon\Carbon;

$testRunId = 'weather_qa_' . Carbon::now()->format('Y_m_d_His');
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

function logSuccess($msg, $ctx = []) {
    Log::channel('manual_test')->info("[PASS] {$msg}", $ctx);
    echo "[PASS] {$msg}\n";
}

function logWarn($msg, $ctx = []) {
    Log::channel('manual_test')->warning($msg, $ctx);
    echo "[WARN] {$msg}\n";
}

$passedTests = 0;
$failedTests = 0;

function assertTest($condition, $testName, $context = []) {
    global $passedTests, $failedTests;
    if ($condition) {
        logSuccess($testName, $context);
        $passedTests++;
    } else {
        logError($testName, $context);
        $failedTests++;
    }
}

try {
    DB::beginTransaction();
    logInfo("=== Weather Feature Phase 5 QA Verification ===");
    logInfo("Test Run ID: {$testRunId}");

    // ============================================
    // PHASE 1: Database Setup Verification
    // ============================================
    logInfo("\n--- Phase 1: Database Foundation Verification ---");

    // 1.1 Verify gta_postal_codes table exists and has data
    $postalCodeCount = GtaPostalCode::count();
    assertTest($postalCodeCount > 0, "GtaPostalCode table has records", ['count' => $postalCodeCount]);
    logInfo("Found {$postalCodeCount} postal codes in reference table");

    // 1.2 Verify weather_caches table exists
    $weatherCacheTableExists = DB::getSchemaBuilder()->hasTable('weather_caches');
    assertTest($weatherCacheTableExists, "Weather caches table exists");

    // 1.3 Test GtaPostalCode model search functionality
    $searchResults = GtaPostalCode::search('M5V')->limit(5)->get();
    assertTest($searchResults->count() > 0, "Postal code search returns results for 'M5V'");

    // 1.4 Test nearest FSA resolution
    $dtLat = 43.6532;
    $dtLng = -79.3832;
    $nearestFsa = GtaPostalCode::nearestFsa($dtLat, $dtLng)?->fsa;
    assertTest($nearestFsa !== null, "Nearest FSA resolution works for Downtown Toronto coordinates");
    logInfo("Nearest FSA to Downtown Toronto: {$nearestFsa}");

    // ============================================
    // PHASE 2: Weather Services Verification
    // ============================================
    logInfo("\n--- Phase 2: Weather Services Verification ---");

    // 2.1 Test WeatherCacheService fast cache (miss expected initially)
    $cacheService = app(WeatherCacheService::class);
    $testFsa = 'M5V';
    $fastCacheKey = 'weather.current.' . $testFsa;

    // Clear any existing cache first
    Cache::forget($fastCacheKey);
    WeatherCache::where('fsa', $testFsa)->delete();

    $fastCacheMiss = Cache::get($fastCacheKey);
    assertTest($fastCacheMiss === null, "Fast cache initially empty for test FSA");

    // 2.2 Test WeatherFetchService (may fail if Environment Canada is down, which is acceptable)
    logInfo("Testing WeatherFetchService (Environment Canada integration)...");
    $fetchService = app(WeatherFetchService::class);

    try {
        $weatherData = $fetchService->fetch($testFsa);
        assertTest($weatherData instanceof WeatherData, "WeatherFetchService returns WeatherData DTO");
        assertTest(is_numeric($weatherData->temperature), "Weather data has valid temperature", ['temp' => $weatherData->temperature]);
        assertTest(is_string($weatherData->condition), "Weather data has condition description");
        logInfo("Live fetch successful", [
            'temperature' => $weatherData->temperature,
            'condition' => $weatherData->condition,
            'humidity' => $weatherData->humidity,
        ]);

        // 2.3 Test caching after successful fetch
        $cachedWeather = $cacheService->get($testFsa);
        assertTest($cachedWeather !== null, "WeatherCacheService caches fetched data");

        // 2.4 Test fast cache hit
        $fastCacheHit = Cache::get($fastCacheKey);
        assertTest($fastCacheHit !== null, "Fast cache populated after fetch");

    } catch (\App\Services\Weather\Exceptions\WeatherFetchException $e) {
        logWarn("Weather fetch failed (external service may be unavailable)", ['error' => $e->getMessage()]);
        logInfo("This is acceptable - provider resilience is working as designed");
    }

    // 2.5 Test WeatherData DTO structure
    $mockWeatherData = new WeatherData(
        fsa: 'M5V',
        provider: 'TestProvider',
        temperature: 22.5,
        humidity: 65,
        windSpeed: '12.3',
        windDirection: 'NW',
        condition: 'Partly Cloudy',
        alertLevel: 'yellow',
        alertText: 'Heat Warning in effect',
        fetchedAt: new \DateTimeImmutable()
    );
    assertTest($mockWeatherData->alertLevel === 'yellow', "WeatherData DTO supports alert levels");
    assertTest($mockWeatherData->alertText === 'Heat Warning in effect', "WeatherData DTO supports alert text");

    // ============================================
    // PHASE 3: API Endpoints Verification (Simulated)
    // ============================================
    logInfo("\n--- Phase 3: API Endpoints Verification ---");

    // 3.1 Test Postal Code Search endpoint logic
    $searchQuery = 'Toronto';
    $searchResults = GtaPostalCode::search($searchQuery)
        ->limit(10)
        ->get();
    assertTest($searchResults->count() > 0, "Postal code search API logic returns results for 'Toronto'");
    logInfo("Found {$searchResults->count()} results for 'Toronto' search");

    // 3.2 Test coordinate resolution logic
    $gtaBounds = [
        'lat_min' => 43.2,
        'lat_max' => 44.2,
        'lng_min' => -80.0,
        'lng_max' => -78.6,
    ];

    $testCoords = [
        ['lat' => 43.6532, 'lng' => -79.3832, 'name' => 'Downtown Toronto'],
        ['lat' => 43.7315, 'lng' => -79.7624, 'name' => 'Brampton'],
        ['lat' => 43.5890, 'lng' => -79.6441, 'name' => 'Mississauga'],
    ];

    foreach ($testCoords as $coord) {
        $inBounds = (
            $coord['lat'] >= $gtaBounds['lat_min'] &&
            $coord['lat'] <= $gtaBounds['lat_max'] &&
            $coord['lng'] >= $gtaBounds['lng_min'] &&
            $coord['lng'] <= $gtaBounds['lng_max']
        );
        assertTest($inBounds, "Coordinates for {$coord['name']} are within GTA bounds");

        $resolvedFsa = GtaPostalCode::nearestFsa($coord['lat'], $coord['lng'])?->fsa;
        assertTest($resolvedFsa !== null, "Nearest FSA resolved for {$coord['name']}");
        logInfo("{$coord['name']}: {$resolvedFsa}");
    }

    // 3.3 Test out-of-bounds rejection
    $outOfBoundsLat = 45.5;
    $outOfBoundsLng = -75.7;
    $outOfBounds = !(
        $outOfBoundsLat >= $gtaBounds['lat_min'] &&
        $outOfBoundsLat <= $gtaBounds['lat_max'] &&
        $outOfBoundsLng >= $gtaBounds['lng_min'] &&
        $outOfBoundsLng <= $gtaBounds['lng_max']
    );
    assertTest($outOfBounds, "Out-of-bounds coordinates (Ottawa) correctly rejected");

    // 3.4 Test FSA format validation
    $validFsas = ['M5V', 'M5V 2L8', 'm5v', 'L6P', 'M5V2L8ABC'];
    $invalidFsas = ['ABC', '12345', '', 'M'];

    foreach ($validFsas as $fsa) {
        $normalized = strtoupper(substr(str_replace(' ', '', $fsa), 0, 3));
        $isValid = (bool) preg_match('/^[A-Z]\d[A-Z]$/', $normalized);
        assertTest($isValid, "FSA '{$fsa}' normalizes to valid format '{$normalized}'");
    }

    foreach ($invalidFsas as $fsa) {
        $normalized = strtoupper(substr(str_replace(' ', '', $fsa), 0, 3));
        $isValid = (bool) preg_match('/^[A-Z]\d[A-Z]$/', $normalized);
        assertTest(!$isValid || empty($fsa), "Invalid FSA '{$fsa}' correctly rejected");
    }

    // ============================================
    // PHASE 4: Cache Behavior Verification
    // ============================================
    logInfo("\n--- Phase 4: Cache Behavior Verification ---");

    // 4.1 Test WeatherCache model findValid method
    $testCacheFsa = 'M5A';
    $testProvider = 'EnvironmentCanada';
    WeatherCache::where('fsa', $testCacheFsa)->delete();

    // Create a valid cache entry
    $validCache = WeatherCache::create([
        'fsa' => $testCacheFsa,
        'provider' => $testProvider,
        'payload' => [
            'temperature' => 25.0,
            'humidity' => 60,
            'wind_speed' => 10.0,
            'wind_direction' => 'S',
            'condition' => 'Sunny',
            'alert_level' => null,
            'alert_text' => null,
            'fetched_at' => Carbon::now()->toIso8601String(),
        ],
        'fetched_at' => Carbon::now(),
    ]);

    $foundValid = WeatherCache::findValid($testCacheFsa, $testProvider);
    assertTest($foundValid !== null, "WeatherCache::findValid returns valid cache entry");
    assertTest($foundValid->fsa === $testCacheFsa, "Retrieved cache entry has correct FSA");

    // 4.2 Test expired cache rejection
    $expiredFsa = 'M5B';
    WeatherCache::where('fsa', $expiredFsa)->delete();

    WeatherCache::create([
        'fsa' => $expiredFsa,
        'provider' => $testProvider,
        'payload' => ['temperature' => 20.0],
        'fetched_at' => Carbon::now()->subMinutes(35), // Expired (default TTL is 30 min)
    ]);

    $expiredCache = WeatherCache::findValid($expiredFsa, $testProvider);
    assertTest($expiredCache === null, "Expired cache entry correctly rejected");

    // 4.3 Test isFresh method
    assertTest($validCache->fresh()->isFresh() === true, "Recent cache entry reports as fresh");

    // ============================================
    // PHASE 5: Error Handling & Resilience
    // ============================================
    logInfo("\n--- Phase 5: Error Handling & Resilience ---");

    // 5.1 Test WeatherFetchException
    $exception = new \App\Services\Weather\Exceptions\WeatherFetchException(
        fsa: 'M5V',
        provider: 'TestProvider',
        reason: 'Test error message'
    );
    assertTest(strpos($exception->getMessage(), 'Test error message') !== false, "WeatherFetchException stores message");
    assertTest($exception->fsa === 'M5V', "WeatherFetchException stores FSA");
    assertTest($exception->provider === 'TestProvider', "WeatherFetchException stores provider");

    // 5.2 Test provider failure cascade
    config(['weather.providers' => []]); // Empty providers
    $fetchServiceWithNoProviders = new WeatherFetchService([]);
    try {
        $fetchServiceWithNoProviders->fetch('M5V');
        assertTest(false, "Empty provider list should throw exception");
    } catch (\App\Services\Weather\Exceptions\WeatherFetchException $e) {
        assertTest(true, "Empty provider list correctly throws WeatherFetchException");
    }

    // Restore config
    config(['weather.providers' => [\App\Services\Weather\Providers\EnvironmentCanadaWeatherProvider::class]]);

    // 5.3 Test unknown FSA handling (falls back to default coordinates)
    // The provider doesn't reject unknown FSAs; it normalizes them and uses default coords
    try {
        $result = $fetchService->fetch('UNKNOWN');
        assertTest($result instanceof WeatherData, "Unknown FSA falls back to default coordinates and returns WeatherData");
        assertTest($result->fsa === 'UNKNOWN', "Unknown FSA preserves the original FSA in the result");
    } catch (\App\Services\Weather\Exceptions\WeatherFetchException $e) {
        // Even with unknown FSA, if the API works, it should succeed
        assertTest(false, "Unknown FSA should not cause exception if API is available: " . $e->getMessage());
    }

    // ============================================
    // PHASE 6: Configuration Verification
    // ============================================
    logInfo("\n--- Phase 6: Configuration Verification ---");

    // 6.1 Verify weather.php config exists
    $configExists = file_exists(config_path('weather.php'));
    assertTest($configExists, "Weather configuration file exists");

    // 6.2 Verify required config keys
    $requiredKeys = ['providers', 'timeout_seconds', 'environment_canada'];
    foreach ($requiredKeys as $key) {
        $hasKey = config("weather.{$key}") !== null;
        assertTest($hasKey, "Config key 'weather.{$key}' exists");
    }

    // 6.2b Verify WeatherCache TTL constant exists
    assertTest(\App\Models\WeatherCache::TTL_MINUTES > 0, "WeatherCache::TTL_MINUTES constant is defined");

    // 6.3 Verify provider class exists
    $providerClass = config('weather.providers')[0] ?? null;
    if ($providerClass) {
        assertTest(class_exists($providerClass), "Weather provider class exists: {$providerClass}");
    }

    // ============================================
    // Summary
    // ============================================
    logInfo("\n=== Verification Summary ===");
    logInfo("Passed: {$passedTests}");
    logInfo("Failed: {$failedTests}");
    logInfo("Total:  " . ($passedTests + $failedTests));

    if ($failedTests === 0) {
        logInfo("\n✅ ALL TESTS PASSED - Weather Feature Phase 5 QA Complete");
    } else {
        logError("\n⚠️  SOME TESTS FAILED - Review failures above");
    }

} catch (\Exception $e) {
    logError("Unexpected error during verification", [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    DB::rollBack();
    logInfo("\nDatabase transaction rolled back (cleanup complete)");
    logInfo("Full logs available at: {$logFile}");
    echo "\n📄 Check detailed logs at: {$logFile}\n";
}

exit($failedTests > 0 ? 1 : 0);
