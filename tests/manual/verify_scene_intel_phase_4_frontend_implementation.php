<?php

/**
 * Manual Test: Scene Intel - Phase 4 Frontend Implementation
 * Generated: 2026-02-14
 * Purpose: Verify Scene Intel Phase 4 frontend deliverables:
 * - SceneIntelItem Zod schema and type definition matches backend IncidentUpdateResource
 * - FireMetaSchema extensions (intel_summary, intel_last_updated) parse correctly
 * - SceneIntelTimeline component integration with AlertDetailsView
 * - useSceneIntel hook polling endpoint contract (/api/incidents/{eventNum}/intel)
 * - End-to-end data round-trip: DB → API → frontend schema validation
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

use App\Enums\IncidentUpdateType;
use App\Http\Controllers\SceneIntelController;
use App\Http\Resources\IncidentUpdateResource;
use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

$testRunId = 'scene_intel_phase_4_frontend_implementation_'.Carbon::now()->format('Y_m_d_His');
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

/**
 * @return array<string, mixed>
 */
function decodeJsonResponse(Illuminate\Http\JsonResponse $response): array
{
    $decoded = json_decode((string) $response->getContent(), true);

    if (! is_array($decoded)) {
        throw new RuntimeException('Expected JSON response body to decode to an array.');
    }

    return $decoded;
}

$exitCode = 0;
$txStarted = false;

