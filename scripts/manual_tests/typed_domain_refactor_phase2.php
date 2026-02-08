<?php

/**
 * Manual Test Script for Unified Alert Contract (Phase 2: Typed Domain Refactor)
 * Generated: 2026-02-07
 *
 * Purpose:
 * - Pull a page of unified alerts via UnifiedAlertsQuery + UnifiedAlertResource.
 * - Validate the JSON contract (shape + source-specific meta typing) expected by the
 *   frontend typed domain layer (Zod schemas / discriminated unions).
 * - Seed minimal sample records if a source has zero rows (and optionally clean them up).
 *
 * Usage (local PHP):
 *   php scripts/manual_tests/typed_domain_refactor_phase2.php
 *
 * Usage (Sail):
 *   ./vendor/bin/sail php scripts/manual_tests/typed_domain_refactor_phase2.php
 *
 * Options:
 *   --no-induce Disable induced malformed rows.
 *   --commit    Commit seeded rows (default is rollback transaction).
 *   --keep      When used with --commit, keep seeded rows (no cleanup).
 *   --allow-non-testing Allow running outside APP_ENV=testing (dangerous).
 */

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Http\Resources\UnifiedAlertResource;
use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

$app = require_once dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(ConsoleKernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production.\n");
}

$allowNonTesting = in_array('--allow-non-testing', $argv, true);
$commit = in_array('--commit', $argv, true);
$keep = in_array('--keep', $argv, true);
$induceFailures = ! in_array('--no-induce', $argv, true);

if (! app()->environment('testing') && ! $allowNonTesting) {
    exit(
        "Error: This script must be run with APP_ENV=testing to avoid touching the development DB.\n".
        "Run: APP_ENV=testing php scripts/manual_tests/typed_domain_refactor_phase2.php\n".
        "Or (dangerous): php scripts/manual_tests/typed_domain_refactor_phase2.php --allow-non-testing\n"
    );
}

$testRunId = 'typed_domain_refactor_phase2_'.Carbon::now()->format('Y_m_d_His');
$logFile = storage_path("logs/manual_tests/{$testRunId}.log");

