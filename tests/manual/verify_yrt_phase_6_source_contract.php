<?php

/**
 * Manual Test: YRT Phase 6 Source Enum + Backend Contract Plumbing
 * Generated: 2026-04-02
 * Purpose: Verify YRT enum identity, feed API source/status filtering contract,
 * and homepage latest_feed_updated_at precedence including YRT.
 */

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Enums\AlertSource;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\FireIncident;
use App\Models\MiwayAlert;
use App\Models\YrtAlert;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

if (app()->environment('production')) {
    fwrite(STDERR, "Error: Cannot run manual tests in production.\n");
    exit(1);
}

$testRunId = 'yrt_phase6_'.now()->format('Y_m_d_His');
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

function assertTrue(bool $condition, string $message, array $context = []): void
{
    if (! $condition) {
        throw new RuntimeException('Assertion failed: '.$message.' '.json_encode($context, JSON_THROW_ON_ERROR));
    }
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
 * @return array<string,mixed>
 */
function makeInertiaPayload(HttpKernel $httpKernel, HandleInertiaRequests $inertiaMiddleware, array $query = []): array
{
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
    $httpKernel->terminate($request, $response);

    if ($response->getStatusCode() === 409) {
        $location = $response->headers->get('X-Inertia-Location') ?? $response->headers->get('Location');
        $suffix = $location ? " Location: {$location}" : '';
        throw new RuntimeException("Inertia asset version mismatch (409).{$suffix}");
    }

    $payload = json_decode($response->getContent() ?: 'null', true);
    if (! is_array($payload)) {
        throw new RuntimeException('Expected Inertia JSON payload but got non-JSON response.');
    }

    return $payload;
}

/**
 * @return array<int,array<string,mixed>>
 */
function feedRows(array $responseBody): array
{
    assertTrue(array_key_exists('data', $responseBody), 'feed response has data key');
    assertTrue(is_array($responseBody['data']), 'feed response data is array');

    /** @var array<int,array<string,mixed>> $rows */
    $rows = $responseBody['data'];

    return $rows;
}

try {
    DB::beginTransaction();

    logInfo("=== Starting Manual Test: {$testRunId} ===");

    $httpKernel = app(HttpKernel::class);
    $inertiaMiddleware = app(HandleInertiaRequests::class);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-02 12:00:00'));

    logInfo('Phase 1: Setup deterministic dataset');

    FireIncident::query()->delete();
    MiwayAlert::query()->delete();
    YrtAlert::query()->delete();

    FireIncident::factory()->create([
        'event_num' => 'F-PH6-001',
        'is_active' => true,
        'dispatch_time' => CarbonImmutable::now()->subMinutes(3),
        'feed_updated_at' => CarbonImmutable::now()->subMinutes(4),
    ]);

    YrtAlert::factory()->create([
        'external_id' => '91001',
        'is_active' => true,
        'posted_at' => CarbonImmutable::now()->subMinutes(2),
        'feed_updated_at' => CarbonImmutable::now()->subMinute(),
    ]);

    YrtAlert::factory()->create([
        'external_id' => '91002',
        'is_active' => false,
        'posted_at' => CarbonImmutable::now()->subMinutes(5),
        'feed_updated_at' => CarbonImmutable::now()->subMinutes(6),
    ]);

    MiwayAlert::factory()->create([
        'external_id' => 'miway:ph6:001',
        'header_text' => 'MiWay baseline advisory',
        'is_active' => true,
        'starts_at' => CarbonImmutable::now()->subMinutes(8),
        'feed_updated_at' => CarbonImmutable::now()->subMinutes(5),
    ]);

    logInfo('Phase 2A: Verify AlertSource enum contracts include yrt');

    assertTrue(
        AlertSource::values() === ['fire', 'police', 'transit', 'go_transit', 'miway', 'yrt'],
        'AlertSource::values() includes yrt in expected order',
        ['values' => AlertSource::values()]
    );
    assertTrue(AlertSource::isValid('yrt'), 'AlertSource::isValid accepts yrt');
    assertTrue(! AlertSource::isValid('unknown'), 'AlertSource::isValid rejects unknown');

    logInfo('Phase 2B: Verify /api/feed source and status contract for yrt');

    $yrtResponse = sendJsonRequest($httpKernel, '/api/feed', ['source' => 'yrt']);
    assertTrue($yrtResponse['status'] === 200, 'source=yrt returns 200');
    $yrtRows = feedRows($yrtResponse['body']);
    assertTrue(count($yrtRows) === 2, 'source=yrt returns both yrt rows', ['count' => count($yrtRows)]);
    assertTrue(collect($yrtRows)->every(fn (array $row): bool => ($row['source'] ?? null) === 'yrt'), 'source=yrt returns only yrt rows');

    $activeResponse = sendJsonRequest($httpKernel, '/api/feed', ['source' => 'yrt', 'status' => 'active']);
    assertTrue($activeResponse['status'] === 200, 'source=yrt&status=active returns 200');
    $activeRows = feedRows($activeResponse['body']);
    assertTrue(count($activeRows) === 1, 'source=yrt&status=active returns one row');
    assertTrue(($activeRows[0]['external_id'] ?? null) === '91001', 'active filter returns expected external_id', ['row' => $activeRows[0] ?? null]);
    assertTrue(($activeRows[0]['is_active'] ?? null) === true, 'active filter returns active row');

    $clearedResponse = sendJsonRequest($httpKernel, '/api/feed', ['source' => 'yrt', 'status' => 'cleared']);
    assertTrue($clearedResponse['status'] === 200, 'source=yrt&status=cleared returns 200');
    $clearedRows = feedRows($clearedResponse['body']);
    assertTrue(count($clearedRows) === 1, 'source=yrt&status=cleared returns one row');
    assertTrue(($clearedRows[0]['external_id'] ?? null) === '91002', 'cleared filter returns expected external_id', ['row' => $clearedRows[0] ?? null]);
    assertTrue(($clearedRows[0]['is_active'] ?? null) === false, 'cleared filter returns inactive row');

    $fireResponse = sendJsonRequest($httpKernel, '/api/feed', ['source' => 'fire']);
    assertTrue($fireResponse['status'] === 200, 'source=fire returns 200');
    $fireRows = feedRows($fireResponse['body']);
    assertTrue(count($fireRows) === 1, 'source=fire remains unaffected by yrt plumbing');
    assertTrue(($fireRows[0]['external_id'] ?? null) === 'F-PH6-001', 'source=fire returns expected fire record');

    logInfo('Phase 2C: Verify home latest_feed_updated_at prefers most-recent yrt');

    $payload = makeInertiaPayload($httpKernel, $inertiaMiddleware);
    $latestFeedUpdatedAt = $payload['props']['latest_feed_updated_at'] ?? null;
    $expectedLatest = CarbonImmutable::now()->subMinute()->toIso8601String();

    assertTrue(($payload['component'] ?? null) === 'gta-alerts', 'home inertia component remains gta-alerts');
    assertTrue($latestFeedUpdatedAt === $expectedLatest, 'latest_feed_updated_at is selected from yrt when most recent', [
        'actual' => $latestFeedUpdatedAt,
        'expected' => $expectedLatest,
    ]);

    logInfo('Manual verification assertions passed');
    logInfo('Phase 3: Cleanup');

    CarbonImmutable::setTestNow();
    DB::rollBack();

    logInfo('Database transaction rolled back');
    logInfo("=== Manual Test Completed: {$testRunId} ===");

    echo "\n✓ Manual verification passed. Log: {$logFile}\n";
    exit(0);
} catch (Throwable $e) {
    CarbonImmutable::setTestNow();

    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    logError('Manual verification failed', [
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    echo "\n✗ Manual verification failed. Log: {$logFile}\n";
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}
