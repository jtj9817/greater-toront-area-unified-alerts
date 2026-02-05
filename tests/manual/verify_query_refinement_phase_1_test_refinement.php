<?php

/**
 * Manual Test: Query Refinement 20260203 - Phase 1 Test Refinement
 * Generated: 2026-02-04
 * Purpose: Verify UnifiedAlertsQuery invariants and mapping edge cases introduced/defined in Phase 1:
 * - Deterministic ordering + per-page ID uniqueness
 * - Status invariants (`active` => all isActive=true, `cleared` => all isActive=false)
 * - Meta decoding edge cases (null/empty/invalid/valid) never leak exceptions
 * - Location edge cases (coords-only + zero coords)
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

umask(002);

use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Services\Alerts\Contracts\AlertSelectProvider;
use App\Services\Alerts\DTOs\UnifiedAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Database\Seeders\UnifiedAlertsTestSeeder;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

$testRunId = 'query_refinement_phase_1_test_refinement_'.Carbon::now()->format('Y_m_d_His');
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

function assertTrue(bool $condition, string $label, array $ctx = []): void
{
    if (! $condition) {
        $message = "Assertion failed: {$label}.";
        logError($message, $ctx);
        throw new \RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
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

/**
 * @param  array<int, UnifiedAlert>  $items
 */
function assertOrderedByDeterministicTuple(array $items): void
{
    for ($index = 1; $index < count($items); $index++) {
        $previous = $items[$index - 1];
        $current = $items[$index];

        $previousTimestamp = $previous->timestamp->getTimestamp();
        $currentTimestamp = $current->timestamp->getTimestamp();

        if ($previousTimestamp !== $currentTimestamp) {
            assertTrue($previousTimestamp >= $currentTimestamp, 'ordering: timestamp desc', [
                'previous' => $previous->id,
                'current' => $current->id,
            ]);

            continue;
        }

        if ($previous->source !== $current->source) {
            assertTrue(strcmp($previous->source, $current->source) <= 0, 'ordering: source asc', [
                'previous' => $previous->id,
                'current' => $current->id,
            ]);

            continue;
        }

        assertTrue(strcmp($previous->externalId, $current->externalId) >= 0, 'ordering: external_id desc', [
            'previous' => $previous->id,
            'current' => $current->id,
        ]);
    }

    logInfo('Ordering invariant holds for deterministic tuple.');
}

/**
 * @param  array<int, UnifiedAlert>  $items
 */
function assertPerPageIdentifiersValid(array $items): void
{
    $ids = [];

    foreach ($items as $item) {
        assertTrue($item->id !== '', 'identifier: id non-empty', ['id' => $item->id]);
        assertTrue($item->source !== '', 'identifier: source non-empty', ['id' => $item->id]);
        assertTrue($item->externalId !== '', 'identifier: externalId non-empty', ['id' => $item->id]);

        $ids[] = $item->id;
    }

    assertEqual(count($ids), count(array_unique($ids)), 'identifier: ids unique per page');
}

function emptyUnifiedSelect(string $source): Builder
{
    return DB::query()
        ->selectRaw(
            "NULL as id,\n            ? as source,\n            NULL as external_id,\n            0 as is_active,\n            NULL as timestamp,\n            NULL as title,\n            NULL as location_name,\n            NULL as lat,\n            NULL as lng,\n            NULL as meta",
            [$source]
        )
        ->whereRaw('1 = 0');
}

function singleRowUnifiedSelect(array $overrides = []): Builder
{
    $defaults = [
        'id' => 'fire:1',
        'source' => 'fire',
        'external_id' => '1',
        'is_active' => 1,
        'timestamp' => '2026-02-02 12:00:00',
        'title' => 'TEST',
        'location_name' => null,
        'lat' => null,
        'lng' => null,
        'meta' => null,
    ];

    $data = array_merge($defaults, $overrides);

    return DB::query()->selectRaw(
        "? as id,\n        ? as source,\n        ? as external_id,\n        ? as is_active,\n        ? as timestamp,\n        ? as title,\n        ? as location_name,\n        ? as lat,\n        ? as lng,\n        ? as meta",
        [
            $data['id'],
            $data['source'],
            $data['external_id'],
            $data['is_active'],
            $data['timestamp'],
            $data['title'],
            $data['location_name'],
            $data['lat'],
            $data['lng'],
            $data['meta'],
        ],
    );
}

