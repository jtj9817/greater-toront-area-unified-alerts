<?php

/**
 * Manual Test: Scene Intel - Phase 5 Optimization & Hardening
 * Generated: 2026-02-14
 * Purpose: Verify Scene Intel Phase 5 deliverables:
 * - Fire alert selection embeds bounded intel_summary and intel_last_updated
 * - Unified feed remains stable with mixed provider UNION output
 * - Frontend consumes embedded intel summary before polling
 * - Targeted backend/frontend tests pass for optimized integration paths
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

$expectedMySqlDatabase = 'gta_alerts_testing';
$connection = config('database.default');
$currentDatabase = config("database.connections.{$connection}.database");

if (! app()->environment('testing')) {
    exit("Error: Manual tests must run with APP_ENV=testing. Destructive test operations are disabled outside the testing environment and cannot be overridden.\n");
}

$isSafeDatabase = ($connection === 'mysql' && $currentDatabase === $expectedMySqlDatabase)
    || $connection === 'sqlite';

if (! $isSafeDatabase) {
    exit("Error: Manual tests require either mysql database '{$expectedMySqlDatabase}' or sqlite connection in APP_ENV=testing (current: {$connection}/{$currentDatabase}).\n");
}

umask(002);

use App\Enums\IncidentUpdateType;
use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;
use App\Services\Alerts\Providers\FireAlertSelectProvider;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

$testRunId = 'scene_intel_phase_5_optimization_hardening_'.Carbon::now()->format('Y_m_d_His');
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

    if (
        ! Schema::hasTable('fire_incidents')
        || ! Schema::hasTable('incident_updates')
        || ! Schema::hasTable('police_calls')
        || ! Schema::hasTable('transit_alerts')
    ) {
        logInfo('Required tables missing; running migrations for testing database');
        Artisan::call('migrate', ['--force' => true]);
        logInfo('Migration output', ['output' => trim(Artisan::output())]);
    }

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: Scene Intel Phase 5 Optimization & Hardening ===');

    logInfo('Phase 1: Verify fire select embeds bounded intel summary and last-updated timestamp');

    $incident = FireIncident::factory()->create([
        'event_num' => 'F26081001',
        'is_active' => true,
        'alarm_level' => 2,
        'units_dispatched' => 'P331, R331',
    ]);

    $baseTime = Carbon::parse('2026-02-14 12:00:00');

    foreach (range(1, 5) as $index) {
        IncidentUpdate::factory()->create([
            'event_num' => $incident->event_num,
            'update_type' => IncidentUpdateType::RESOURCE_STATUS,
            'content' => "Embedded update {$index}",
            'metadata' => ['index' => $index],
            'source' => 'synthetic',
            'created_at' => $baseTime->copy()->addMinutes($index),
        ]);
    }

    $fireRow = (new FireAlertSelectProvider)->select()
        ->where('event_num', $incident->event_num)
        ->first();

    assertTrue($fireRow !== null, 'fire provider returns a row for seeded incident');

    if ($fireRow === null) {
        throw new RuntimeException('Expected FireAlertSelectProvider row for seeded incident.');
    }

    $fireMeta = UnifiedAlertMapper::decodeMeta($fireRow->meta);

    assertTrue(array_key_exists('intel_summary', $fireMeta), 'fire meta includes intel_summary');
    assertTrue(array_key_exists('intel_last_updated', $fireMeta), 'fire meta includes intel_last_updated');
    assertEqual(count($fireMeta['intel_summary']), 3, 'intel_summary is capped to latest 3 items');
    assertEqual(
        array_column($fireMeta['intel_summary'], 'content'),
        ['Embedded update 5', 'Embedded update 4', 'Embedded update 3'],
        'intel_summary contains newest-first updates'
    );

    assertTrue(is_string($fireMeta['intel_last_updated']), 'intel_last_updated is a string timestamp');
    assertEqual(
        Carbon::parse((string) $fireMeta['intel_last_updated'])->utc()->format('Y-m-d H:i:s'),
        '2026-02-14 12:05:00',
        'intel_last_updated matches max incident_updates.created_at'
    );

    $incidentWithoutUpdates = FireIncident::factory()->create([
        'event_num' => 'F26081002',
        'is_active' => true,
    ]);

    $emptyRow = (new FireAlertSelectProvider)->select()
        ->where('event_num', $incidentWithoutUpdates->event_num)
        ->first();
    assertTrue($emptyRow !== null, 'fire provider returns a row when no incident updates exist');

    if ($emptyRow !== null) {
        $emptyMeta = UnifiedAlertMapper::decodeMeta($emptyRow->meta);
        assertEqual($emptyMeta['intel_summary'] ?? null, [], 'intel_summary defaults to empty array');
        assertEqual($emptyMeta['intel_last_updated'] ?? null, null, 'intel_last_updated defaults to null');
    }

    logInfo('Phase 2: Verify unified mixed-feed query remains valid with fire intel embedding');

    PoliceCall::factory()->create([
        'object_id' => 700001,
        'call_type' => 'ASSIST AMBULANCE',
        'call_type_code' => 'ASSAM',
        'occurrence_time' => Carbon::parse('2026-02-14 12:10:00'),
        'is_active' => true,
    ]);

    TransitAlert::factory()->create([
        'external_id' => 'api:phase5-1',
        'title' => 'Line 1 delay advisory',
        'active_period_start' => Carbon::parse('2026-02-14 12:15:00'),
        'is_active' => true,
    ]);

    $results = app(UnifiedAlertsQuery::class)->paginate(
        new UnifiedAlertsCriteria(status: 'all', perPage: 50)
    );

    $items = collect($results->items());
    $sources = $items->map(fn ($item) => $item->source)->unique()->values()->all();

    assertTrue(in_array('fire', $sources, true), 'unified results include fire source');
    assertTrue(in_array('police', $sources, true), 'unified results include police source');
    assertTrue(in_array('transit', $sources, true), 'unified results include transit source');

    $fireUnified = $items->first(fn ($item) => $item->source === 'fire');
    assertTrue($fireUnified !== null, 'unified result contains a fire dto');

    if ($fireUnified !== null) {
        assertTrue(array_key_exists('intel_summary', $fireUnified->meta), 'fire dto meta carries intel_summary');
        assertTrue(array_key_exists('intel_last_updated', $fireUnified->meta), 'fire dto meta carries intel_last_updated');
    }

    logInfo('Phase 3: Verify frontend integration wiring for embedded intel paths');

    $fireSchemaContents = readFileContents('resources/js/features/gta-alerts/domain/alerts/fire/schema.ts');
    assertContainsText('intel_summary', $fireSchemaContents, 'FireMetaSchema includes intel_summary');
    assertContainsText('intel_last_updated', $fireSchemaContents, 'FireMetaSchema includes intel_last_updated');

    $alertServiceContents = readFileContents('resources/js/features/gta-alerts/services/AlertService.ts');
    assertContainsText('return fromResource(alert);', $alertServiceContents, 'AlertService maps main feed via domain resource parser');

    $presentationContents = readFileContents('resources/js/features/gta-alerts/domain/alerts/fire/presentation.ts');
    assertContainsText('const intelSummary = alert.meta.intel_summary;', $presentationContents, 'fire presentation consumes intel_summary');
    assertContainsText('const intelLastUpdated = alert.meta.intel_last_updated;', $presentationContents, 'fire presentation consumes intel_last_updated');
    assertContainsText('intelSummary,', $presentationContents, 'fire presentation metadata emits intelSummary');
    assertContainsText('intelLastUpdated,', $presentationContents, 'fire presentation metadata emits intelLastUpdated');

    $detailsViewContents = readFileContents('resources/js/features/gta-alerts/components/AlertDetailsView.tsx');
    assertContainsText(
        'eventNum={alert.metadata?.eventNum || \'\'}',
        $detailsViewContents,
        'AlertDetailsView passes eventNum to SceneIntelTimeline'
    );
    assertContainsText(
        'initialItems={alert.metadata?.intelSummary}',
        $detailsViewContents,
        'AlertDetailsView passes embedded intel summary as initialItems'
    );

    $hookContents = readFileContents('resources/js/features/gta-alerts/hooks/useSceneIntel.ts');
    assertContainsText(
        'const [items, setItems] = useState<SceneIntelItem[]>(initialItems);',
        $hookContents,
        'useSceneIntel initializes local state from initialItems'
    );
    assertContainsText(
        'const hasDataRef = useRef<boolean>(initialItems.length > 0);',
        $hookContents,
        'useSceneIntel suppresses initial loading when embedded intel exists'
    );

    logInfo('Phase 4: Execute targeted regression suites for optimization and hardening paths');

    $backendTests = runCommand(
        'APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_STORE=array QUEUE_CONNECTION=sync SESSION_DRIVER=array php artisan test tests/Unit/Services/Alerts/Providers/FireAlertSelectProviderTest.php tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php',
        'scene intel phase 5 backend targeted suites'
    );
    assertTrue(
        $backendTests['exit_code'] === 0,
        'targeted backend tests pass',
        ['exit_code' => $backendTests['exit_code']]
    );

    $frontendTests = runCommand(
        'CI=true LARAVEL_BYPASS_ENV_CHECK=1 pnpm exec vitest run resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx resources/js/features/gta-alerts/components/SceneIntelTimeline.test.tsx resources/js/features/gta-alerts/hooks/useSceneIntel.test.ts resources/js/features/gta-alerts/domain/alerts/fire/mapper.test.ts',
        'scene intel phase 5 frontend targeted suites'
    );
    assertTrue(
        $frontendTests['exit_code'] === 0,
        'targeted frontend tests pass',
        ['exit_code' => $frontendTests['exit_code']]
    );

    logInfo('=== Scene Intel Phase 5 Optimization & Hardening manual verification completed successfully ===');
    logInfo('Manual test log file', ['path' => $logFileRelative]);
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual verification failed', [
        'exception' => $e::class,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    if ($txStarted && DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    if ($exitCode === 0) {
        echo "\nSUCCESS: Scene Intel Phase 5 Optimization & Hardening manual verification passed.\n";
        echo "Log: {$logFileRelative}\n";
    } else {
        echo "\nFAILED: Scene Intel Phase 5 Optimization & Hardening manual verification failed.\n";
        echo "Check log: {$logFileRelative}\n";
    }
}

exit($exitCode);
