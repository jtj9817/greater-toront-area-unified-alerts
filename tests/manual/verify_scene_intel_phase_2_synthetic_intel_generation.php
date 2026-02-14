<?php

/**
 * Manual Test: Scene Intel - Phase 2 Synthetic Intel Generation
 * Generated: 2026-02-14
 * Purpose: Verify Scene Intel Phase 2 synthetic generation deliverables:
 * - SceneIntelProcessor alarm/resource/phase diff generation
 * - FetchFireIncidentsCommand integration for update + deactivation flows
 * - Closure jitter guard behavior and reactivation cycle handling
 * - Command resilience when intel generation fails for one incident
 * - Optimized existing-incident prefetch query shape
 */

require __DIR__.'/../../vendor/autoload.php';

// Default manual verification runs to testing so Laravel loads `.env.testing`.
// Preserve an explicitly provided APP_ENV value if the caller set one.
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}

// Some manual test environments do not provide APP_KEY in `.env.testing`.
// Use a deterministic testing-only fallback so app boot does not fail.
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

// Prevent production execution.
if (app()->environment('production')) {
    exit("Error: Cannot run manual tests in production!\n");
}

if (function_exists('posix_geteuid') && posix_geteuid() === 0 && getenv('ALLOW_ROOT_MANUAL_TESTS') !== '1') {
    fwrite(STDERR, "Error: Do not run manual tests as root. Use `./vendor/bin/sail shell` (or `./vendor/bin/sail php ...`).\n");
    fwrite(STDERR, "If you really need root, re-run with ALLOW_ROOT_MANUAL_TESTS=1 (not recommended).\n");
    exit(1);
}

// Manual tests can delete data; only allow the dedicated testing database.
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
use App\Events\AlertCreated;
use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use App\Services\SceneIntel\SceneIntelProcessor;
use App\Services\TorontoFireFeedService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

$testRunId = 'scene_intel_phase_2_synthetic_intel_generation_'.Carbon::now()->format('Y_m_d_His');
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

function assertContainsRegex(string $pattern, string $value, string $label): void
{
    assertTrue((bool) preg_match($pattern, $value), $label, ['pattern' => $pattern, 'value' => $value]);
}