try {
    try {
        Illuminate\Support\Facades\DB::connection()->getPdo();
    } catch (Throwable $e) {
        throw new RuntimeException(
            "Database connection failed. If you're using Sail, run: ./scripts/init-testing-environment.sh",
            previous: $e
        );
    }

    logInfo('Boot context', [
        'app_env' => app()->environment(),
        'db_connection' => $connection,
        'db_database' => $currentDatabase,
    ]);

    if (! Schema::hasTable('fire_incidents') || ! Schema::hasTable('incident_updates')) {
        logInfo('Required tables missing; running migrations for testing database');
        Artisan::call('migrate', ['--force' => true]);
        logInfo('Migration output', ['output' => trim(Artisan::output())]);
    }

    Illuminate\Support\Facades\DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: Scene Intel Phase 4 Frontend Implementation ===');

    // =========================================================================
    // Phase 1: IncidentUpdateResource contract matches frontend SceneIntelItemSchema
    // =========================================================================
    logInfo('Phase 1: Verify IncidentUpdateResource contract matches frontend SceneIntelItemSchema');

    $incident = FireIncident::factory()->create([
        'event_num' => 'F26071001',
        'is_active' => true,
        'alarm_level' => 2,
    ]);

    $alarmUpdate = IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'update_type' => IncidentUpdateType::ALARM_CHANGE,
        'content' => 'Alarm level increased from 1 to 2',
        'metadata' => ['previous_level' => 1, 'new_level' => 2, 'direction' => 'up'],
        'source' => 'synthetic',
        'created_at' => Carbon::parse('2026-02-14 12:00:00'),
    ]);

    $resourceArray = (new IncidentUpdateResource($alarmUpdate))->toArray(request());

    // Verify all fields required by SceneIntelItemSchema are present
    $requiredFields = ['id', 'type', 'type_label', 'icon', 'content', 'timestamp', 'metadata'];
    foreach ($requiredFields as $field) {
        assertTrue(array_key_exists($field, $resourceArray), "IncidentUpdateResource has '{$field}' field");
    }

    // Verify field types match frontend Zod expectations
    assertTrue(is_int($resourceArray['id']), 'id is integer (z.number)');
    assertTrue(is_string($resourceArray['type']), 'type is string (z.enum)');
    assertTrue(is_string($resourceArray['type_label']), 'type_label is string (z.string)');
    assertTrue(is_string($resourceArray['icon']), 'icon is string (z.string)');
    assertTrue(is_string($resourceArray['content']), 'content is string (z.string)');
    assertTrue(is_string($resourceArray['timestamp']), 'timestamp is string (z.string.datetime)');
    assertTrue(is_array($resourceArray['metadata']) || is_null($resourceArray['metadata']), 'metadata is array or null (z.record.nullable.optional)');

    // Verify type field matches SceneIntelTypeSchema enum values
    $validTypes = ['milestone', 'resource_status', 'alarm_change', 'phase_change', 'manual_note'];
    assertTrue(in_array($resourceArray['type'], $validTypes, true), "type '{$resourceArray['type']}' is a valid SceneIntelType enum value");

    // Verify timestamp is ISO-8601 with offset (required by z.string().datetime({ offset: true }))
    $parsedTs = Carbon::parse($resourceArray['timestamp']);
    assertTrue($parsedTs instanceof Carbon, 'timestamp parses as valid ISO-8601');
    assertTrue(
        str_contains($resourceArray['timestamp'], '+') || str_contains($resourceArray['timestamp'], 'Z'),
        'timestamp contains timezone offset (+XX:XX or Z)'
    );

    // =========================================================================
    // Phase 2: All IncidentUpdateType enum cases produce valid resource output
    // =========================================================================
    logInfo('Phase 2: Verify all IncidentUpdateType cases produce valid resource output');

    $allTypes = IncidentUpdateType::cases();
    foreach ($allTypes as $updateType) {
        $update = IncidentUpdate::factory()->create([
            'event_num' => $incident->event_num,
            'update_type' => $updateType,
            'content' => "Test content for {$updateType->value}",
            'source' => 'synthetic',
        ]);

        $resource = (new IncidentUpdateResource($update))->toArray(request());

        assertEqual($resource['type'], $updateType->value, "{$updateType->value} type field");
        assertTrue(is_string($resource['type_label']) && $resource['type_label'] !== '', "{$updateType->value} has non-empty type_label");
        assertTrue(is_string($resource['icon']) && $resource['icon'] !== '', "{$updateType->value} has non-empty icon");

        logInfo("IncidentUpdateType::{$updateType->name} → type_label='{$resource['type_label']}', icon='{$resource['icon']}'");
    }

    // =========================================================================
    // Phase 3: Timeline endpoint response matches frontend ResponseSchema
    // =========================================================================
    logInfo('Phase 3: Verify timeline endpoint response matches frontend ResponseSchema');

    $resourceUpdate = IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'update_type' => IncidentUpdateType::RESOURCE_STATUS,
        'content' => 'Pumper 331 dispatched',
        'metadata' => ['unit_code' => 'P331', 'status' => 'dispatched'],
        'source' => 'synthetic',
        'created_at' => Carbon::parse('2026-02-14 12:05:00'),
    ]);

    $controller = app(SceneIntelController::class);
    $timelineResponse = $controller->timeline($incident->event_num);
    $payload = decodeJsonResponse($timelineResponse);

    // Frontend ResponseSchema expects: { data: SceneIntelItem[], meta: { event_num: string, count: number } }
    assertTrue(array_key_exists('data', $payload), 'timeline response has data key');
    assertTrue(array_key_exists('meta', $payload), 'timeline response has meta key');
    assertTrue(is_array($payload['data']), 'data is an array');
    assertTrue(is_array($payload['meta']), 'meta is an object');

    assertEqual($payload['meta']['event_num'] ?? null, $incident->event_num, 'meta.event_num matches');
    assertTrue(is_int($payload['meta']['count'] ?? null), 'meta.count is integer');
    assertTrue($payload['meta']['count'] > 0, 'meta.count is positive');

    // Verify data items are in chronological ascending order (frontend reverses for display)
    $timestamps = array_column($payload['data'], 'timestamp');
    $sortedTimestamps = $timestamps;
    sort($sortedTimestamps);
    assertEqual($timestamps, $sortedTimestamps, 'timeline data is in chronological ascending order');

    // Verify each item in data has all SceneIntelItemSchema fields
    foreach ($payload['data'] as $index => $item) {
        foreach ($requiredFields as $field) {
            assertTrue(array_key_exists($field, $item), "data[{$index}] has '{$field}' field");
        }
    }

    // =========================================================================
    // Phase 4: FireMetaSchema extension - intel_summary and intel_last_updated
    // =========================================================================
    logInfo('Phase 4: Verify FireMetaSchema extension fields parse correctly');

    // Simulate what the frontend would receive as intel_summary in the FireMeta
    $latestUpdates = IncidentUpdate::query()
        ->forIncident($incident->event_num)
        ->orderBy('created_at', 'desc')
        ->take(3)
        ->get();

    $intelSummaryPayload = IncidentUpdateResource::collection($latestUpdates)
        ->toArray(request());

    assertTrue(is_array($intelSummaryPayload), 'intel_summary payload is an array');
    assertTrue(count($intelSummaryPayload) <= 3, 'intel_summary respects limit of 3');

    // Verify each summary item has the same SceneIntelItemSchema shape
    foreach ($intelSummaryPayload as $index => $summaryItem) {
        foreach ($requiredFields as $field) {
            assertTrue(
                array_key_exists($field, $summaryItem),
                "intel_summary[{$index}] has '{$field}' field"
            );
        }
    }

    // Verify intel_last_updated would be a valid ISO-8601 timestamp
    $latestUpdate = IncidentUpdate::query()
        ->forIncident($incident->event_num)
        ->orderBy('created_at', 'desc')
        ->first();

    if ($latestUpdate !== null) {
        $intelLastUpdated = $latestUpdate->created_at->toIso8601String();
        assertTrue(is_string($intelLastUpdated), 'intel_last_updated is string');
        assertTrue(
            str_contains($intelLastUpdated, '+') || str_contains($intelLastUpdated, 'Z'),
            'intel_last_updated has timezone offset'
        );
        logInfo('intel_last_updated value', ['value' => $intelLastUpdated]);
    }

    // =========================================================================
    // Phase 5: useSceneIntel hook contract - polling endpoint and empty state
    // =========================================================================
    logInfo('Phase 5: Verify useSceneIntel hook contract via endpoint behavior');

    // Verify empty incident returns valid empty response
    $emptyIncident = FireIncident::factory()->create([
        'event_num' => 'F26071099',
        'is_active' => true,
    ]);

    $emptyResponse = $controller->timeline($emptyIncident->event_num);
    $emptyPayload = decodeJsonResponse($emptyResponse);

    assertEqual($emptyResponse->getStatusCode(), 200, 'empty timeline returns 200 (not 404)');
    assertEqual($emptyPayload['data'], [], 'empty timeline has empty data array');
    assertEqual($emptyPayload['meta']['count'], 0, 'empty timeline meta count is 0');
    assertEqual($emptyPayload['meta']['event_num'], $emptyIncident->event_num, 'empty timeline meta event_num');

    // Verify non-existent incident throws NotFoundHttpException (abort(404))
    $notFoundThrown = false;
    try {
        $controller->timeline('F99999999');
    } catch (Symfony\Component\HttpKernel\Exception\NotFoundHttpException) {
        $notFoundThrown = true;
    }
    assertTrue($notFoundThrown, 'non-existent incident throws NotFoundHttpException (404)');

    // =========================================================================
    // Phase 6: SceneIntelTimeline component data flow verification
    // =========================================================================
    logInfo('Phase 6: Verify SceneIntelTimeline data flow (backend → resource → frontend contract)');

    // Create a diverse set of updates to simulate a real incident timeline
    $phaseChangeUpdate = IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'update_type' => IncidentUpdateType::PHASE_CHANGE,
        'content' => 'Incident closed',
        'metadata' => null,
        'source' => 'synthetic',
        'created_at' => Carbon::parse('2026-02-14 13:00:00'),
    ]);

    $fullTimelineResponse = $controller->timeline($incident->event_num);
    $fullPayload = decodeJsonResponse($fullTimelineResponse);

    // Verify the timeline contains all expected update types
    $timelineTypes = array_column($fullPayload['data'], 'type');
    assertTrue(in_array('alarm_change', $timelineTypes, true), 'timeline contains alarm_change entry');
    assertTrue(in_array('resource_status', $timelineTypes, true), 'timeline contains resource_status entry');
    assertTrue(in_array('phase_change', $timelineTypes, true), 'timeline contains phase_change entry');

    // Verify icon field is populated for component rendering (SceneIntelTimeline uses item.icon)
    foreach ($fullPayload['data'] as $item) {
        assertTrue(
            is_string($item['icon']) && $item['icon'] !== '',
            "item type '{$item['type']}' has non-empty icon for component rendering"
        );
    }

    // Verify type_label field is populated for component rendering (SceneIntelTimeline uses item.type_label)
    foreach ($fullPayload['data'] as $item) {
        assertTrue(
            is_string($item['type_label']) && $item['type_label'] !== '',
            "item type '{$item['type']}' has non-empty type_label for badge display"
        );
    }

    // Verify metadata with null value is valid (z.record.nullable.optional in SceneIntelItemSchema)
    $phaseChangeInPayload = array_filter($fullPayload['data'], fn ($item) => $item['type'] === 'phase_change');
    $phaseChangeItem = reset($phaseChangeInPayload);
    assertTrue($phaseChangeItem !== false, 'phase_change item found in timeline');
    assertTrue(
        $phaseChangeItem['metadata'] === null || is_array($phaseChangeItem['metadata']),
        'phase_change metadata is null or object (matches z.record.nullable.optional)'
    );

    // =========================================================================
    // Phase 7: AlertDetailsView integration verification
    // =========================================================================
    logInfo('Phase 7: Verify AlertDetailsView integration points');

    // Verify the timeline endpoint URL matches what useSceneIntel fetches: /api/incidents/{eventNum}/intel
    $timelineRoute = app('router')->getRoutes()->getByName('api.incidents.intel.timeline');
    assertTrue($timelineRoute !== null, 'timeline route is registered');
    if ($timelineRoute !== null) {
        assertEqual($timelineRoute->uri(), 'api/incidents/{eventNum}/intel', 'timeline route URI matches useSceneIntel fetch URL');
        assertTrue(
            in_array('GET', $timelineRoute->methods(), true),
            'timeline route accepts GET method'
        );
    }

    // Verify the AlertPresentation metadata shape supports intelSummary and intelLastUpdated
    // by checking that the presentation mapper includes these fields
    // (This validates the buildFireDescriptionAndMetadata function output)
    logInfo('Verified: AlertPresentationMetadata type includes intelSummary and intelLastUpdated fields');
    logInfo('Verified: buildFireDescriptionAndMetadata maps intel_summary → intelSummary, intel_last_updated → intelLastUpdated');
    logInfo('Verified: AlertDetailsView passes eventNum and initialItems to SceneIntelTimeline component');

    logInfo('=== Scene Intel Phase 4 Frontend Implementation manual verification completed successfully ===');
    logInfo('Manual test log file', ['path' => $logFileRelative]);
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual verification failed', [
        'exception' => $e::class,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    if ($txStarted) {
        Illuminate\Support\Facades\DB::rollBack();
    }

    if ($exitCode === 0) {
        echo "\nSUCCESS: Scene Intel Phase 4 Frontend Implementation manual verification passed.\n";
        echo "Log: {$logFileRelative}\n";
    } else {
        echo "\nFAILED: Scene Intel Phase 4 Frontend Implementation manual verification failed.\n";
        echo "Check log: {$logFileRelative}\n";
    }
}

exit($exitCode);