if (! is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

function logInfo(string $message, array $context = []): void
{
    Log::channel('manual_test')->info($message, $context);
    echo "[INFO] {$message}\n";
}

function logError(string $message, array $context = []): void
{
    Log::channel('manual_test')->error($message, $context);
    echo "[ERROR] {$message}\n";
}

/**
 * @param  array<string, mixed>  $resource
 * @return array<int, string> List of validation errors (empty when valid).
 */
function validateUnifiedAlertResource(array $resource): array
{
    $errors = [];

    $isString = static fn ($value): bool => is_string($value) && $value !== '';
    $isBool = static fn ($value): bool => is_bool($value);
    $isNumber = static fn ($value): bool => is_int($value) || is_float($value);

    if (! array_key_exists('id', $resource) || ! $isString($resource['id'] ?? null)) {
        $errors[] = 'id must be a non-empty string';
    }

    $source = $resource['source'] ?? null;
    $allowedSources = ['fire', 'police', 'transit', 'go_transit'];
    if (! is_string($source) || ! in_array($source, $allowedSources, true)) {
        $errors[] = 'source must be one of: '.implode(', ', $allowedSources);
    }

    if (! array_key_exists('external_id', $resource) || ! $isString($resource['external_id'] ?? null)) {
        $errors[] = 'external_id must be a non-empty string';
    }

    if (! array_key_exists('is_active', $resource) || ! $isBool($resource['is_active'] ?? null)) {
        $errors[] = 'is_active must be a boolean';
    }

    if (! array_key_exists('timestamp', $resource) || ! is_string($resource['timestamp'] ?? null)) {
        $errors[] = 'timestamp must be a string (ISO 8601)';
    }

    if (! array_key_exists('title', $resource) || ! is_string($resource['title'] ?? null)) {
        $errors[] = 'title must be a string';
    }

    $location = $resource['location'] ?? null;
    if (! is_null($location)) {
        if (! is_array($location)) {
            $errors[] = 'location must be null or an object';
        } else {
            foreach (['name', 'lat', 'lng'] as $key) {
                if (! array_key_exists($key, $location)) {
                    $errors[] = "location.{$key} is required (nullable)";
                }
            }

            if (array_key_exists('name', $location) && ! (is_null($location['name']) || is_string($location['name']))) {
                $errors[] = 'location.name must be null or string';
            }
            if (array_key_exists('lat', $location) && ! (is_null($location['lat']) || $isNumber($location['lat']))) {
                $errors[] = 'location.lat must be null or number';
            }
            if (array_key_exists('lng', $location) && ! (is_null($location['lng']) || $isNumber($location['lng']))) {
                $errors[] = 'location.lng must be null or number';
            }
        }
    }

    $meta = $resource['meta'] ?? null;
    if (! is_array($meta)) {
        $errors[] = 'meta must be an object (associative array)';

        return $errors;
    }

    foreach (array_keys($meta) as $metaKey) {
        if (! is_string($metaKey)) {
            $errors[] = 'meta keys must be strings';
            break;
        }
    }

    if (! is_string($source)) {
        return $errors;
    }

    if ($source === 'fire') {
        if (! array_key_exists('alarm_level', $meta) || ! $isNumber($meta['alarm_level'])) {
            $errors[] = 'fire.meta.alarm_level must be a number';
        }
        if (! array_key_exists('event_num', $meta) || ! is_string($meta['event_num'])) {
            $errors[] = 'fire.meta.event_num must be a string';
        }
        if (array_key_exists('units_dispatched', $meta) && ! (is_null($meta['units_dispatched']) || is_string($meta['units_dispatched']))) {
            $errors[] = 'fire.meta.units_dispatched must be null or string';
        }
        if (array_key_exists('beat', $meta) && ! (is_null($meta['beat']) || is_string($meta['beat']))) {
            $errors[] = 'fire.meta.beat must be null or string';
        }
    } elseif ($source === 'police') {
        if (! array_key_exists('object_id', $meta) || ! $isNumber($meta['object_id'])) {
            $errors[] = 'police.meta.object_id must be a number';
        }
        if (array_key_exists('division', $meta) && ! (is_null($meta['division']) || is_string($meta['division']))) {
            $errors[] = 'police.meta.division must be null or string';
        }
        if (array_key_exists('call_type_code', $meta) && ! (is_null($meta['call_type_code']) || is_string($meta['call_type_code']))) {
            $errors[] = 'police.meta.call_type_code must be null or string';
        }
    } elseif ($source === 'transit') {
        $nullableStrings = [
            'route_type',
            'route',
            'severity',
            'effect',
            'source_feed',
            'alert_type',
            'description',
            'url',
            'direction',
            'cause',
            'stop_start',
            'stop_end',
        ];

        foreach ($nullableStrings as $key) {
            if (array_key_exists($key, $meta) && ! (is_null($meta[$key]) || is_string($meta[$key]))) {
                $errors[] = "transit.meta.{$key} must be null or string";
            }
        }
    } elseif ($source === 'go_transit') {
        $nullableStrings = [
            'alert_type',
            'service_mode',
            'sub_category',
            'corridor_code',
            'direction',
            'trip_number',
            'delay_duration',
            'line_colour',
            'message_body',
        ];

        foreach ($nullableStrings as $key) {
            if (array_key_exists($key, $meta) && ! (is_null($meta[$key]) || is_string($meta[$key]))) {
                $errors[] = "go_transit.meta.{$key} must be null or string";
            }
        }
    }

    return $errors;
}

logInfo("=== Manual Test Run Started: {$testRunId} ===");
logInfo('Log file', ['path' => $logFile]);
logInfo('Environment', [
    'app_env' => app()->environment(),
    'db_connection' => config('database.default'),
    'db_driver' => config('database.connections.'.config('database.default').'.driver'),
    'db_database' => config('database.connections.'.config('database.default').'.database'),
    'commit' => $commit,
    'induce_failures' => $induceFailures,
]);

$createdRecords = [
    'fire_incidents' => [],
    'police_calls' => [],
    'transit_alerts' => [],
    'go_transit_alerts' => [],
];

try {
    DB::beginTransaction();

    logInfo('Phase 1: Data Setup');

    $requiredTables = [
        'fire_incidents',
        'police_calls',
        'transit_alerts',
        'go_transit_alerts',
    ];

    $missingTables = array_values(array_filter(
        $requiredTables,
        static fn (string $table): bool => ! Schema::hasTable($table),
    ));

    if ($missingTables !== []) {
        logInfo('Missing tables detected; running migrations', ['tables' => $missingTables]);
        Artisan::call('migrate', ['--force' => true]);
        logInfo('Migrations complete');
    }

    if (FireIncident::query()->count() === 0) {
        $incident = FireIncident::factory()->create();
        $createdRecords['fire_incidents'][] = $incident->getKey();
        logInfo('Seeded FireIncident (factory)', ['id' => $incident->getKey(), 'event_num' => $incident->event_num]);
    }

    if (PoliceCall::query()->count() === 0) {
        $call = PoliceCall::factory()->create();
        $createdRecords['police_calls'][] = $call->getKey();
        logInfo('Seeded PoliceCall (factory)', ['id' => $call->getKey(), 'object_id' => $call->object_id]);
    }

    if (TransitAlert::query()->count() === 0) {
        $alert = TransitAlert::factory()->create();
        $createdRecords['transit_alerts'][] = $alert->getKey();
        logInfo('Seeded TransitAlert (factory)', ['id' => $alert->getKey(), 'external_id' => $alert->external_id]);
    }

    if (GoTransitAlert::query()->count() === 0) {
        $alert = GoTransitAlert::factory()->create();
        $createdRecords['go_transit_alerts'][] = $alert->getKey();
        logInfo('Seeded GoTransitAlert (factory)', ['id' => $alert->getKey(), 'external_id' => $alert->external_id]);
    }

    $induced = [
        'fire_event_num' => null,
        'police_object_id' => null,
    ];

    if ($induceFailures) {
        $driver = config('database.connections.'.config('database.default').'.driver');
        if ($driver !== 'sqlite') {
            logInfo('Induced failure rows skipped (requires sqlite dynamic typing)', ['db_driver' => $driver]);
        } else {
            $inducedFireEventNum = "INDUCED_FIRE_{$testRunId}";
            DB::table('fire_incidents')->insert([
                'event_num' => $inducedFireEventNum,
                'event_type' => 'STRUCTURE FIRE',
                'prime_street' => 'MAIN ST',
                'cross_streets' => 'CROSS RD',
                'dispatch_time' => Carbon::now()->subMinutes(3)->toDateTimeString(),
                'alarm_level' => 'NOT_A_NUMBER',
                'beat' => null,
                'units_dispatched' => null,
                'is_active' => 1,
                'feed_updated_at' => Carbon::now()->toDateTimeString(),
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
            $induced['fire_event_num'] = $inducedFireEventNum;
            logInfo('Induced malformed FireIncident (sqlite)', ['event_num' => $inducedFireEventNum]);

            $inducedPoliceObjectId = "INDUCED_OBJ_{$testRunId}";
            DB::table('police_calls')->insert([
                'object_id' => $inducedPoliceObjectId,
                'call_type_code' => 'ASLTPR',
                'call_type' => 'ASSAULT IN PROGRESS',
                'division' => 'D31',
                'cross_streets' => '456 POLICE RD',
                'latitude' => 43.7,
                'longitude' => -79.4,
                'occurrence_time' => Carbon::now()->subMinutes(4)->toDateTimeString(),
                'is_active' => 1,
                'feed_updated_at' => Carbon::now()->toDateTimeString(),
                'created_at' => Carbon::now()->toDateTimeString(),
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
            $induced['police_object_id'] = $inducedPoliceObjectId;
            logInfo('Induced malformed PoliceCall (sqlite)', ['object_id' => $inducedPoliceObjectId]);
        }
    }

    logInfo('Data setup completed');

    logInfo('Phase 2: Contract Validation');
    /** @var UnifiedAlertsQuery $query */
    $query = app(UnifiedAlertsQuery::class);

    $criteria = new UnifiedAlertsCriteria(status: 'all', perPage: 50, page: 1);
    $paginator = $query->paginate($criteria);

    $resources = [];
    foreach ($paginator->items() as $dto) {
        $resources[] = (new UnifiedAlertResource($dto))->toArray(request());
    }

    $summary = [
        'total' => count($resources),
        'by_source' => ['fire' => 0, 'police' => 0, 'transit' => 0, 'go_transit' => 0],
        'invalid' => 0,
    ];

    $invalidExamples = [];
    $inducedMatched = [
        'fire' => false,
        'police' => false,
    ];

    foreach ($resources as $resource) {
        if (isset($resource['source']) && is_string($resource['source']) && isset($summary['by_source'][$resource['source']])) {
            $summary['by_source'][$resource['source']]++;
        }

        $errors = validateUnifiedAlertResource($resource);
        if ($errors !== []) {
            $summary['invalid']++;
            if (($resource['source'] ?? null) === 'fire' && is_string($induced['fire_event_num'] ?? null)) {
                if (($resource['external_id'] ?? null) === $induced['fire_event_num']) {
                    $inducedMatched['fire'] = true;
                }
            }
            if (($resource['source'] ?? null) === 'police' && is_string($induced['police_object_id'] ?? null)) {
                if (($resource['external_id'] ?? null) === $induced['police_object_id']) {
                    $inducedMatched['police'] = true;
                }
            }
            $invalidExamples[] = [
                'id' => $resource['id'] ?? null,
                'source' => $resource['source'] ?? null,
                'errors' => $errors,
            ];
        }
    }

    logInfo('Contract validation summary', $summary);

    if ($induceFailures) {
        if ($summary['invalid'] < 1) {
            logError('Expected at least one invalid resource from induced failures, but found none.');
        } else {
            logInfo('Induced failures produced invalid resources as expected', ['matched' => $inducedMatched]);
        }
    }

    if ($invalidExamples !== [] && ! $induceFailures) {
        logError('Found invalid resources (unexpected)', ['examples' => $invalidExamples]);
    } elseif ($invalidExamples === []) {
        logInfo('All resources match the expected contract.');
    }

    logInfo('Phase 2 Manual Checklist');
    logInfo('1) Start the app (Sail): ./vendor/bin/sail up -d');
    logInfo('2) Start dev servers: composer dev');
    logInfo('3) Open http://localhost (Home route -> GTA Alerts)');
    logInfo("4) Open browser console: expect no '[DomainAlert] Invalid ...' warnings during normal rendering");
    logInfo('5) Confirm feed renders (and filters/search still work)');
} catch (Throwable $e) {
    logError('Manual test failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
    throw $e;
} finally {
    logInfo('Phase 3: Data Cleanup');

    if ($commit) {
        DB::commit();
        logInfo('Committed transaction (--commit)');

        if (! $keep) {
            foreach (array_reverse($createdRecords['go_transit_alerts']) as $id) {
                GoTransitAlert::query()->whereKey($id)->delete();
            }
            foreach (array_reverse($createdRecords['transit_alerts']) as $id) {
                TransitAlert::query()->whereKey($id)->delete();
            }
            foreach (array_reverse($createdRecords['police_calls']) as $id) {
                PoliceCall::query()->whereKey($id)->delete();
            }
            foreach (array_reverse($createdRecords['fire_incidents']) as $id) {
                FireIncident::query()->whereKey($id)->delete();
            }

            logInfo('Cleanup completed (committed run)', $createdRecords);
        } else {
            logInfo('--keep set; skipping cleanup for committed run');
        }
    } else {
        DB::rollBack();
        logInfo('Rolled back transaction (default)');
    }

    logInfo("=== Manual Test Run Completed: {$testRunId} ===");
    logInfo('Full logs available', ['path' => $logFile]);
    echo "\n✓ Done. Logs: {$logFile}\n";
}
