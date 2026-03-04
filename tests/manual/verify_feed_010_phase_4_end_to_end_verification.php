<?php

/**
 * Manual Test: FEED-010 Phase 4 End-to-End Verification (SQLite + MySQL + PostgreSQL)
 * Purpose: Verify pgsql end-to-end /api/feed behavior, filter determinism, and metadata invariants.
 */

use App\Enums\IncidentUpdateType;
use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\IncidentUpdate;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

require __DIR__.'/../../vendor/autoload.php';

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
    exit("Error: Manual tests must run with APP_ENV=testing.\n");
}

$connection = config('database.default');
$currentDatabase = (string) config("database.connections.{$connection}.database");

umask(002);

$testRunId = 'feed_010_phase_4_end_to_end_verification_'.Carbon::now()->format('Y_m_d_His');
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
        throw new RuntimeException($message);
    }

    logInfo("Assertion passed: {$label}");
}

/**
 * @return array{status:int, body:array<string,mixed>}
 */
function sendJsonRequest(HttpKernel $httpKernel, string $path, array $query = []): array
{
    $request = Request::create($path, 'GET', $query, [], [], ['HTTP_ACCEPT' => 'application/json']);
    $response = $httpKernel->handle($request);
    $status = $response->getStatusCode();
    $content = (string) $response->getContent();

    $httpKernel->terminate($request, $response);

    try {
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        throw new RuntimeException("{$path} returned invalid JSON.", previous: $e);
    }

    if (! is_array($decoded)) {
        throw new RuntimeException("{$path} JSON payload was not an array.");
    }

    return ['status' => $status, 'body' => $decoded];
}

/**
 * @return array<int,array<string,mixed>>
 */
function assertFeedResponseShape(array $responseBody, string $label): array
{
    assertTrue(array_key_exists('data', $responseBody), "{$label} response has data");
    assertTrue(array_key_exists('next_cursor', $responseBody), "{$label} response has next_cursor");
    assertTrue(is_array($responseBody['data']), "{$label} data is array");

    /** @var array<int,array<string,mixed>> $data */
    $data = $responseBody['data'];

    return $data;
}

