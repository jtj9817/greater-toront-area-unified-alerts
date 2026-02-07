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
 *   --keep      Do not delete any seeded sample records.
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
use Illuminate\Support\Facades\Log;

$app = require_once dirname(__DIR__, 2).'/bootstrap/app.php';
$kernel = $app->make(ConsoleKernel::class);
$kernel->bootstrap();

if (app()->environment('production')) {
    die("Error: Cannot run manual tests in production.\n");
}

$keep = in_array('--keep', $argv, true);

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

$createdRecords = [
    'fire_incidents' => [],
    'police_calls' => [],
    'transit_alerts' => [],
    'go_transit_alerts' => [],
];

try {
    logInfo('Phase 1: Data Setup');

    if (FireIncident::query()->count() === 0) {
        $eventNum = "MANUAL_FIRE_{$testRunId}";
        $incident = FireIncident::query()->create([
            'event_num' => $eventNum,
            'event_type' => 'STRUCTURE FIRE',
            'prime_street' => 'MAIN ST',
            'cross_streets' => 'CROSS RD',
            'dispatch_time' => Carbon::now()->subMinutes(5),
            'alarm_level' => 2,
            'beat' => 'B1',
            'units_dispatched' => 'P1, P2',
            'is_active' => true,
            'feed_updated_at' => Carbon::now(),
        ]);
        $createdRecords['fire_incidents'][] = $incident->getKey();
        logInfo('Seeded FireIncident', ['id' => $incident->getKey(), 'event_num' => $eventNum]);
    }

    if (PoliceCall::query()->count() === 0) {
        $objectId = (int) (Carbon::now()->format('His').'01');
        $call = PoliceCall::query()->create([
            'object_id' => $objectId,
            'call_type_code' => 'ASLTPR',
            'call_type' => 'ASSAULT IN PROGRESS',
            'division' => 'D31',
            'cross_streets' => '456 POLICE RD',
            'latitude' => 43.7,
            'longitude' => -79.4,
            'occurrence_time' => Carbon::now()->subMinutes(8),
            'is_active' => true,
            'feed_updated_at' => Carbon::now(),
        ]);
        $createdRecords['police_calls'][] = $call->getKey();
        logInfo('Seeded PoliceCall', ['id' => $call->getKey(), 'object_id' => $objectId]);
    }

    if (TransitAlert::query()->count() === 0) {
        $externalId = "MANUAL_TTC_{$testRunId}";
        $alert = TransitAlert::query()->create([
            'external_id' => $externalId,
            'source_feed' => 'manual',
            'alert_type' => 'advisory',
            'route_type' => 'Subway',
            'route' => '1',
            'title' => 'Line 1 delay',
            'description' => 'Signal issues. Shuttle buses operating.',
            'severity' => 'Critical',
            'effect' => 'REDUCED_SERVICE',
            'cause' => null,
            'active_period_start' => Carbon::now()->subMinutes(12),
            'active_period_end' => null,
            'direction' => 'Both Ways',
            'stop_start' => 'St Clair',
            'stop_end' => 'Lawrence',
            'url' => null,
            'is_active' => true,
            'feed_updated_at' => Carbon::now(),
        ]);
        $createdRecords['transit_alerts'][] = $alert->getKey();
        logInfo('Seeded TransitAlert', ['id' => $alert->getKey(), 'external_id' => $externalId]);
    }

    if (GoTransitAlert::query()->count() === 0) {
        $externalId = "MANUAL_GO_{$testRunId}";
        $alert = GoTransitAlert::query()->create([
            'external_id' => $externalId,
            'alert_type' => 'saag',
            'service_mode' => 'Train',
            'corridor_or_route' => 'Lakeshore East',
            'corridor_code' => 'LE',
            'sub_category' => 'TDELAY',
            'message_subject' => 'Lakeshore East delay',
            'message_body' => 'Minor delays due to track congestion.',
            'direction' => 'Eastbound',
            'trip_number' => null,
            'delay_duration' => '00:15:00',
            'status' => null,
            'line_colour' => null,
            'posted_at' => Carbon::now()->subMinutes(15),
            'is_active' => true,
            'feed_updated_at' => Carbon::now(),
        ]);
        $createdRecords['go_transit_alerts'][] = $alert->getKey();
        logInfo('Seeded GoTransitAlert', ['id' => $alert->getKey(), 'external_id' => $externalId]);
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
    foreach ($resources as $resource) {
        if (isset($resource['source']) && is_string($resource['source']) && isset($summary['by_source'][$resource['source']])) {
            $summary['by_source'][$resource['source']]++;
        }

        $errors = validateUnifiedAlertResource($resource);
        if ($errors !== []) {
            $summary['invalid']++;
            $invalidExamples[] = [
                'id' => $resource['id'] ?? null,
                'source' => $resource['source'] ?? null,
                'errors' => $errors,
            ];
        }
    }

    logInfo('Contract validation summary', $summary);

    if ($invalidExamples !== []) {
        logError('Found invalid resources (unexpected)', ['examples' => $invalidExamples]);
    } else {
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

    if ($keep) {
        logInfo('--keep set; skipping cleanup');
    } else {
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

        logInfo('Cleanup completed', $createdRecords);
    }

    logInfo("=== Manual Test Run Completed: {$testRunId} ===");
    logInfo('Full logs available', ['path' => $logFile]);
    echo "\n✓ Done. Logs: {$logFile}\n";
}

