<?php

/**
 * Manual Test: Notifications - Phase 2 Static Geofencing & Address Search
 * Generated: 2026-02-12
 * Purpose: Verify Phase 1 (track phase) static geofencing deliverables:
 * - Geospatial schema and routing contracts are present.
 * - Local geocoding search returns Toronto address + POI matches.
 * - Saved place CRUD enforces ownership + GTA coordinate validation.
 * - NotificationMatcher geofence behavior uses SavedPlace records.
 * - Targeted automation for newly added geofencing tests passes.
 */

require __DIR__.'/../../vendor/autoload.php';

if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}

$manualTestEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null);
if ($manualTestEnv === 'testing' && (getenv('APP_KEY') === false || getenv('APP_KEY') === '')) {
    $fallbackAppKey = 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=';
    putenv("APP_KEY={$fallbackAppKey}");
    $_ENV['APP_KEY'] = $fallbackAppKey;
    $_SERVER['APP_KEY'] = $fallbackAppKey;
}

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALLOW_ROOT_MANUAL_TESTS') !== '1') {
    fwrite(STDERR, "Error: Do not run manual tests as root. Use `./vendor/bin/sail shell` (or `./vendor/bin/sail php ...`).\n");
    fwrite(STDERR, "If you really need root, re-run with ALLOW_ROOT_MANUAL_TESTS=1 (not recommended).\n");
    exit(1);
}

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing. Destructive test operations are disabled outside the testing environment and cannot be overridden.\n");
}

$expectedDatabase = 'gta_alerts_testing';
$allowSqliteFallback = getenv('MANUAL_TEST_USE_SQLITE') === '1';
$connection = config('database.default');
$currentDatabase = config("database.connections.{$connection}.database");

if (! $allowSqliteFallback && $currentDatabase !== $expectedDatabase) {
    exit("Error: Manual tests must use the '{$expectedDatabase}' database (current: {$currentDatabase}). Destructive test operations are disabled and cannot be overridden.\n");
}

umask(002);

use App\Http\Controllers\Geocoding\LocalGeocodingSearchController;
use App\Http\Controllers\Notifications\SavedPlaceController;
use App\Http\Requests\Notifications\SavedPlaceStoreRequest;
use App\Http\Requests\Notifications\SavedPlaceUpdateRequest;
use App\Models\NotificationPreference;
use App\Models\SavedPlace;
use App\Models\TorontoAddress;
use App\Models\TorontoPointOfInterest;
use App\Models\User;
use App\Services\Geocoding\LocalGeocodingService;
use App\Services\Notifications\NotificationAlert;
use App\Services\Notifications\NotificationMatcher;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

$testRunId = 'notifications_phase_2_static_geofencing_address_search_'.Carbon::now()->format('Y_m_d_His');
$logFileRelative = "storage/logs/manual_tests/{$testRunId}.log";
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");
$logDir = dirname($logFile);

if (! is_dir($logDir) && ! mkdir($logDir, 0775, true) && ! is_dir($logDir)) {
    fwrite(STDERR, "Error: Failed to create log directory: {$logDir}\n");
    exit(1);
}

if (! file_exists($logFile) && @touch($logFile) === false) {
    fwrite(STDERR, "Error: Failed to create log file: {$logFile}\n");
    exit(1);
}

if (! @chmod($logFile, 0664)) {
    fwrite(STDERR, "Warning: Failed to set permissions on log file: {$logFile}\n");
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

function logInfo(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->info($msg, $ctx);
    $suffix = $ctx === [] ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES);
    echo "[INFO] {$msg}{$suffix}\n";
}

function logError(string $msg, array $ctx = []): void
{
    Log::channel('manual_test')->error($msg, $ctx);
    $suffix = $ctx === [] ? '' : ' '.json_encode($ctx, JSON_UNESCAPED_SLASHES);
    echo "[ERROR] {$msg}{$suffix}\n";
}