function findRowByExternalId(array $rows, string $externalId): ?array
{
    foreach ($rows as $row) {
        if (($row['external_id'] ?? null) === $externalId) {
            return $row;
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
            'Database connection failed. If using Sail, run: ./scripts/run-manual-test.sh --db pgsql --env-file .env.testing.pgsql tests/manual/verify_feed_010_phase_4_end_to_end_verification.php',
            previous: $e,
        );
    }

    if (DB::getDriverName() !== 'pgsql') {
        throw new RuntimeException('This manual test requires DB_CONNECTION=pgsql.');
    }

    logInfo('Running with testing database', ['connection' => $connection, 'database' => $currentDatabase]);

    DB::beginTransaction();
    $txStarted = true;

    logInfo('=== Starting Manual Test: FEED-010 Phase 4 End-to-End Verification ===');

    $httpKernel = app(HttpKernel::class);

    $baseTime = CarbonImmutable::parse('2026-02-27 12:00:00', 'UTC');
    Carbon::setTestNow($baseTime);

    IncidentUpdate::query()->delete();
    FireIncident::query()->delete();
    PoliceCall::query()->delete();
    TransitAlert::query()->delete();
    GoTransitAlert::query()->delete();

    FireIncident::factory()->create([
        'event_num' => 'F-P4-ACTIVE-WITH-UPDATES',
        'event_type' => 'ALARM FEED010PHASE4TOKEN',
        'prime_street' => 'Bay Street',
        'cross_streets' => 'King Street',
        'dispatch_time' => $baseTime->subMinutes(5),
        'is_active' => true,
    ]);

    IncidentUpdate::factory()->create([
        'event_num' => 'F-P4-ACTIVE-WITH-UPDATES',
        'update_type' => IncidentUpdateType::RESOURCE_STATUS,
        'content' => 'Units en route',
        'created_at' => $baseTime->subMinutes(3),
        'updated_at' => $baseTime->subMinutes(3),
    ]);

    FireIncident::factory()->inactive()->create([
        'event_num' => 'F-P4-CLEARED-NO-UPDATES',
        'event_type' => 'MEDICAL ASSIST',
        'prime_street' => 'Bloor Street',
        'cross_streets' => 'Yonge Street',
        'dispatch_time' => $baseTime->subMinutes(40),
        'is_active' => false,
    ]);

    PoliceCall::factory()->create([
        'object_id' => 440001,
        'call_type' => 'THEFT OVER',
        'cross_streets' => 'Queen Street - University Avenue',
        'occurrence_time' => $baseTime->subMinutes(10),
        'is_active' => true,
    ]);

    TransitAlert::factory()->create([
        'external_id' => 'ttc:phase4:1',
        'title' => 'Line 1 delay',
        'description' => 'Signal issue',
        'active_period_start' => $baseTime->subMinutes(15),
        'is_active' => true,
    ]);

    GoTransitAlert::factory()->create([
        'external_id' => 'go:phase4:1',
        'message_subject' => 'Minor delays',
        'message_body' => 'Expect 10 minute delays',
        'posted_at' => $baseTime->subMinutes(20),
        'is_active' => true,
    ]);

    logInfo('Step 1: Smoke test GET / and GET /api/feed on pgsql');
    $homeRequest = Request::create('/', 'GET');
    $homeResponse = $httpKernel->handle($homeRequest);
    $homeStatus = $homeResponse->getStatusCode();
    $httpKernel->terminate($homeRequest, $homeResponse);
    assertTrue($homeStatus === 200, '/ returns 200');

    $feedBase = sendJsonRequest($httpKernel, '/api/feed');
    assertTrue($feedBase['status'] === 200, '/api/feed returns 200 without filters');
    $baseRows = assertFeedResponseShape($feedBase['body'], '/api/feed');
    assertTrue(count($baseRows) === 5, '/api/feed returns seeded rows');

    logInfo('Step 2: Verify deterministic filter behavior (status, source, since, cursor)');
    $active = sendJsonRequest($httpKernel, '/api/feed', ['status' => 'active']);
    assertTrue($active['status'] === 200, 'status=active returns 200');
    $activeRows = assertFeedResponseShape($active['body'], 'status=active');
    assertTrue(count($activeRows) === 4, 'status=active returns only active rows');

    $cleared = sendJsonRequest($httpKernel, '/api/feed', ['status' => 'cleared']);
    assertTrue($cleared['status'] === 200, 'status=cleared returns 200');
    $clearedRows = assertFeedResponseShape($cleared['body'], 'status=cleared');
    assertTrue(count($clearedRows) === 1, 'status=cleared returns only cleared rows');

    $fireOnly = sendJsonRequest($httpKernel, '/api/feed', ['source' => 'fire']);
    assertTrue($fireOnly['status'] === 200, 'source=fire returns 200');
    $fireRows = assertFeedResponseShape($fireOnly['body'], 'source=fire');
    assertTrue(count($fireRows) === 2, 'source=fire returns both fire rows');

    $since = sendJsonRequest($httpKernel, '/api/feed', ['since' => '30m']);
    assertTrue($since['status'] === 200, 'since=30m returns 200');
    $sinceRows = assertFeedResponseShape($since['body'], 'since=30m');
    assertTrue(count($sinceRows) === 4, 'since=30m excludes older cleared fire row');

    $page1 = sendJsonRequest($httpKernel, '/api/feed', ['per_page' => 2]);
    assertTrue($page1['status'] === 200, 'cursor page 1 returns 200');
    $page1Rows = assertFeedResponseShape($page1['body'], 'cursor page 1');
    assertTrue(count($page1Rows) === 2, 'cursor page 1 returns 2 rows');
    $cursor = $page1['body']['next_cursor'] ?? null;
    assertTrue(is_string($cursor) && $cursor !== '', 'cursor page 1 emits next_cursor');

    $page2 = sendJsonRequest($httpKernel, '/api/feed', ['per_page' => 2, 'cursor' => $cursor]);
    assertTrue($page2['status'] === 200, 'cursor page 2 returns 200');
    $page2Rows = assertFeedResponseShape($page2['body'], 'cursor page 2');
    assertTrue(count($page2Rows) >= 1, 'cursor page 2 returns rows');
    $page1Ids = array_column($page1Rows, 'id');
    $page2Ids = array_column($page2Rows, 'id');
    assertTrue(count(array_intersect($page1Ids, $page2Ids)) === 0, 'cursor pagination has no duplicate ids between pages');

    logInfo('Step 3: Verify q filter behavior and no QueryException paths');
    $noQuery = sendJsonRequest($httpKernel, '/api/feed', ['source' => 'fire']);
    assertTrue($noQuery['status'] === 200, 'fire feed without q returns 200');
    $noQueryRows = assertFeedResponseShape($noQuery['body'], 'fire feed without q');

    $withQuery = sendJsonRequest($httpKernel, '/api/feed', ['source' => 'fire', 'q' => 'FEED010PHASE4TOKEN']);
    assertTrue($withQuery['status'] === 200, 'fire feed with q returns 200');
    $withQueryRows = assertFeedResponseShape($withQuery['body'], 'fire feed with q');
    assertTrue(count($withQueryRows) < count($noQueryRows), 'q filter reduces fire result set');
    assertTrue(count($withQueryRows) === 1, 'q filter returns expected single fire row');
    assertTrue(($withQueryRows[0]['external_id'] ?? null) === 'F-P4-ACTIVE-WITH-UPDATES', 'q filter returns matching fire external_id');

    logInfo('Step 4: Verify fire meta invariants in full feed response');
    foreach ($baseRows as $row) {
        if (($row['source'] ?? null) !== 'fire') {
            continue;
        }

        $externalId = (string) ($row['external_id'] ?? '');
        $meta = $row['meta'] ?? null;

        assertTrue(is_array($meta), "fire {$externalId} meta is array");
        assertTrue(array_key_exists('intel_summary', $meta), "fire {$externalId} has intel_summary key");
        assertTrue(is_array($meta['intel_summary']), "fire {$externalId} intel_summary is array");

        if ($externalId === 'F-P4-CLEARED-NO-UPDATES') {
            assertTrue($meta['intel_summary'] === [], 'fire no-updates intel_summary is empty array');
            assertTrue(($meta['intel_last_updated'] ?? null) === null, 'fire no-updates intel_last_updated is null');
        }

        if ($externalId === 'F-P4-ACTIVE-WITH-UPDATES') {
            $lastUpdated = $meta['intel_last_updated'] ?? null;
            assertTrue(is_string($lastUpdated), 'fire updates intel_last_updated is string');
            assertTrue(
                preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $lastUpdated) === 1,
                'fire updates intel_last_updated is ISO-8601 with timezone offset',
                ['value' => $lastUpdated],
            );
            assertTrue(count($meta['intel_summary']) >= 1, 'fire updates intel_summary contains entries');
        }
    }

    logInfo('=== Manual Test Completed Successfully ===');
} catch (Throwable $e) {
    $exitCode = 1;
    logError('Manual Test Failed', [
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
    ]);
} finally {
    try {
        Paginator::currentPageResolver(fn () => 1);
    } catch (Throwable) {
    }

    try {
        Carbon::setTestNow();
    } catch (Throwable) {
    }

    if ($txStarted) {
        try {
            if (DB::connection()->transactionLevel() > 0) {
                DB::rollBack();
                logInfo('Transaction rolled back (Database preserved).');
            }
        } catch (Throwable) {
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
