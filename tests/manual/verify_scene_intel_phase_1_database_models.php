<?php

/**
 * Manual Test: Scene Intel - Phase 1 Database & Models
 * Generated: 2026-02-13
 * Purpose: Verify Scene Intel Phase 1 persistence/model deliverables:
 * - incident_updates schema, indexes, and foreign keys
 * - IncidentUpdateType enum cases and presentation mappings
 * - IncidentUpdate and FireIncident relationships/casts/scopes
 * - SceneIntelRepository ordering, summary payload, and manual entry writes
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
use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use App\Models\User;
use App\Services\SceneIntel\SceneIntelRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

$testRunId = 'scene_intel_phase_1_database_models_'.Carbon::now()->format('Y_m_d_His');
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

function hasIndex(array $indexRows, string $columns, bool $unique): bool
{
    foreach ($indexRows as $row) {
        $rowValues = array_change_key_case((array) $row, CASE_LOWER);
        $isUnique = ((int) ($rowValues['non_unique'] ?? 1)) === 0;
        $rowColumns = (string) ($rowValues['columns'] ?? '');

        if ($rowColumns === $columns && $isUnique === $unique) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, mixed>|null
 */
function findForeignKey(
    array $foreignKeyRows,
    string $column,
    string $referencedTable,
    string $referencedColumn
): ?array {
    foreach ($foreignKeyRows as $row) {
        $values = array_change_key_case((array) $row, CASE_LOWER);

        if (
            ($values['column_name'] ?? null) === $column
            && ($values['referenced_table_name'] ?? null) === $referencedTable
            && ($values['referenced_column_name'] ?? null) === $referencedColumn
        ) {
            return $values;
        }
    }

    return null;
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

    if (! Schema::hasTable('incident_updates')) {
        logInfo('incident_updates table missing; running migrations for testing database');
        Artisan::call('migrate', ['--force' => true]);
        logInfo('Migration output', ['output' => trim(Artisan::output())]);
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: Scene Intel Phase 1 Database & Models ===');

    logInfo('Phase 1: Schema, index, and foreign-key verification');
    assertTrue(Schema::hasTable('incident_updates'), 'incident_updates table exists');
    assertTrue(Schema::hasColumns('incident_updates', [
        'id',
        'event_num',
        'update_type',
        'content',
        'metadata',
        'source',
        'created_by',
        'created_at',
        'updated_at',
    ]), 'incident_updates has required columns');

    $columnTypeRows = DB::select("
        SELECT column_name, data_type, column_default
        FROM information_schema.columns
        WHERE table_schema = DATABASE()
          AND table_name = 'incident_updates'
          AND column_name IN ('metadata', 'source')
    ");

    $columnTypes = [];
    foreach ($columnTypeRows as $row) {
        $rowValues = array_change_key_case((array) $row, CASE_LOWER);
        $columnName = (string) ($rowValues['column_name'] ?? '');

        if ($columnName !== '') {
            $columnTypes[$columnName] = [
                'data_type' => strtolower((string) ($rowValues['data_type'] ?? '')),
                'column_default' => (string) ($rowValues['column_default'] ?? ''),
            ];
        }
    }

    assertEqual($columnTypes['metadata']['data_type'] ?? null, 'json', 'metadata uses json column type');
    assertEqual($columnTypes['source']['column_default'] ?? null, 'synthetic', 'source defaults to synthetic');

    $indexRows = DB::select("
        SELECT
            index_name,
            non_unique,
            GROUP_CONCAT(column_name ORDER BY seq_in_index SEPARATOR ',') AS columns
        FROM information_schema.statistics
        WHERE table_schema = DATABASE()
          AND table_name = 'incident_updates'
        GROUP BY index_name, non_unique
    ");

    assertTrue(hasIndex($indexRows, 'event_num,created_at', false), 'composite index on event_num+created_at');
    assertTrue(hasIndex($indexRows, 'update_type', false), 'index on update_type');
    assertTrue(hasIndex($indexRows, 'created_at', false), 'index on created_at');

    $foreignKeyRows = DB::select("
        SELECT
            k.constraint_name,
            k.column_name,
            k.referenced_table_name,
            k.referenced_column_name,
            rc.delete_rule
        FROM information_schema.key_column_usage k
        JOIN information_schema.referential_constraints rc
            ON rc.constraint_schema = k.constraint_schema
           AND rc.constraint_name = k.constraint_name
        WHERE k.table_schema = DATABASE()
          AND k.table_name = 'incident_updates'
          AND k.referenced_table_name IS NOT NULL
    ");

    $incidentForeignKey = findForeignKey($foreignKeyRows, 'event_num', 'fire_incidents', 'event_num');
    assertTrue($incidentForeignKey !== null, 'event_num FK references fire_incidents.event_num');
    assertEqual(
        strtoupper((string) ($incidentForeignKey['delete_rule'] ?? '')),
        'CASCADE',
        'event_num FK uses ON DELETE CASCADE'
    );

    $creatorForeignKey = findForeignKey($foreignKeyRows, 'created_by', 'users', 'id');
    assertTrue($creatorForeignKey !== null, 'created_by FK references users.id');
    assertEqual(
        strtoupper((string) ($creatorForeignKey['delete_rule'] ?? '')),
        'SET NULL',
        'created_by FK uses ON DELETE SET NULL'
    );

    $cascadeIncident = FireIncident::factory()->create();
    IncidentUpdate::factory()->count(2)->create([
        'event_num' => $cascadeIncident->event_num,
    ]);

    assertEqual(
        IncidentUpdate::query()->forIncident($cascadeIncident->event_num)->count(),
        2,
        'precondition: cascade fixture updates exist'
    );

    $cascadeIncident->delete();

    assertEqual(
        IncidentUpdate::query()->forIncident($cascadeIncident->event_num)->count(),
        0,
        'deleting fire incident cascades incident_updates'
    );

    logInfo('Phase 2: Enum and model behavior verification');
    $expectedEnumValues = [
        'milestone',
        'resource_status',
        'alarm_change',
        'phase_change',
        'manual_note',
    ];

    $enumValues = array_map(static fn (IncidentUpdateType $type): string => $type->value, IncidentUpdateType::cases());
    assertEqual($enumValues, $expectedEnumValues, 'IncidentUpdateType values match Phase 1 scope');
    assertEqual(IncidentUpdateType::RESOURCE_STATUS->label(), 'Resource Update', 'RESOURCE_STATUS label');
    assertEqual(IncidentUpdateType::ALARM_CHANGE->icon(), 'trending_up', 'ALARM_CHANGE icon');

    $incidentUpdateModel = new IncidentUpdate;
    assertEqual($incidentUpdateModel->getFillable(), [
        'event_num',
        'update_type',
        'content',
        'metadata',
        'source',
        'created_by',
    ], 'IncidentUpdate fillable attributes');

    $fireIncidentModel = new FireIncident;
    assertTrue(
        in_array('event_num', $fireIncidentModel->getFillable(), true),
        'FireIncident fillable includes event_num'
    );

    $incident = FireIncident::factory()->create();
    $otherIncident = FireIncident::factory()->create();
    $creator = User::factory()->create();

    $castedUpdate = IncidentUpdate::query()->create([
        'event_num' => $incident->event_num,
        'update_type' => IncidentUpdateType::MILESTONE,
        'content' => 'Command established',
        'metadata' => ['unitCode' => 'P144', 'phase' => 'arrival'],
        'source' => 'manual',
        'created_by' => (string) $creator->id,
    ])->fresh();

    assertTrue($castedUpdate instanceof IncidentUpdate, 'incident update persisted');
    assertTrue($castedUpdate->update_type instanceof IncidentUpdateType, 'update_type cast to IncidentUpdateType enum');
    assertEqual($castedUpdate->update_type, IncidentUpdateType::MILESTONE, 'update_type enum value preserved');
    assertEqual($castedUpdate->metadata, ['unitCode' => 'P144', 'phase' => 'arrival'], 'metadata cast to array');
    assertEqual($castedUpdate->created_by, $creator->id, 'created_by cast to integer');
    assertTrue($castedUpdate->fireIncident?->is($incident) ?? false, 'IncidentUpdate belongsTo FireIncident via event_num');
    assertTrue($castedUpdate->creator?->is($creator) ?? false, 'IncidentUpdate belongsTo creator user');

    IncidentUpdate::factory()->count(2)->create(['event_num' => $incident->event_num]);
    IncidentUpdate::factory()->create(['event_num' => $otherIncident->event_num]);

    assertEqual(
        IncidentUpdate::query()->forIncident($incident->event_num)->count(),
        3,
        'scopeForIncident filters by event_num'
    );

    assertEqual($incident->incidentUpdates()->count(), 3, 'FireIncident hasMany incident updates');

    logInfo('Phase 3: SceneIntelRepository behavior verification');
    $repository = app(SceneIntelRepository::class);
    $repoIncident = FireIncident::factory()->create();
    $repoOtherIncident = FireIncident::factory()->create();
    $sameSecond = Carbon::parse('2026-02-13 10:00:00');

    IncidentUpdate::factory()->create([
        'event_num' => $repoOtherIncident->event_num,
        'content' => 'Other incident update',
        'created_at' => Carbon::parse('2026-02-13 10:05:00'),
        'updated_at' => Carbon::parse('2026-02-13 10:05:00'),
    ]);

    $firstId = IncidentUpdate::factory()->create([
        'event_num' => $repoIncident->event_num,
        'content' => 'First same-second update',
        'created_at' => $sameSecond,
        'updated_at' => $sameSecond,
    ])->id;

    $secondId = IncidentUpdate::factory()->create([
        'event_num' => $repoIncident->event_num,
        'content' => 'Second same-second update',
        'created_at' => $sameSecond,
        'updated_at' => $sameSecond,
    ])->id;

    $thirdId = IncidentUpdate::factory()->create([
        'event_num' => $repoIncident->event_num,
        'update_type' => IncidentUpdateType::ALARM_CHANGE,
        'content' => 'Escalated to 2-Alarm',
        'metadata' => ['newLevel' => 2],
        'created_at' => $sameSecond,
        'updated_at' => $sameSecond,
    ])->id;

    $latest = $repository->getLatestForIncident($repoIncident->event_num, 2);
    assertEqual($latest->count(), 2, 'getLatestForIncident applies limit');
    assertEqual($latest->pluck('id')->all(), [$thirdId, $secondId], 'getLatestForIncident uses id tie-break descending');
    assertEqual($latest->pluck('event_num')->unique()->all(), [$repoIncident->event_num], 'getLatestForIncident filters by incident');

    $timeline = $repository->getTimeline($repoIncident->event_num);
    assertEqual($timeline->pluck('id')->all(), [$firstId, $secondId, $thirdId], 'getTimeline orders ascending with id tie-break');

    $summary = $repository->getSummaryForIncident($repoIncident->event_num, 2);
    assertEqual(count($summary), 2, 'getSummaryForIncident applies limit');
    assertEqual(array_column($summary, 'id'), [$thirdId, $secondId], 'getSummaryForIncident uses latest ordering');
    assertEqual($summary[0]['type'], 'alarm_change', 'summary includes update_type value');
    assertEqual($summary[0]['type_label'], 'Alarm Level Change', 'summary includes enum label');
    assertEqual($summary[0]['icon'], 'trending_up', 'summary includes enum icon');
    assertEqual($summary[0]['content'], 'Escalated to 2-Alarm', 'summary includes content');
    assertEqual($summary[0]['metadata'], ['newLevel' => 2], 'summary includes metadata array');
    assertTrue(is_string($summary[0]['timestamp']) && str_contains($summary[0]['timestamp'], 'T'), 'summary includes ISO timestamp');

    $manualEntry = $repository->addManualEntry(
        eventNum: $repoIncident->event_num,
        content: 'Primary search complete',
        userId: $creator->id,
        metadata: ['milestoneType' => 'primary_search_complete']
    );

    assertEqual($manualEntry->event_num, $repoIncident->event_num, 'addManualEntry stores event_num');
    assertEqual($manualEntry->update_type, IncidentUpdateType::MANUAL_NOTE, 'addManualEntry stores manual_note type');
    assertEqual($manualEntry->source, 'manual', 'addManualEntry stores manual source');
    assertEqual($manualEntry->created_by, $creator->id, 'addManualEntry stores creator id');
    assertEqual($manualEntry->metadata, ['milestoneType' => 'primary_search_complete'], 'addManualEntry stores metadata');

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
    if ($txStarted && DB::transactionLevel() > 0) {
        DB::rollBack();
        logInfo('Transaction rolled back (database preserved).');
    }

    logInfo('=== Test Run Finished ===');
    echo "\n".($exitCode === 0 ? '[OK]' : '[FAIL]')." Full logs at: {$logFileRelative}\n";

    exit($exitCode);
}