function assertThrows(string $label, callable $fn, string $expectedClass): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        assertTrue(is_a($e, $expectedClass), "{$label}: throws {$expectedClass}", [
            'actual_class' => $e::class,
            'message' => $e->getMessage(),
        ]);

        return;
    }

    throw new \RuntimeException("Assertion failed: {$label} expected exception {$expectedClass} but none was thrown.");
}

$exitCode = 0;
$txStarted = false;

try {
    // Manual scripts are usually executed via Sail (MySQL host = "mysql").
    // Provide a clearer error if Docker/Sail isn't running.
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        throw new \RuntimeException(
            'Database connection failed. If you are using Sail, ensure Docker is running and execute: ./vendor/bin/sail php tests/manual/verify_query_refinement_phase_1_test_refinement.php',
            previous: $e
        );
    }

    DB::beginTransaction();
    $txStarted = true;
    logInfo('=== Starting Manual Test: Query Refinement Phase 1 (Test Refinement) ===');

    logInfo('Step 1: Seeding deterministic dataset (UnifiedAlertsTestSeeder)');

    FireIncident::query()->delete();
    PoliceCall::query()->delete();

    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));
    Artisan::call('db:seed', ['--class' => UnifiedAlertsTestSeeder::class]);

    assertEqual(FireIncident::count(), 4, 'fire_incidents count');
    assertEqual(PoliceCall::count(), 4, 'police_calls count');

    logInfo('Step 2: Verifying ordering + identifier invariants (status=all)');
    $alerts = app(UnifiedAlertsQuery::class);
    $all = $alerts->paginate(new UnifiedAlertsCriteria(status: 'all', perPage: 50));

    assertEqual($all->total(), 8, 'unified total');
    assertEqual(count($all->items()), 8, 'unified items length');

    /** @var array<int, UnifiedAlert> $allItems */
    $allItems = $all->items();
    assertOrderedByDeterministicTuple($allItems);
    assertPerPageIdentifiersValid($allItems);

    $ids = collect($allItems)->map(fn (UnifiedAlert $a) => $a->id)->values()->all();
    logInfo('Unified IDs (status=all)', ['ids' => $ids]);

    logInfo('Step 3: Verifying status invariants');
    $active = $alerts->paginate(new UnifiedAlertsCriteria(status: 'active', perPage: 50));
    assertEqual($active->total(), 4, 'active total');
    assertTrue(collect($active->items())->every(fn (UnifiedAlert $a) => $a->isActive), 'active => all isActive=true');

    $cleared = $alerts->paginate(new UnifiedAlertsCriteria(status: 'cleared', perPage: 50));
    assertEqual($cleared->total(), 4, 'cleared total');
    assertTrue(collect($cleared->items())->every(fn (UnifiedAlert $a) => ! $a->isActive), 'cleared => all isActive=false');

    logInfo('Step 4: Verifying location edge cases (coords-only + zero coords)');
    $policeTimestamp = Carbon::parse('2026-02-02 12:31:00');

    PoliceCall::factory()->create([
        'object_id' => 778,
        'call_type' => 'THEFT',
        'cross_streets' => null,
        'latitude' => 43.6500,
        'longitude' => -79.3800,
        'occurrence_time' => $policeTimestamp,
        'is_active' => true,
    ]);

    PoliceCall::factory()->create([
        'object_id' => 779,
        'call_type' => 'THEFT',
        'cross_streets' => null,
        'latitude' => 0.0,
        'longitude' => 0.0,
        'occurrence_time' => $policeTimestamp->copy()->addSecond(),
        'is_active' => true,
    ]);

    $withLocationEdgeCases = $alerts->paginate(new UnifiedAlertsCriteria(status: 'all', perPage: 50));
    $byId = collect($withLocationEdgeCases->items())->keyBy(fn (UnifiedAlert $a) => $a->id);

    /** @var UnifiedAlert $coordsOnly */
    $coordsOnly = $byId->get('police:778');
    assertTrue($coordsOnly !== null, 'coords-only row is present');
    assertTrue($coordsOnly->location !== null, 'coords-only => location not null');
    assertEqual($coordsOnly->location?->name, null, 'coords-only => location.name is null');
    assertEqual($coordsOnly->location?->lat, 43.65, 'coords-only => location.lat float');
    assertEqual($coordsOnly->location?->lng, -79.38, 'coords-only => location.lng float');

    /** @var UnifiedAlert $zeroCoords */
    $zeroCoords = $byId->get('police:779');
    assertTrue($zeroCoords !== null, 'zero-coords row is present');
    assertTrue($zeroCoords->location !== null, 'zero-coords => location not null');
    assertEqual($zeroCoords->location?->name, null, 'zero-coords => location.name is null');
    assertEqual($zeroCoords->location?->lat, 0.0, 'zero-coords => location.lat is 0.0');
    assertEqual($zeroCoords->location?->lng, 0.0, 'zero-coords => location.lng is 0.0');

    logInfo('Step 5: Verifying meta decoding edge cases via controlled provider row');

    $metaCases = [
        'null meta' => [null, []],
        'empty meta string' => ['', []],
        'invalid json string' => ['{', []],
        'valid json object string' => ['{"k":1}', ['k' => 1]],
        'valid json scalar string' => ['"k"', []],
    ];

    foreach ($metaCases as $label => [$meta, $expected]) {
        $query = new UnifiedAlertsQuery(
            providers: [
                new class implements AlertSelectProvider
                {
                    public function select(): Builder
                    {
                        return emptyUnifiedSelect('fire');
                    }
                },
                new class implements AlertSelectProvider
                {
                    public function select(): Builder
                    {
                        return emptyUnifiedSelect('police');
                    }
                },
                new class($meta) implements AlertSelectProvider
                {
                    public function __construct(private readonly mixed $meta) {}

                    public function select(): Builder
                    {
                        return singleRowUnifiedSelect([
                            'id' => 'meta:1',
                            'source' => 'fire',
                            'external_id' => '1',
                            'timestamp' => '2026-02-02 12:00:00',
                            'meta' => $this->meta,
                        ]);
                    }
                },
            ],
            mapper: new UnifiedAlertMapper,
        );

        $results = $query->paginate(new UnifiedAlertsCriteria(status: 'all', perPage: 50));
        /** @var UnifiedAlert $first */
        $first = $results->items()[0];

        assertEqual($first->meta, $expected, "meta case: {$label}");
    }

    logInfo('Step 6: Verifying timestamp contract is fail-fast (missing/unparseable)');

    $missingTimestampQuery = new UnifiedAlertsQuery(
        providers: [
            new class implements AlertSelectProvider
            {
                public function select(): Builder
                {
                    return emptyUnifiedSelect('fire');
                }
            },
            new class implements AlertSelectProvider
            {
                public function select(): Builder
                {
                    return emptyUnifiedSelect('police');
                }
            },
            new class implements AlertSelectProvider
            {
                public function select(): Builder
                {
                    return singleRowUnifiedSelect([
                        'id' => 'ts:missing',
                        'source' => 'fire',
                        'external_id' => '1',
                        'timestamp' => null,
                    ]);
                }
            },
        ],
        mapper: new UnifiedAlertMapper,
    );

    assertThrows('timestamp missing', fn () => $missingTimestampQuery->paginate(new UnifiedAlertsCriteria(status: 'all', perPage: 50)), \InvalidArgumentException::class);

    $badTimestampQuery = new UnifiedAlertsQuery(
        providers: [
            new class implements AlertSelectProvider
            {
                public function select(): Builder
                {
                    return emptyUnifiedSelect('fire');
                }
            },
            new class implements AlertSelectProvider
            {
                public function select(): Builder
                {
                    return emptyUnifiedSelect('police');
                }
            },
            new class implements AlertSelectProvider
            {
                public function select(): Builder
                {
                    return singleRowUnifiedSelect([
                        'id' => 'ts:bad',
                        'source' => 'fire',
                        'external_id' => '1',
                        'timestamp' => 'not-a-timestamp',
                    ]);
                }
            },
        ],
        mapper: new UnifiedAlertMapper,
    );

    assertThrows('timestamp not parseable', fn () => $badTimestampQuery->paginate(new UnifiedAlertsCriteria(status: 'all', perPage: 50)), \InvalidArgumentException::class);

    logInfo('=== Manual Test Completed Successfully ===');
} catch (\Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    // Best-effort cleanup; avoid masking the original failure with a cleanup exception.
    try {
        Paginator::currentPageResolver(fn () => 1);
    } catch (\Throwable) {
    }

    try {
        Carbon::setTestNow();
    } catch (\Throwable) {
    }

    if ($txStarted) {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                logInfo('Transaction rolled back (Database preserved).');
            }
        } catch (\Throwable) {
            // Swallow rollback failures (e.g., DB unreachable) to preserve the original failure signal.
        }
    }

    logInfo('=== Test Run Finished ===');

    if ($exitCode === 0) {
        echo "\nResult: PASS\nLogs at: {$logFileRelative}\n";
    } else {
        echo "\nResult: FAIL\nLogs at: {$logFileRelative}\n";
    }

    exit($exitCode);
}
