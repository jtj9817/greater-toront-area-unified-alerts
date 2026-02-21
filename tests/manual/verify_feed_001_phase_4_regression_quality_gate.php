<?php

/**
 * Manual Test: FEED-001 - Phase 4 Regression & Quality Gate
 * Generated: 2026-02-21
 *
 * Purpose:
 * - Verify FEED-001 regression checklist:
 *   - server-side filters remain authoritative
 *   - cursor infinite scroll remains deterministic with no duplicates
 *   - URL query params continue to round-trip into Inertia filter props
 * - Execute quality-gate commands required by Phase 4:
 *   - composer test
 *   - pnpm run lint
 *   - pnpm run format (optional when RUN_FORMAT=1)
 *
 * Run:
 * - ./vendor/bin/sail php tests/manual/verify_feed_001_phase_4_regression_quality_gate.php
 *
 * Optional:
 * - SKIP_COMMAND_GATES=1 (skip composer/pnpm commands)
 * - RUN_FORMAT=1 (also execute pnpm run format)
 */

require __DIR__.'/../../vendor/autoload.php';

// Default manual verification runs to testing so Laravel loads `.env.testing`.
if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
    $_SERVER['APP_ENV'] = 'testing';
}

// Some manual test environments do not provide APP_KEY in `.env.testing`.
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
    fwrite(STDERR, "Error: Cannot run manual tests in production!\n");
    exit(1);
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
    fwrite(STDERR, "Error: Manual tests must run with APP_ENV=testing. Destructive test operations are disabled outside the testing environment and cannot be overridden.\n");
    exit(1);
}

if ($currentDatabase !== $expectedDatabase) {
    fwrite(STDERR, "Error: Manual tests must use the '{$expectedDatabase}' database (current: {$currentDatabase}). Destructive test operations are disabled and cannot be overridden.\n");
    exit(1);
}

umask(002);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;
use App\Services\Alerts\UnifiedAlertsQuery;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

$testRunId = 'feed_001_phase_4_regression_quality_gate_'.Carbon::now()->format('Y_m_d_His');
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

@chmod($logFile, 0664);

config(['logging.channels.manual_test' => [
    'driver' => 'single',
    'path' => $logFile,
    'level' => 'debug',
]]);

config(['logging.default' => 'manual_test']);

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

function runCommand(string $label, string $command): void
{
    logInfo("Running command: {$label}", ['command' => $command]);

    $process = new Process(['bash', '-lc', $command], base_path());
    $process->setTimeout(null);

    $outputBuffer = '';
    $process->run(function (string $type, string $output) use (&$outputBuffer): void {
        $outputBuffer .= $output;
        echo $output;
    });

    if (! $process->isSuccessful()) {
        logError("Command failed: {$label}", [
            'exit_code' => $process->getExitCode(),
            'output_tail' => mb_substr($outputBuffer, -5000),
        ]);

        throw new RuntimeException("Phase 4 quality gate failed at: {$label}");
    }

    logInfo("Command succeeded: {$label}", ['exit_code' => $process->getExitCode()]);
}

/**
 * @param  array<string, string|null>  $query
 * @return array<string, mixed>
 */
function makeInertiaPayload(array $query): array
{
    $httpKernel = app(HttpKernel::class);
    $inertiaMiddleware = app(HandleInertiaRequests::class);

    $request = Request::create('/', 'GET', $query, [], [], [
        'HTTP_X_INERTIA' => 'true',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        'HTTP_ACCEPT' => 'application/json',
    ]);

    $version = $inertiaMiddleware->version($request);
    if (is_string($version) && $version !== '') {
        $request->headers->set('X-Inertia-Version', $version);
    }

    $response = $httpKernel->handle($request);

    if (method_exists($httpKernel, 'terminate')) {
        $httpKernel->terminate($request, $response);
    }

    if ($response->getStatusCode() === 409) {
        $location = $response->headers->get('X-Inertia-Location') ?? $response->headers->get('Location');
        $suffix = $location ? " Location: {$location}" : '';
        throw new RuntimeException("Inertia asset version mismatch (409).{$suffix}");
    }

    $payload = json_decode((string) $response->getContent(), true);
    if (! is_array($payload)) {
        throw new RuntimeException('Expected Inertia JSON payload but received non-JSON response.');
    }

    return $payload;
}

/**
 * @param  array<string, string|null>  $query
 * @return array<string, mixed>
 */
function makeJsonPayload(array $query): array
{
    $httpKernel = app(HttpKernel::class);

    $request = Request::create('/api/feed', 'GET', $query, [], [], [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
    ]);

    $response = $httpKernel->handle($request);

    if (method_exists($httpKernel, 'terminate')) {
        $httpKernel->terminate($request, $response);
    }

    $payload = json_decode((string) $response->getContent(), true);
    if (! is_array($payload)) {
        throw new RuntimeException('Expected API JSON payload but received non-JSON response.');
    }

    return $payload;
}