function isListArray(array $value): bool
{
    if (function_exists('array_is_list')) {
        return array_is_list($value);
    }

    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function normalizeAssociativeArray(array $value): array
{
    if (! isListArray($value)) {
        ksort($value);
    }

    foreach ($value as $key => $item) {
        if (is_array($item)) {
            $value[$key] = normalizeAssociativeArray($item);
        }
    }

    return $value;
}

function assertArrayEquivalent(array $actual, array $expected, string $label): void
{
    assertEqual(
        normalizeAssociativeArray($actual),
        normalizeAssociativeArray($expected),
        $label
    );
}

/**
 * @param  list<array{
 *     updated_at: string,
 *     events: list<array{
 *         event_num: string,
 *         event_type: string,
 *         prime_street: ?string,
 *         cross_streets: ?string,
 *         dispatch_time: string,
 *         alarm_level: int,
 *         beat: ?string,
 *         units_dispatched: ?string
 *     }>
 * }>  $responses
 */
function bindFeedServiceSequence(array $responses): void
{
    app()->instance(TorontoFireFeedService::class, new class($responses) extends TorontoFireFeedService
    {
        /**
         * @param  list<array{
         *     updated_at: string,
         *     events: list<array<string, mixed>>
         * }>  $responses
         */
        public function __construct(private array $responses) {}

        public function fetch(): array
        {
            if ($this->responses === []) {
                throw new RuntimeException('No fake feed responses remaining for this verifier phase.');
            }

            $response = array_shift($this->responses);

            if (! is_array($response)) {
                throw new RuntimeException('Fake feed response is not an array.');
            }

            return $response;
        }
    });
}

function bindSceneIntelProcessor(SceneIntelProcessor $processor): void
{
    app()->instance(SceneIntelProcessor::class, $processor);
}

function forgetContainerInstance(string $abstract): void
{
    $container = app();

    if (method_exists($container, 'forgetInstance')) {
        $container->forgetInstance($abstract);
    }
}

/**
 * @return array{exit_code: int, output: string}
 */
function runFetchFireIncidentsCommand(): array
{
    $exitCode = Artisan::call('fire:fetch-incidents');
    $output = trim(Artisan::output());

    logInfo('fire:fetch-incidents output', [
        'exit_code' => $exitCode,
        'output' => $output,
    ]);

    return [
        'exit_code' => $exitCode,
        'output' => $output,
    ];
}

$exitCode = 0;
$txStarted = false;

try {
    try {
        DB::connection()->getPdo();
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

    DB::beginTransaction();
    $txStarted = true;

    Event::fake([AlertCreated::class]);

    logInfo('=== Starting Manual Test: Scene Intel Phase 2 Synthetic Intel Generation ===');

    logInfo('Phase 1: SceneIntelProcessor diff generation');
    $processor = app(SceneIntelProcessor::class);

    $upwardIncident = FireIncident::factory()->create([
        'event_num' => 'F26041001',
        'alarm_level' => 2,
        'units_dispatched' => 'P100, R200',
        'is_active' => true,
    ]);

    $processor->processIncidentUpdate($upwardIncident, [
        'alarm_level' => 1,
        'units_dispatched' => 'P100, R300',
        'is_active' => true,
    ]);

    $upwardUpdates = IncidentUpdate::query()
        ->forIncident($upwardIncident->event_num)
        ->orderBy('id')
        ->get();

    assertEqual($upwardUpdates->count(), 3, 'processor creates alarm + dispatched + cleared updates');
    assertEqual($upwardUpdates[0]->update_type, IncidentUpdateType::ALARM_CHANGE, 'alarm change update type');
    assertEqual($upwardUpdates[0]->content, 'Alarm level increased from 1 to 2', 'alarm increase content');
    assertArrayEquivalent($upwardUpdates[0]->metadata ?? [], [
        'previous_level' => 1,
        'new_level' => 2,
        'direction' => 'up',
    ], 'alarm increase metadata');
    assertEqual($upwardUpdates[1]->content, 'Unit R200 dispatched', 'new unit dispatched content');
    assertArrayEquivalent($upwardUpdates[1]->metadata ?? [], [
        'unit_code' => 'R200',
        'status' => 'dispatched',
    ], 'dispatched unit metadata');
    assertEqual($upwardUpdates[2]->content, 'Unit R300 cleared', 'removed unit cleared content');
    assertArrayEquivalent($upwardUpdates[2]->metadata ?? [], [
        'unit_code' => 'R300',
        'status' => 'cleared',
    ], 'cleared unit metadata');

    $downwardIncident = FireIncident::factory()->create([
        'event_num' => 'F26041002',
        'alarm_level' => 1,
        'units_dispatched' => 'P111',
        'is_active' => true,
    ]);

    $processor->processIncidentUpdate($downwardIncident, [
        'alarm_level' => 3,
        'units_dispatched' => 'P111',
        'is_active' => true,
    ]);

    $downwardUpdate = IncidentUpdate::query()
        ->forIncident($downwardIncident->event_num)
        ->first();

    assertTrue($downwardUpdate !== null, 'alarm decrease update is generated');
    if ($downwardUpdate !== null) {
        assertEqual($downwardUpdate->content, 'Alarm level decreased from 3 to 1', 'alarm decrease content');
        assertArrayEquivalent($downwardUpdate->metadata ?? [], [
            'previous_level' => 3,
            'new_level' => 1,
            'direction' => 'down',
        ], 'alarm decrease metadata');
    }

    $newIncident = FireIncident::factory()->create(['event_num' => 'F26041003']);
    $processor->processIncidentUpdate($newIncident, null);
    assertEqual(
        IncidentUpdate::query()->forIncident($newIncident->event_num)->count(),
        0,
        'processor skips generation when previousData is null'
    );

    logInfo('Phase 2: SceneIntelProcessor phase transition lifecycle');

    $phaseIncident = FireIncident::factory()->create([
        'event_num' => 'F26041004',
        'is_active' => true,
    ]);

    $processor->processIncidentUpdate($phaseIncident, [
        'alarm_level' => $phaseIncident->alarm_level,
        'units_dispatched' => $phaseIncident->units_dispatched,
        'is_active' => false,
    ]);

    $phaseIncident->is_active = false;
    $processor->processIncidentUpdate($phaseIncident, [
        'alarm_level' => $phaseIncident->alarm_level,
        'units_dispatched' => $phaseIncident->units_dispatched,
        'is_active' => true,
    ]);

    $processor->processIncidentUpdate($phaseIncident, [
        'alarm_level' => $phaseIncident->alarm_level,
        'units_dispatched' => $phaseIncident->units_dispatched,
        'is_active' => true,
    ]);

    $phaseUpdates = IncidentUpdate::query()
        ->forIncident($phaseIncident->event_num)
        ->where('update_type', IncidentUpdateType::PHASE_CHANGE)
        ->orderBy('id')
        ->get();

    assertEqual($phaseUpdates->count(), 2, 'processor records active->resolved cycle without duplicate closure');
    assertEqual($phaseUpdates[0]->content, 'Incident marked as active', 'phase reactivation content');
    assertArrayEquivalent($phaseUpdates[0]->metadata ?? [], [
        'previous_phase' => 'resolved',
        'new_phase' => 'active',
    ], 'phase reactivation metadata');
    assertEqual($phaseUpdates[1]->content, 'Incident marked as resolved', 'phase closure content');
    assertArrayEquivalent($phaseUpdates[1]->metadata ?? [], [
        'previous_phase' => 'active',
        'new_phase' => 'resolved',
    ], 'phase closure metadata');

    logInfo('Phase 3: Command integration for changed incidents and deactivations');

    IncidentUpdate::query()->delete();
    FireIncident::query()->delete();

    FireIncident::factory()->create([
        'event_num' => 'F26042001',
        'event_type' => 'FIRE',
        'alarm_level' => 1,
        'units_dispatched' => 'P101, R301',
        'is_active' => true,
    ]);

    FireIncident::factory()->create([
        'event_num' => 'F26042999',
        'event_type' => 'FIRE',
        'is_active' => true,
    ]);

    bindFeedServiceSequence([
        [
            'updated_at' => '2026-02-14 10:00:00',
            'events' => [
                [
                    'event_num' => 'F26042001',
                    'event_type' => 'FIRE',
                    'prime_street' => 'KING ST W',
                    'cross_streets' => 'SPADINA AVE / BRANT ST',
                    'dispatch_time' => '2026-02-14T09:45:00',
                    'alarm_level' => 2,
                    'beat' => '101',
                    'units_dispatched' => 'P101, R201',
                ],
            ],
        ],
    ]);
    forgetContainerInstance(SceneIntelProcessor::class);

    $commandResult = runFetchFireIncidentsCommand();
    assertEqual($commandResult['exit_code'], 0, 'command succeeds for mixed update/deactivation feed');
    assertContainsText('Done. 1 active incidents synced, 1 marked inactive.', $commandResult['output'], 'command summary reflects deactivation');

    $changedUpdates = IncidentUpdate::query()->forIncident('F26042001')->orderBy('id')->get();
    assertEqual($changedUpdates->count(), 3, 'command writes alarm/resource synthetic updates');
    assertEqual($changedUpdates[0]->content, 'Alarm level increased from 1 to 2', 'command alarm diff content');
    assertEqual($changedUpdates[1]->content, 'Unit R201 dispatched', 'command dispatched diff content');
    assertEqual($changedUpdates[2]->content, 'Unit R301 cleared', 'command cleared diff content');

    $deactivatedUpdates = IncidentUpdate::query()->forIncident('F26042999')->get();
    assertEqual($deactivatedUpdates->count(), 1, 'command writes deactivation phase update');
    assertEqual($deactivatedUpdates[0]->content, 'Incident marked as resolved', 'deactivation phase content');

    logInfo('Phase 4: Command deactivation jitter guard and reactivation lifecycle');

    IncidentUpdate::query()->delete();
    FireIncident::query()->delete();

    $jitterIncident = FireIncident::factory()->create([
        'event_num' => 'F26043001',
        'event_type' => 'FIRE',
        'is_active' => true,
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => $jitterIncident->event_num,
        'update_type' => IncidentUpdateType::PHASE_CHANGE,
        'content' => 'Incident marked as resolved',
        'metadata' => [
            'previous_phase' => 'active',
            'new_phase' => 'resolved',
        ],
        'source' => 'synthetic',
    ]);

    bindFeedServiceSequence([
        [
            'updated_at' => '2026-02-14 10:05:00',
            'events' => [],
        ],
    ]);
    forgetContainerInstance(SceneIntelProcessor::class);

    $jitterResult = runFetchFireIncidentsCommand();
    assertEqual($jitterResult['exit_code'], 0, 'command succeeds for jitter guard scenario');

    $closureCountAfterJitter = IncidentUpdate::query()
        ->forIncident($jitterIncident->event_num)
        ->where('update_type', IncidentUpdateType::PHASE_CHANGE)
        ->where('content', 'Incident marked as resolved')
        ->count();

    assertEqual($closureCountAfterJitter, 1, 'jitter guard prevents duplicate resolved transition');

    $reactivatedIncident = FireIncident::factory()->create([
        'event_num' => 'F26043002',
        'event_type' => 'FIRE',
        'is_active' => false,
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => $reactivatedIncident->event_num,
        'update_type' => IncidentUpdateType::PHASE_CHANGE,
        'content' => 'Incident marked as resolved',
        'metadata' => [
            'previous_phase' => 'active',
            'new_phase' => 'resolved',
        ],
        'source' => 'synthetic',
    ]);

    bindFeedServiceSequence([
        [
            'updated_at' => '2026-02-14 10:10:00',
            'events' => [
                [
                    'event_num' => $reactivatedIncident->event_num,
                    'event_type' => 'FIRE',
                    'prime_street' => 'QUEEN ST W',
                    'cross_streets' => 'SPADINA AVE / AUGUSTA AVE',
                    'dispatch_time' => '2026-02-14T10:00:00',
                    'alarm_level' => 1,
                    'beat' => '143',
                    'units_dispatched' => 'P143',
                ],
            ],
        ],
        [
            'updated_at' => '2026-02-14 10:15:00',
            'events' => [],
        ],
    ]);
    forgetContainerInstance(SceneIntelProcessor::class);

    $reactivationRun = runFetchFireIncidentsCommand();
    $finalDeactivationRun = runFetchFireIncidentsCommand();
    assertEqual($reactivationRun['exit_code'], 0, 'command succeeds for reactivation run');
    assertEqual($finalDeactivationRun['exit_code'], 0, 'command succeeds for follow-up deactivation run');

    $reactivationPhaseUpdates = IncidentUpdate::query()
        ->forIncident($reactivatedIncident->event_num)
        ->where('update_type', IncidentUpdateType::PHASE_CHANGE)
        ->orderBy('id')
        ->get();

    $resolvedTransitions = $reactivationPhaseUpdates->where('content', 'Incident marked as resolved')->values();
    $activeTransitions = $reactivationPhaseUpdates->where('content', 'Incident marked as active')->values();

    assertEqual($resolvedTransitions->count(), 2, 'resolved transition is recorded again after reactivation');
    assertEqual($activeTransitions->count(), 1, 'active transition is recorded once during reactivation');
    assertArrayEquivalent(($resolvedTransitions->last()?->metadata) ?? [], [
        'previous_phase' => 'active',
        'new_phase' => 'resolved',
    ], 'second resolved transition metadata reflects active->resolved');

    logInfo('Phase 5: Command resilience and query optimization checks');

    IncidentUpdate::query()->delete();
    FireIncident::query()->delete();

    bindFeedServiceSequence([
        [
            'updated_at' => '2026-02-14 11:00:00',
            'events' => [
                [
                    'event_num' => 'E001',
                    'event_type' => 'Fire',
                    'prime_street' => 'Street 1',
                    'cross_streets' => 'Cross 1',
                    'dispatch_time' => '2026-02-14T11:00:00',
                    'alarm_level' => 1,
                    'beat' => 'B1',
                    'units_dispatched' => 'U1',
                ],
                [
                    'event_num' => 'E002',
                    'event_type' => 'Medical',
                    'prime_street' => 'Street 2',
                    'cross_streets' => 'Cross 2',
                    'dispatch_time' => '2026-02-14T11:05:00',
                    'alarm_level' => 0,
                    'beat' => 'B2',
                    'units_dispatched' => 'U2',
                ],
            ],
        ],
    ]);

    bindSceneIntelProcessor(new class extends SceneIntelProcessor
    {
        public function processIncidentUpdate(FireIncident $incident, ?array $previousData): void
        {
            if ($incident->event_num === 'E001') {
                throw new RuntimeException('Intel generation failed');
            }
        }
    });

    $resilienceRun = runFetchFireIncidentsCommand();
    assertEqual($resilienceRun['exit_code'], 0, 'command continues despite per-incident intel processing failure');
    assertContainsText(
        'Failed to generate scene intel for event E001: Intel generation failed',
        $resilienceRun['output'],
        'command logs scene intel processing failure'
    );
    assertTrue(FireIncident::query()->where('event_num', 'E001')->exists(), 'first incident still persists despite intel failure');
    assertTrue(FireIncident::query()->where('event_num', 'E002')->exists(), 'second incident persists after first intel failure');

    IncidentUpdate::query()->delete();
    FireIncident::query()->delete();
    forgetContainerInstance(SceneIntelProcessor::class);

    FireIncident::factory()->create([
        'event_num' => 'E900',
        'is_active' => true,
    ]);

    bindFeedServiceSequence([
        [
            'updated_at' => '2026-02-14 11:15:00',
            'events' => [
                [
                    'event_num' => 'E900',
                    'event_type' => 'Fire',
                    'prime_street' => 'Optimized Street',
                    'cross_streets' => 'Optimized Cross',
                    'dispatch_time' => '2026-02-14T11:12:00',
                    'alarm_level' => 1,
                    'beat' => 'B9',
                    'units_dispatched' => 'U9',
                ],
            ],
        ],
    ]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $queryShapeRun = runFetchFireIncidentsCommand();
    assertEqual($queryShapeRun['exit_code'], 0, 'command succeeds for query-shape verification scenario');

    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    $optimizedSelectPattern = '/select\\s+["`]?id["`]?\\s*,\\s*["`]?event_num["`]?\\s*,\\s*["`]?alarm_level["`]?\\s*,\\s*["`]?units_dispatched["`]?\\s*,\\s*["`]?is_active["`]?\\s+from\\s+["`]?fire_incidents["`]?/i';
    $foundOptimizedQuery = false;

    foreach ($queries as $query) {
        $sql = is_array($query) ? (string) ($query['query'] ?? '') : (string) $query;

        if (preg_match($optimizedSelectPattern, $sql) === 1) {
            $foundOptimizedQuery = true;
            break;
        }
    }

    assertTrue($foundOptimizedQuery, 'existing incident prefetch uses optimized selected columns');
    assertContainsRegex($optimizedSelectPattern, implode("\n", array_map(
        static fn ($query): string => is_array($query) ? (string) ($query['query'] ?? '') : (string) $query,
        $queries
    )), 'query log includes optimized fire_incidents column selection');

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;

    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    forgetContainerInstance(TorontoFireFeedService::class);
    forgetContainerInstance(SceneIntelProcessor::class);

    if ($txStarted && DB::transactionLevel() > 0) {
        DB::rollBack();
        logInfo('Transaction rolled back (database preserved).');
    }

    logInfo('=== Test Run Finished ===');
    echo "\n".($exitCode === 0 ? '[OK]' : '[FAIL]')." Full logs at: {$logFileRelative}\n";

    exit($exitCode);
}