function assertTrue(bool $condition, string $label, array $ctx = []): void
{
    if (! $condition) {
        $message = "Assertion failed: {$label}.";
        logError($message, $ctx);
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function assertEqual(mixed $actual, mixed $expected, string $label): void
{
    if ($actual !== $expected) {
        $message = "Assertion failed for {$label}.";
        logError($message, ['expected' => $expected, 'actual' => $actual]);
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

function assertContainsText(string $needle, string $haystack, string $label): void
{
    assertTrue(str_contains($haystack, $needle), $label, ['needle' => $needle]);
}

/**
 * @return array<string, mixed>
 */
function decodeJsonResponse(JsonResponse $response): array
{
    $decoded = $response->getData(true);
    assertTrue(is_array($decoded), 'response payload decodes to array');

    return $decoded;
}

function readFileContents(string $relativePath): string
{
    $absolutePath = base_path($relativePath);
    assertTrue(file_exists($absolutePath), "file exists: {$relativePath}");
    $contents = file_get_contents($absolutePath);
    assertTrue(is_string($contents), "file is readable: {$relativePath}");

    return $contents;
}

/**
 * @return array{exit_code: int|null, output: string}
 */
function runCommand(string $command, string $label): array
{
    logInfo("Running command: {$label}", ['command' => $command]);

    $process = new Process(['bash', '-lc', $command], base_path());
    $process->setTimeout(null);

    $output = '';
    $process->run(function (string $type, string $buffer) use (&$output): void {
        $output .= $buffer;
        echo $buffer;
    });

    $exitCode = $process->getExitCode();

    Log::channel('manual_test')->info("Command output: {$label}", [
        'exit_code' => $exitCode,
        'output' => $output,
    ]);

    return [
        'exit_code' => $exitCode,
        'output' => $output,
    ];
}

function makeValidatedSavedPlaceStoreRequest(User $user, array $payload): SavedPlaceStoreRequest
{
    $request = SavedPlaceStoreRequest::create(
        '/api/saved-places',
        'POST',
        $payload,
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json']
    );

    $request->setContainer(app());
    $request->setRedirector(app('redirect'));
    $request->setUserResolver(static fn (): User => $user);
    $request->validateResolved();

    return $request;
}

function makeValidatedSavedPlaceUpdateRequest(User $user, int $savedPlaceId, array $payload): SavedPlaceUpdateRequest
{
    $request = SavedPlaceUpdateRequest::create(
        "/api/saved-places/{$savedPlaceId}",
        'PATCH',
        $payload,
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json']
    );

    $request->setContainer(app());
    $request->setRedirector(app('redirect'));
    $request->setUserResolver(static fn (): User => $user);
    $request->validateResolved();

    return $request;
}

function makeRequest(User $user, string $method, string $uri, array $query = []): Request
{
    $request = Request::create(
        $uri,
        strtoupper($method),
        $query,
        [],
        [],
        ['HTTP_ACCEPT' => 'application/json']
    );
    $request->setUserResolver(static fn (): User => $user);

    return $request;
}

function ensureSqliteDatabaseFile(string $databasePath): void
{
    $databaseDir = dirname($databasePath);

    if (! is_dir($databaseDir) && ! mkdir($databaseDir, 0775, true) && ! is_dir($databaseDir)) {
        throw new RuntimeException("Failed to create sqlite directory: {$databaseDir}");
    }

    if (! file_exists($databasePath) && @touch($databasePath) === false) {
        throw new RuntimeException("Failed to create sqlite database file: {$databasePath}");
    }
}

function configureSqliteFallback(string $databasePath): void
{
    ensureSqliteDatabaseFile($databasePath);

    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => $databasePath,
        'database.connections.sqlite.foreign_key_constraints' => true,
    ]);

    DB::purge('sqlite');
    DB::reconnect('sqlite');
}

$exitCode = 0;
$txStarted = false;
$usingSqliteFallback = false;
$sqliteFallbackPath = storage_path("app/private/manual_tests/{$testRunId}.sqlite");
$sqliteSuitePath = storage_path("app/private/manual_tests/{$testRunId}_suite.sqlite");

if ($allowSqliteFallback) {
    configureSqliteFallback($sqliteFallbackPath);
    $usingSqliteFallback = true;
}

try {
    try {
        DB::connection()->getPdo();
    } catch (Throwable $e) {
        if (! $allowSqliteFallback || $usingSqliteFallback) {
            throw new RuntimeException(
                "Database connection failed. If you're using Sail, run: ./scripts/init-testing-environment.sh or set MANUAL_TEST_USE_SQLITE=1 to allow sqlite fallback.",
                previous: $e
            );
        }

        logInfo('Primary DB connection failed; enabling sqlite fallback', ['reason' => $e->getMessage()]);
        configureSqliteFallback($sqliteFallbackPath);
        $usingSqliteFallback = true;
    }

    $activeConnection = config('database.default');
    $activeDatabase = config("database.connections.{$activeConnection}.database");

    logInfo('=== Starting Manual Test: Notifications Phase 2 Static Geofencing & Address Search ===');
    logInfo('Protocol Step 1/5: Verification started', [
        'app_env' => app()->environment(),
        'db_connection' => $activeConnection,
        'db_database' => $activeDatabase,
        'sqlite_fallback' => $usingSqliteFallback,
    ]);

    foreach (['notification_preferences', 'saved_places', 'toronto_addresses', 'toronto_pois'] as $table) {
        if (! Schema::hasTable($table)) {
            logInfo("Table {$table} missing; running migrations");
            Artisan::call('migrate', ['--force' => true]);
            logInfo('Migration output', ['output' => trim(Artisan::output())]);
            break;
        }
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('Protocol Step 2/5: Verifying changed modules have corresponding tests');

    $featureCommitLookup = runCommand(
        'git log --format=%H --grep="feat(notifications): implement phase 1 geofencing" -n 1',
        'Locate feature implementation commit'
    );
    assertTrue($featureCommitLookup['exit_code'] === 0, 'feature commit lookup command succeeds');
    $featureCommit = trim($featureCommitLookup['output']);
    assertTrue($featureCommit !== '', 'feature implementation commit hash located');

    $featureCommitDiff = runCommand(
        "git show --name-only --pretty='' {$featureCommit}",
        'List changed files for feature implementation commit'
    );
    assertTrue($featureCommitDiff['exit_code'] === 0, 'feature commit diff command succeeds');

    $changedFiles = array_values(array_filter(array_map(
        static fn (string $line): string => trim($line),
        explode("\n", $featureCommitDiff['output'])
    )));
    assertTrue($changedFiles !== [], 'feature implementation commit has changed files');
    logInfo('Feature commit changed files summary', [
        'commit' => $featureCommit,
        'count' => count($changedFiles),
    ]);

    $expectedTestCoverageMap = [
        'app/Console/Commands/ImportTorontoGeospatialDataCommand.php' => 'tests/Feature/Console/ImportTorontoGeospatialDataCommandTest.php',
        'app/Http/Controllers/Geocoding/LocalGeocodingSearchController.php' => 'tests/Feature/Geocoding/LocalGeocodingSearchControllerTest.php',
        'app/Http/Controllers/Notifications/SavedPlaceController.php' => 'tests/Feature/Notifications/SavedPlaceControllerTest.php',
        'app/Services/Notifications/NotificationMatcher.php' => 'tests/Feature/Notifications/AlertCreatedMatchingTest.php',
    ];

    foreach ($expectedTestCoverageMap as $sourcePath => $testPath) {
        assertTrue(file_exists(base_path($sourcePath)), "feature file exists: {$sourcePath}");
        assertTrue(file_exists(base_path($testPath)), "coverage test exists: {$testPath}");
        assertTrue(in_array($sourcePath, $changedFiles, true), "feature file is part of the tracked implementation diff: {$sourcePath}");
    }

    $matchingTestsContents = readFileContents('tests/Feature/Notifications/AlertCreatedMatchingTest.php');
    assertContainsText('queues notification jobs only for matching preferences', $matchingTestsContents, 'matching test covers geofence preference dispatch');
    assertContainsText('transit alerts respect subscribed route matching when provided', $matchingTestsContents, 'matching test covers route filtering');

    $savedPlaceTestsContents = readFileContents('tests/Feature/Notifications/SavedPlaceControllerTest.php');
    assertContainsText('saved places update and delete are scoped to the owner', $savedPlaceTestsContents, 'saved place test covers ownership boundary');
    assertContainsText('saved places validation enforces gta bounds', $savedPlaceTestsContents, 'saved place test covers GTA bounds validation');

    logInfo('Phase A: Schema and route integration checks');

    assertTrue(! Schema::hasColumn('notification_preferences', 'geofences'), 'notification_preferences.geofences column removed');

    assertTrue(Schema::hasTable('saved_places'), 'saved_places table exists');
    assertTrue(Schema::hasColumns('saved_places', ['id', 'user_id', 'name', 'lat', 'long', 'radius', 'type']), 'saved_places columns exist');
    assertTrue(Schema::hasIndex('saved_places', ['user_id']), 'saved_places has user_id index');
    assertTrue(Schema::hasIndex('saved_places', ['user_id', 'type']), 'saved_places has user_id+type index');

    assertTrue(Schema::hasTable('toronto_addresses'), 'toronto_addresses table exists');
    assertTrue(Schema::hasColumns('toronto_addresses', ['id', 'street_num', 'street_name', 'lat', 'long', 'zip']), 'toronto_addresses columns exist');

    assertTrue(Schema::hasTable('toronto_pois'), 'toronto_pois table exists');
    assertTrue(Schema::hasColumns('toronto_pois', ['id', 'name', 'category', 'lat', 'long']), 'toronto_pois columns exist');

    assertTrue(Route::has('api.geocoding.search'), 'geocoding API route registered');
    assertTrue(Route::has('api.saved-places.index'), 'saved places index API route registered');
    assertTrue(Route::has('api.saved-places.store'), 'saved places store API route registered');
    assertTrue(Route::has('api.saved-places.update'), 'saved places update API route registered');
    assertTrue(Route::has('api.saved-places.destroy'), 'saved places destroy API route registered');

    $settingsViewContents = readFileContents('resources/js/features/gta-alerts/components/SettingsView.tsx');
    assertContainsText('<SavedPlacesManager authUserId={authUserId} />', $settingsViewContents, 'settings UI renders SavedPlacesManager');

    $savedPlacesManagerContents = readFileContents('resources/js/features/gta-alerts/components/SavedPlacesManager.tsx');
    assertContainsText('Search Toronto addresses or POIs and save geofenced places.', $savedPlacesManagerContents, 'saved places manager description text is present');
    assertContainsText('Saved place added.', $savedPlacesManagerContents, 'saved places manager handles success state');

    logInfo('Phase B: Local geocoding service + controller behavior');

    TorontoAddress::query()->delete();
    TorontoPointOfInterest::query()->delete();

    TorontoAddress::query()->create([
        'street_num' => '100',
        'street_name' => 'Queen St W',
        'lat' => 43.6531,
        'long' => -79.3840,
        'zip' => 'M5H 2N2',
    ]);

    TorontoAddress::query()->create([
        'street_num' => '25',
        'street_name' => 'King St E',
        'lat' => 43.6501,
        'long' => -79.3720,
        'zip' => 'M5C 1E9',
    ]);

    TorontoPointOfInterest::query()->create([
        'name' => 'Queen Station',
        'category' => 'Transit',
        'lat' => 43.6524,
        'long' => -79.3799,
    ]);

    TorontoPointOfInterest::query()->create([
        'name' => 'CN Tower',
        'category' => 'Landmark',
        'lat' => 43.6426,
        'long' => -79.3871,
    ]);

    $serviceResults = app(LocalGeocodingService::class)->search('queen', 8);
    $serviceNames = collect($serviceResults)->pluck('name')->all();
    assertTrue(in_array('100 Queen St W', $serviceNames, true), 'service search returns matching address');
    assertTrue(in_array('Queen Station', $serviceNames, true), 'service search returns matching POI');
    assertTrue(! in_array('CN Tower', $serviceNames, true), 'service search excludes unrelated POI');

    $geocodingController = app(LocalGeocodingSearchController::class);
    $authedUser = User::factory()->create();

    $invalidRequest = makeRequest($authedUser, 'GET', '/api/geocoding/search');
    $invalidResponse = $geocodingController($invalidRequest, app(LocalGeocodingService::class));
    assertEqual($invalidResponse->status(), 422, 'geocoding controller validates required q');
    $invalidPayload = decodeJsonResponse($invalidResponse);
    assertTrue(isset($invalidPayload['errors']['q']), 'validation payload includes q errors');

    $shortRequest = makeRequest($authedUser, 'GET', '/api/geocoding/search', ['q' => 'q']);
    $shortResponse = $geocodingController($shortRequest, app(LocalGeocodingService::class));
    assertEqual($shortResponse->status(), 200, 'geocoding controller returns ok for short query');
    $shortPayload = decodeJsonResponse($shortResponse);
    assertEqual($shortPayload['data'] ?? null, [], 'geocoding controller returns empty data for <2 chars');

    $validRequest = makeRequest($authedUser, 'GET', '/api/geocoding/search', ['q' => 'queen', 'limit' => 8]);
    $validResponse = $geocodingController($validRequest, app(LocalGeocodingService::class));
    assertEqual($validResponse->status(), 200, 'geocoding controller returns data for valid query');
    $validPayload = decodeJsonResponse($validResponse);
    $validNames = collect($validPayload['data'] ?? [])->pluck('name')->all();
    assertTrue(in_array('100 Queen St W', $validNames, true), 'geocoding controller payload includes address match');
    assertTrue(in_array('Queen Station', $validNames, true), 'geocoding controller payload includes POI match');

    logInfo('Phase C: Saved places CRUD, validation, and ownership scoping');

    SavedPlace::query()->delete();

    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $savedPlaceController = app(SavedPlaceController::class);

    $storeRequest = makeValidatedSavedPlaceStoreRequest($owner, [
        'name' => 'CN Tower',
        'lat' => 43.6426,
        'long' => -79.3871,
        'radius' => 750,
        'type' => 'poi',
    ]);
    $storeResponse = $savedPlaceController->store($storeRequest);
    assertEqual($storeResponse->status(), 201, 'saved place create returns 201');
    $storedPayload = decodeJsonResponse($storeResponse);
    assertEqual($storedPayload['data']['name'] ?? null, 'CN Tower', 'saved place create returns expected name');
    assertEqual($storedPayload['data']['radius'] ?? null, 750, 'saved place create returns expected radius');
    assertEqual($storedPayload['data']['type'] ?? null, 'poi', 'saved place create returns expected type');

    $savedPlaceId = (int) ($storedPayload['data']['id'] ?? 0);
    assertTrue($savedPlaceId > 0, 'saved place id is returned');

    $indexRequest = makeRequest($owner, 'GET', '/api/saved-places');
    $indexResponse = $savedPlaceController->index($indexRequest);
    assertEqual($indexResponse->status(), 200, 'saved place index returns 200');
    $indexPayload = decodeJsonResponse($indexResponse);
    assertEqual(count($indexPayload['data'] ?? []), 1, 'saved place index returns owner records');
    assertEqual($indexPayload['data'][0]['id'] ?? null, $savedPlaceId, 'saved place index includes created record');

    $updateRequest = makeValidatedSavedPlaceUpdateRequest($owner, $savedPlaceId, [
        'name' => 'Updated CN Tower',
        'radius' => 1000,
    ]);
    $updateResponse = $savedPlaceController->update($updateRequest, $savedPlaceId);
    assertEqual($updateResponse->status(), 200, 'saved place update returns 200');
    $updatePayload = decodeJsonResponse($updateResponse);
    assertEqual($updatePayload['data']['name'] ?? null, 'Updated CN Tower', 'saved place update mutates name');
    assertEqual($updatePayload['data']['radius'] ?? null, 1000, 'saved place update mutates radius');

    try {
        makeValidatedSavedPlaceStoreRequest($owner, [
            'name' => 'Outside GTA',
            'lat' => 45.0000,
            'long' => -79.3871,
            'radius' => 500,
            'type' => 'address',
        ]);

        throw new RuntimeException('Expected ValidationException for out-of-bounds lat.');
    } catch (ValidationException $e) {
        assertTrue($e->validator->errors()->has('lat'), 'saved place validation rejects out-of-bounds lat');
    }

    try {
        $unauthorizedUpdate = makeValidatedSavedPlaceUpdateRequest($otherUser, $savedPlaceId, [
            'name' => 'Unauthorized update',
        ]);
        $savedPlaceController->update($unauthorizedUpdate, $savedPlaceId);
        throw new RuntimeException('Expected ModelNotFoundException for cross-user saved place update.');
    } catch (ModelNotFoundException) {
        logInfo('Assertion passed: saved place update is owner-scoped');
    }

    $deleteRequest = makeRequest($owner, 'DELETE', "/api/saved-places/{$savedPlaceId}");
    $deleteResponse = $savedPlaceController->destroy($deleteRequest, $savedPlaceId);
    assertEqual($deleteResponse->status(), 200, 'saved place delete returns 200');
    $deletePayload = decodeJsonResponse($deleteResponse);
    assertEqual($deletePayload['meta']['deleted'] ?? null, true, 'saved place delete sets meta.deleted=true');
    assertTrue(! SavedPlace::query()->whereKey($savedPlaceId)->exists(), 'saved place record is deleted');

    logInfo('Phase D: NotificationMatcher geofence behavior with SavedPlace records');

    NotificationPreference::query()->delete();
    SavedPlace::query()->delete();

    $openPreference = NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'major',
        'subscribed_routes' => [],
        'push_enabled' => true,
    ]);

    $nearPreference = NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'major',
        'subscribed_routes' => [],
        'push_enabled' => true,
    ]);

    SavedPlace::query()->create([
        'user_id' => $nearPreference->user_id,
        'name' => 'Near Zone',
        'lat' => 43.7000,
        'long' => -79.4000,
        'radius' => 3000,
        'type' => 'address',
    ]);

    $farPreference = NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'major',
        'subscribed_routes' => [],
        'push_enabled' => true,
    ]);

    SavedPlace::query()->create([
        'user_id' => $farPreference->user_id,
        'name' => 'Far Zone',
        'lat' => 44.1000,
        'long' => -79.1000,
        'radius' => 1000,
        'type' => 'address',
    ]);

    $matcher = app(NotificationMatcher::class);

    $nearbyAlert = new NotificationAlert(
        alertId: 'police:manual:1',
        source: 'police',
        severity: 'major',
        summary: 'Manual verification alert near downtown',
        occurredAt: CarbonImmutable::parse('2026-02-12T00:00:00Z'),
        lat: 43.7010,
        lng: -79.4010,
    );

    $matchingUserIds = $matcher
        ->matchingPreferences($nearbyAlert)
        ->map(fn (NotificationPreference $preference): int => $preference->user_id)
        ->values()
        ->all();

    assertTrue(in_array($openPreference->user_id, $matchingUserIds, true), 'preference without saved place matches by default');
    assertTrue(in_array($nearPreference->user_id, $matchingUserIds, true), 'near saved place matches alert coordinates');
    assertTrue(! in_array($farPreference->user_id, $matchingUserIds, true), 'far saved place does not match alert coordinates');

    $missingCoordinateAlert = new NotificationAlert(
        alertId: 'police:manual:2',
        source: 'police',
        severity: 'major',
        summary: 'Manual verification alert without coordinates',
        occurredAt: CarbonImmutable::parse('2026-02-12T00:01:00Z'),
    );

    $missingCoordinateUserIds = $matcher
        ->matchingPreferences($missingCoordinateAlert)
        ->map(fn (NotificationPreference $preference): int => $preference->user_id)
        ->values()
        ->all();

    assertTrue(in_array($openPreference->user_id, $missingCoordinateUserIds, true), 'coordinate-less alerts still match users without saved places');
    assertTrue(! in_array($nearPreference->user_id, $missingCoordinateUserIds, true), 'coordinate-less alerts do not match users with saved places');

    if ($txStarted && DB::connection()->transactionLevel() > 0) {
        DB::rollBack();
        $txStarted = false;
        logInfo('Transaction rolled back before command-based gates.');
    }

    logInfo('Protocol Step 3/5: Execute automated test commands');

    $commandPrefix = '';
    if ($usingSqliteFallback) {
        ensureSqliteDatabaseFile($sqliteSuitePath);
        $commandPrefix = 'DB_CONNECTION=sqlite DB_DATABASE='.escapeshellarg($sqliteSuitePath).' ';
    }

    $commands = [
        [
            'label' => 'ImportTorontoGeospatialDataCommandTest',
            'command' => $commandPrefix.'CI=true php artisan test --filter=ImportTorontoGeospatialDataCommandTest',
        ],
        [
            'label' => 'LocalGeocodingSearchControllerTest',
            'command' => $commandPrefix.'CI=true php artisan test --filter=LocalGeocodingSearchControllerTest',
        ],
        [
            'label' => 'SavedPlaceControllerTest',
            'command' => $commandPrefix.'CI=true php artisan test --filter=SavedPlaceControllerTest',
        ],
        [
            'label' => 'AlertCreatedMatchingTest',
            'command' => $commandPrefix.'CI=true php artisan test --filter=AlertCreatedMatchingTest',
        ],
    ];

    foreach ($commands as $command) {
        $result = runCommand($command['command'], $command['label']);
        assertTrue(
            $result['exit_code'] === 0,
            "command passes: {$command['label']}",
            ['exit_code' => $result['exit_code']]
        );
    }

    logInfo('Protocol Step 4/5: Proposed manual browser verification plan');
    $manualSteps = [
        "Start the local stack: ./vendor/bin/sail up -d && ./vendor/bin/sail pnpm run dev",
        "Sign in to the app and open the GTA Alerts landing page.",
        "Open Notification Settings and expand Saved Places Manager.",
        "Search for '100 Queen St W' and save as 500m radius.",
        "Search for 'CN Tower' and save as 1000m radius.",
        "Confirm both places appear in the saved list; delete one and verify it disappears immediately.",
        "Open browser network tab and verify autocomplete calls /api/geocoding/search and CRUD calls /api/saved-places.",
    ];

    foreach ($manualSteps as $index => $step) {
        logInfo('Manual step', ['step' => $index + 1, 'instruction' => $step]);
    }

    logInfo('Protocol Step 5/5: Await explicit user verification feedback outside script execution', [
        'expected_feedback' => 'yes/no with any UI regressions',
    ]);

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
} finally {
    if ($txStarted) {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                logInfo('Transaction rolled back (Database preserved).');
            }
        } catch (Throwable) {
        }
    }

    if ($usingSqliteFallback && file_exists($sqliteFallbackPath)) {
        @unlink($sqliteFallbackPath);
    }

    if ($usingSqliteFallback && file_exists($sqliteSuitePath)) {
        @unlink($sqliteSuitePath);
    }

    logInfo('Manual test log file', ['path' => $logFileRelative]);
}

exit($exitCode);