/**
 * @param  array<int, mixed>  $items
 * @return array<int, string>
 */
function pluckIds(array $items): array
{
    return collect($items)
        ->map(static fn ($item): string => (string) ($item->id ?? ''))
        ->values()
        ->all();
}

$exitCode = 0;
$txStarted = false;
$driverName = null;

try {
    try {
        DB::connection()->getPdo();
    } catch (\Throwable $e) {
        throw new RuntimeException(
            "Database connection failed. Run inside Sail:\n- ./vendor/bin/sail php tests/manual/verify_feed_001_phase_4_regression_quality_gate.php",
            previous: $e
        );
    }

    $driverName = DB::getDriverName();
    $useTransaction = $driverName !== 'mysql';

    if ($useTransaction) {
        DB::beginTransaction();
        $txStarted = true;
    }

    logInfo('=== Starting Manual Test: FEED-001 Phase 4 Regression & Quality Gate ===', [
        'driver' => $driverName,
        'wrapped_transaction' => $txStarted,
    ]);

    $now = CarbonImmutable::parse('2026-02-21 12:00:00');
    Carbon::setTestNow(Carbon::instance($now->toDateTime()));
    CarbonImmutable::setTestNow($now);

    logInfo('Step 1: Preparing deterministic regression dataset');

    FireIncident::query()->delete();
    PoliceCall::query()->delete();
    TransitAlert::query()->delete();
    GoTransitAlert::query()->delete();

    $searchToken = 'FEEDPHASE4TOKEN';

    FireIncident::factory()->create([
        'event_num' => 'FIRE-P4-001',
        'event_type' => "STRUCTURE FIRE {$searchToken}",
        'prime_street' => 'Yonge St',
        'cross_streets' => 'Dundas St',
        'dispatch_time' => $now->subMinutes(8),
        'is_active' => true,
        'feed_updated_at' => $now->subMinutes(7),
    ]);

    FireIncident::factory()->inactive()->create([
        'event_num' => 'FIRE-P4-002',
        'event_type' => "ALARM {$searchToken}",
        'prime_street' => 'Queen St',
        'cross_streets' => null,
        'dispatch_time' => $now->subMinutes(6),
        'feed_updated_at' => $now->subMinutes(6),
    ]);

    FireIncident::factory()->create([
        'event_num' => 'FIRE-P4-003',
        'event_type' => "ALARM {$searchToken}",
        'prime_street' => 'Bloor St',
        'cross_streets' => null,
        'dispatch_time' => $now->subHours(4),
        'is_active' => true,
        'feed_updated_at' => $now->subHours(4),
    ]);

    PoliceCall::factory()->create([
        'object_id' => 940001,
        'call_type' => "ASSAULT {$searchToken}",
        'is_active' => true,
        'occurrence_time' => $now->subMinutes(9),
        'feed_updated_at' => $now->subMinutes(9),
    ]);

    TransitAlert::factory()->create([
        'external_id' => 'api:P4-001',
        'title' => "Line 1 delay {$searchToken}",
        'is_active' => true,
        'active_period_start' => $now->subMinutes(12),
        'feed_updated_at' => $now->subMinutes(12),
    ]);

    GoTransitAlert::factory()->create([
        'external_id' => 'notif:P4-001',
        'message_subject' => "Lakeshore West {$searchToken}",
        'is_active' => true,
        'posted_at' => $now->subMinutes(10),
        'feed_updated_at' => $now->subMinutes(10),
    ]);

    for ($i = 1; $i <= 55; $i++) {
        FireIncident::factory()->create([
            'event_num' => sprintf('FIRE-P4-BATCH-%03d', $i),
            'event_type' => 'ALARM',
            'prime_street' => "Batch St {$i}",
            'cross_streets' => null,
            'dispatch_time' => $now->subMinutes(20 + $i),
            'is_active' => true,
            'feed_updated_at' => $now->subMinutes(20 + $i),
        ]);
    }

    assertEqual(FireIncident::count(), 58, 'fire incidents seeded');
    assertEqual(PoliceCall::count(), 1, 'police calls seeded');
    assertEqual(TransitAlert::count(), 1, 'transit alerts seeded');
    assertEqual(GoTransitAlert::count(), 1, 'go transit alerts seeded');

    logInfo('Step 2: Verifying server-side authoritative filter combinations');

    $query = app(UnifiedAlertsQuery::class);
    $combo = $query->cursorPaginate(new UnifiedAlertsCriteria(
        status: 'active',
        source: 'fire',
        since: '1h',
        query: $searchToken,
        perPage: 50,
    ));

    assertEqual(count($combo['items']), 1, 'status+source+since+q intersection returns one row');
    assertEqual($combo['items'][0]->id, 'fire:FIRE-P4-001', 'intersection row is expected fire incident');

    logInfo('Step 3: Verifying cursor infinite scroll determinism (no duplicates)');

    $seenIds = [];
    $cursor = null;
    $pages = 0;

    do {
        $batch = $query->cursorPaginate(new UnifiedAlertsCriteria(
            status: 'all',
            source: 'fire',
            perPage: 20,
            cursor: $cursor,
        ));

        $ids = pluckIds($batch['items']);
        $duplicates = array_values(array_intersect($seenIds, $ids));

        assertEqual(count($ids), count(array_unique($ids)), "batch {$pages} has unique ids");
        assertEqual($duplicates, [], "batch {$pages} has no duplicates with previous batches");

        $seenIds = array_values(array_unique([...$seenIds, ...$ids]));
        $cursor = $batch['next_cursor'];
        $pages++;
    } while ($cursor !== null && $pages < 20);

    assertTrue($pages >= 3, 'cursor pagination produced multiple batches');
    assertEqual(count($seenIds), 58, 'cursor pagination traversed all fire rows exactly once');

    logInfo('Step 4: Verifying URL state round-trip into Inertia filter props');

    $inertiaPayload = makeInertiaPayload([
        'status' => 'active',
        'source' => 'transit',
        'since' => '1h',
        'q' => $searchToken,
    ]);

    assertEqual($inertiaPayload['component'] ?? null, 'gta-alerts', 'inertia component name');
    assertEqual($inertiaPayload['props']['filters']['status'] ?? null, 'active', 'filters.status round-trip');
    assertEqual($inertiaPayload['props']['filters']['source'] ?? null, 'transit', 'filters.source round-trip');
    assertEqual($inertiaPayload['props']['filters']['since'] ?? null, '1h', 'filters.since round-trip');
    assertEqual($inertiaPayload['props']['filters']['q'] ?? null, $searchToken, 'filters.q round-trip');

    $inertiaRows = $inertiaPayload['props']['alerts']['data'] ?? [];
    assertEqual(count($inertiaRows), 1, 'inertia filtered payload returns one row');
    assertEqual($inertiaRows[0]['id'] ?? null, 'transit:api:P4-001', 'inertia filtered row id');

    logInfo('Step 5: Verifying API filter changes produce independent result sets');

    $fireApi = makeJsonPayload([
        'status' => 'active',
        'source' => 'fire',
        'since' => '1h',
        'q' => $searchToken,
    ]);

    $policeApi = makeJsonPayload([
        'status' => 'active',
        'source' => 'police',
        'since' => '1h',
        'q' => $searchToken,
    ]);

    assertEqual(count($fireApi['data'] ?? []), 1, 'api fire filtered payload count');
    assertEqual(($fireApi['data'][0]['id'] ?? null), 'fire:FIRE-P4-001', 'api fire filtered payload id');
    assertEqual(count($policeApi['data'] ?? []), 1, 'api police filtered payload count');
    assertEqual(($policeApi['data'][0]['id'] ?? null), 'police:940001', 'api police filtered payload id');

    logInfo('Step 6: Running quality-gate commands');

    if (getenv('SKIP_COMMAND_GATES') === '1') {
        logInfo('Skipping command gates because SKIP_COMMAND_GATES=1');
    } else {
        runCommand('composer test', 'composer test');
        runCommand('pnpm run lint', 'pnpm run lint');

        if (getenv('RUN_FORMAT') === '1') {
            runCommand('pnpm run format', 'pnpm run format');
        } else {
            logInfo('Skipping pnpm run format (set RUN_FORMAT=1 to enable)');
        }
    }

    echo "\n";
    echo "========================================\n";
    echo "MANUAL BROWSER CHECKLIST (PHASE 4)\n";
    echo "========================================\n";
    echo "1. URL state + Back/Forward\n";
    echo "   - Open /?status=active&source=fire&since=1h&q={$searchToken}\n";
    echo "   - Change source to police, then click browser Back\n";
    echo "   - Confirm UI restores fire filter state from URL\n";
    echo "\n";
    echo "2. Infinite Scroll Behavior\n";
    echo "   - Scroll until next batch loads; confirm no duplicates appear\n";
    echo "   - Continue until 'No more alerts' appears\n";
    echo "\n";
    echo "3. Cards/Table Toggle Client-Side Only\n";
    echo "   - Switch between Cards and Table repeatedly\n";
    echo "   - Confirm URL query params do not change and no feed reload is triggered\n";
    echo "========================================\n";
    echo "Log file: {$logFileRelative}\n";
    echo "========================================\n";

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    try {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
    } catch (\Throwable) {
    }

    if ($txStarted) {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                logInfo('Transaction rolled back (SQLite/non-MySQL path).');
            }
        } catch (\Throwable) {
        }
    } elseif ($driverName === 'mysql') {
        try {
            FireIncident::query()->delete();
            PoliceCall::query()->delete();
            TransitAlert::query()->delete();
            GoTransitAlert::query()->delete();
            logInfo('MySQL cleanup completed.');
        } catch (\Throwable $cleanupError) {
            logError('MySQL cleanup failed', ['message' => $cleanupError->getMessage()]);
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
