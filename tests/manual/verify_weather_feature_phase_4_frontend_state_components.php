<?php

/**
 * Manual Test: Weather Feature – Phase 4: Frontend State & Components
 * Generated: 2026-03-25
 * Purpose: Verify frontend domain types, useWeather hook, LocationPicker component,
 *          and Footer integration with weather display.
 *
 * Run via:
 *   ./scripts/run-manual-test.sh tests/manual/verify_weather_feature_phase_4_frontend_state_components.php
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

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

// === Logging setup ===
$testRunId = 'weather_phase_4_verify_'.Carbon::now()->format('Y_m_d_His');
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
    echo "  ✓ {$msg}\n";
}

function logFail($msg, $ctx = [])
{
    global $failed;
    $failed++;
    Log::channel('manual_test')->error("[FAIL] {$msg}", $ctx);
    echo "  ✗ {$msg}\n";
}

function assert_true($actual, $label)
{
    if ($actual === true) {
        logOk("{$label} is true");
    } else {
        logFail("{$label}: expected true, got ".json_encode($actual));
    }
}

function assert_file_exists($path, $label)
{
    if (file_exists($path)) {
        logOk("{$label} exists: {$path}");
    } else {
        logFail("{$label}: file not found at {$path}");
    }
}

function assert_file_contains($path, $needle, $label)
{
    if (! file_exists($path)) {
        logFail("{$label}: file not found at {$path}");
        return;
    }
    $content = file_get_contents($path);
    if (str_contains($content, $needle)) {
        logOk("{$label} contains '{$needle}'");
    } else {
        logFail("{$label}: expected to contain '{$needle}'");
    }
}

function assert_export_exists($exportName, $label)
{
    $exports = get_defined_functions()['user'] ?? [];
    if (in_array($exportName, $exports)) {
        logOk("{$label} exports '{$exportName}'");
    } else {
        logFail("{$label}: missing export '{$exportName}'");
    }
}

function assert_class_exists($className, $label)
{
    if (class_exists($className)) {
        logOk("{$label}: class {$className} exists");
    } else {
        logFail("{$label}: class {$className} not found");
    }
}

function assert_interface_exists($interfaceName, $label)
{
    if (interface_exists($interfaceName)) {
        logOk("{$label}: interface {$interfaceName} exists");
    } else {
        logFail("{$label}: interface {$interfaceName} not found");
    }
}

function assert_enum_exists($enumName, $label)
{
    if (enum_exists($enumName)) {
        logOk("{$label}: enum {$enumName} exists");
    } else {
        logFail("{$label}: enum {$enumName} not found");
    }
}

// =========================================================
// Preflight
// =========================================================
echo "\n=== Phase 4 Manual Verification: Frontend State & Components ===\n";
echo "Run ID: {$testRunId}\n\n";

$basePath = base_path();
logInfo("Project base path: {$basePath}");

// =========================================================
// Group 1: Frontend Domain Types
// =========================================================
echo "\nGroup 1: Frontend Domain Types (resources/js/features/gta-alerts/domain/weather/)\n";
echo str_repeat('-', 70)."\n";

logInfo('1.1 Checking domain type definitions');
assert_file_exists("{$basePath}/resources/js/features/gta-alerts/domain/weather/types.ts", 'types.ts');
assert_file_exists("{$basePath}/resources/js/features/gta-alerts/domain/weather/resource.ts", 'resource.ts');
assert_file_exists("{$basePath}/resources/js/features/gta-alerts/domain/weather/fromResource.ts", 'fromResource.ts');

logInfo('1.2 Checking WeatherData type structure');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/types.ts", 'export type WeatherData', 'WeatherData type export');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/types.ts", 'temperature: number | null', 'WeatherData.temperature field');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/types.ts", 'humidity: number | null', 'WeatherData.humidity field');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/types.ts", "alertLevel: 'yellow' | 'orange' | 'red' | null", 'WeatherData.alertLevel field');

logInfo('1.3 Checking WeatherLocation type structure');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/types.ts", 'export type WeatherLocation', 'WeatherLocation type export');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/types.ts", 'fsa: string', 'WeatherLocation.fsa field');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/types.ts", 'label: string', 'WeatherLocation.label field');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/types.ts", 'lat: number', 'WeatherLocation.lat field');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/types.ts", 'lng: number', 'WeatherLocation.lng field');

logInfo('1.4 Checking Zod schemas in resource.ts');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/resource.ts", 'export const WeatherResourceSchema', 'WeatherResourceSchema export');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/resource.ts", 'export const PostalCodeResourceSchema', 'PostalCodeResourceSchema export');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/resource.ts", "alert_level: z.nullable(z.enum(['yellow', 'orange', 'red']))", 'alert_level enum schema');

logInfo('1.5 Checking fromResource mapper');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/fromResource.ts", 'export function fromWeatherResource', 'fromWeatherResource export');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/fromResource.ts", 'windSpeed: r.wind_speed', 'snake_case to camelCase mapping (windSpeed)');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/fromResource.ts", 'alertLevel: r.alert_level', 'snake_case to camelCase mapping (alertLevel)');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/fromResource.ts", '.safeParse(resource)', 'Zod safeParse validation');

echo "\n";

// =========================================================
// Group 2: useWeather Hook
// =========================================================
echo "\nGroup 2: useWeather Hook (resources/js/features/gta-alerts/hooks/)\n";
echo str_repeat('-', 70)."\n";

logInfo('2.1 Checking hook implementation');
assert_file_exists("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'useWeather.ts');
assert_file_exists("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.test.ts", 'useWeather.test.ts');

logInfo('2.2 Checking localStorage integration');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", "LOCATION_STORAGE_KEY = 'gta_weather_location_v1'", 'localStorage key constant');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'localStorage.getItem', 'localStorage.getItem usage');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'localStorage.setItem', 'localStorage.setItem usage');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'localStorage.removeItem', 'localStorage.removeItem usage');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", "typeof window === 'undefined'", 'SSR safety check');

logInfo('2.3 Checking API integration');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", '/api/weather', 'Weather API endpoint');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'AbortController', 'AbortController for request cancellation');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'fromWeatherResource', 'fromWeatherResource mapper usage');

logInfo('2.4 Checking hook return interface');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'export interface UseWeatherReturn', 'UseWeatherReturn interface export');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'location: WeatherLocation | null', 'return.location type');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'weather: WeatherData | null', 'return.weather type');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'isLoading: boolean', 'return.isLoading type');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'error: string | null', 'return.error type');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'setLocation:', 'return.setLocation method');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'refresh:', 'return.refresh method');

logInfo('2.5 Checking stale-while-revalidate behavior');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'stale data stays visible', 'stale-while-revalidate comment');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/hooks/useWeather.ts", 'const isLoading = location !== null && weather === null && error === null', 'derived isLoading logic');

echo "\n";

// =========================================================
// Group 3: LocationPicker Component
// =========================================================
echo "\nGroup 3: LocationPicker Component (resources/js/features/gta-alerts/components/)\n";
echo str_repeat('-', 70)."\n";

logInfo('3.1 Checking component implementation');
assert_file_exists("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", 'LocationPicker.tsx');
assert_file_exists("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.test.tsx", 'LocationPicker.test.tsx');

logInfo('3.2 Checking postal code search integration');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", '/api/postal-codes?q=', 'Postal code search API');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", 'PostalCodeResourceSchema', 'PostalCodeResourceSchema usage');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", 'parseResultList', 'parseResultList helper');

logInfo('3.3 Checking geolocation integration');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", 'navigator.geolocation', 'navigator.geolocation API');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", '/api/postal-codes/resolve-coords', 'Resolve coordinates API');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", 'handleGeolocate', 'handleGeolocate method');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", 'setGeoError', 'Geolocation error state');

logInfo('3.4 Checking CSRF token handling');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", 'csrfToken()', 'CSRF token helper');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", 'meta[name="csrf-token"]', 'CSRF meta tag selector');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", "'X-CSRF-TOKEN'", 'CSRF header');

logInfo('3.5 Checking accessibility attributes');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", "aria-label=\"Search for a GTA location\"", 'Search input aria-label');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", "role=\"listbox\"", 'Results listbox role');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", "role=\"option\"", 'Result item option role');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", "aria-selected", 'aria-selected attribute');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", "aria-expanded", 'aria-expanded attribute');

logInfo('3.6 Checking component props interface');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", 'interface LocationPickerProps', 'LocationPickerProps interface');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", 'onSelect: (location: WeatherLocation) => void', 'onSelect callback prop');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/LocationPicker.tsx", 'selectedLocation?: WeatherLocation | null', 'selectedLocation prop');

echo "\n";

// =========================================================
// Group 4: Footer Integration
// =========================================================
echo "\nGroup 4: Footer Integration (resources/js/features/gta-alerts/components/Footer.tsx)\n";
echo str_repeat('-', 70)."\n";

logInfo('4.1 Checking Footer component');
assert_file_exists("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'Footer.tsx');

logInfo('4.2 Checking weather props');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'interface FooterProps', 'FooterProps interface');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'weather: WeatherData | null', 'weather prop type');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'import type { WeatherData }', 'WeatherData import');

logInfo('4.3 Checking weather display elements');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'id="gta-alerts-footer-weather"', 'Weather container ID');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'thermostat', 'Temperature icon');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'weather.temperature', 'Temperature display');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'weather.humidity', 'Humidity display');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'weather.windSpeed', 'Wind speed display');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'weather.windDirection', 'Wind direction display');

logInfo('4.4 Checking alert badge display');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'ALERT_COLOURS', 'Alert color constants');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", "'yellow' | 'orange' | 'red'", 'Alert level types');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'weather.alertLevel', 'Alert level check');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'id="gta-alerts-footer-weather-alert"', 'Alert badge ID');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", "role=\"status\"", 'Alert badge status role');

logInfo('4.5 Checking empty state display');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'id="gta-alerts-footer-weather-no-location"', 'No location message ID');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'location_off', 'Location off icon');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/components/Footer.tsx", 'No location selected', 'Empty state text');

echo "\n";

// =========================================================
// Group 5: App.tsx Integration
// =========================================================
echo "\nGroup 5: App.tsx Integration\n";
echo str_repeat('-', 70)."\n";

logInfo('5.1 Checking useWeather usage in App.tsx');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/App.tsx", "import { useWeather } from './hooks/useWeather'", 'useWeather import');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/App.tsx", 'const {', 'Destructured hook result');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/App.tsx", 'location: weatherLocation', 'weatherLocation alias');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/App.tsx", 'setLocation: setWeatherLocation', 'setWeatherLocation alias');

logInfo('5.2 Checking LocationPicker integration');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/App.tsx", "import { LocationPicker } from './components/LocationPicker'", 'LocationPicker import');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/App.tsx", 'id="gta-alerts-header-location-picker"', 'LocationPicker container ID');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/App.tsx", 'onSelect={setWeatherLocation}', 'LocationPicker onSelect prop');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/App.tsx", 'selectedLocation={weatherLocation}', 'LocationPicker selectedLocation prop');

logInfo('5.3 Checking Footer weather prop');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/App.tsx", "import { Footer } from './components/Footer'", 'Footer import');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/App.tsx", '<Footer weather={weather} />', 'Footer with weather prop');

echo "\n";

// =========================================================
// Group 6: TypeScript Configuration
// =========================================================
echo "\nGroup 6: TypeScript Configuration\n";
echo str_repeat('-', 70)."\n";

logInfo('6.1 Checking Zod v4 import');
assert_file_contains("{$basePath}/resources/js/features/gta-alerts/domain/weather/resource.ts", "from 'zod/v4'", 'Zod v4 import');

echo "\n";

// =========================================================
// Group 7: Manual Browser Verification Steps
// =========================================================
echo "\nGroup 7: Manual Browser Verification Steps\n";
echo str_repeat('-', 70)."\n";

echo "\n[MANUAL] Please perform the following browser-based verification:\n\n";

echo "Step 1: Start the development server\n";
echo "  $ ./vendor/bin/sail up -d\n";
echo "  $ pnpm run dev\n\n";

echo "Step 2: Open the application in a browser\n";
echo "  Navigate to: http://localhost\n\n";

echo "Step 3: Test LocationPicker - Search by postal code\n";
echo "  - Look for the search box with placeholder 'Search postal code or area…'\n";
echo "  - Type 'M5V' and verify dropdown appears with results\n";
echo "  - Click a result and verify the footer shows weather data\n\n";

echo "Step 4: Test LocationPicker - Geolocation\n";
echo "  - Click the location icon button next to the search box\n";
echo "  - Allow location permission when prompted\n";
echo "  - Verify location resolves and weather displays in footer\n\n";

echo "Step 5: Verify Footer weather display\n";
echo "  - Check that temperature displays (e.g., '15.5 °C')\n";
echo "  - Check that humidity displays (e.g., '| Humidity: 65%')\n";
echo "  - Check that wind displays (e.g., '| Wind: 20 km/h NW')\n\n";

echo "Step 6: Verify localStorage persistence\n";
echo "  - Open browser DevTools → Application → Local Storage\n";
echo "  - Look for key 'gta_weather_location_v1'\n";
echo "  - Refresh page and verify location/weather persists\n\n";

echo "Step 7: Test error handling (optional)\n";
echo "  - Block the /api/weather endpoint in DevTools Network panel\n";
echo "  - Change location and verify graceful error handling\n\n";

// =========================================================
// Summary
// =========================================================
$total = $passed + $failed;
echo "\n".str_repeat('=', 70)."\n";
echo "Static Verification Results: {$passed}/{$total} checks passed";
if ($failed > 0) {
    echo " ({$failed} FAILED)";
}
echo "\n";
echo "Logs: {$logFile}\n";
echo str_repeat('=', 70)."\n\n";

Log::channel('manual_test')->info('=== Verification Complete ===', [
    'passed' => $passed,
    'failed' => $failed,
    'total' => $total,
]);

echo "IMPORTANT: Complete the manual browser verification steps above\n";
echo "before marking this phase as complete.\n\n";

exit($failed > 0 ? 1 : 0);
