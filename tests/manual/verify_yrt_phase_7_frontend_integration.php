<?php

/**
 * Manual Test: YRT Phase 7 Frontend Domain + Presentation Integration
 * Generated: 2026-04-02
 * Purpose: Verify YRT alert payload shape sent to Inertia/frontend is valid for
 * domain mapping and presentation paths introduced in Phase 7.
 */

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\FireIncident;
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

$testRunId = 'yrt_phase7_'.now()->format('Y_m_d_His');
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

try {
    DB::beginTransaction();

    logInfo("=== Starting Manual Test: {$testRunId} ===");

    $httpKernel = app(HttpKernel::class);
    $inertiaMiddleware = app(HandleInertiaRequests::class);

    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-04-02 12:00:00'));

    logInfo('Phase 1: Setup deterministic dataset');

    FireIncident::query()->delete();
    YrtAlert::query()->delete();

    FireIncident::factory()->create([
        'event_num' => 'F-PH7-001',
        'is_active' => true,
        'dispatch_time' => CarbonImmutable::now()->subMinutes(12),
        'feed_updated_at' => CarbonImmutable::now()->subMinutes(12),
    ]);

    YrtAlert::factory()->create([
        'external_id' => '97001',
        'title' => '97 - test detour',
        'posted_at' => CarbonImmutable::now()->subMinutes(2),
        'details_url' => 'https://www.yrt.ca/en/service-updates/97001.aspx',
        'description_excerpt' => 'Routing around temporary closure',
        'route_text' => '97',
        'body_text' => 'Use temporary stop on Main Street.',
        'list_hash' => sha1('97001-list'),
        'details_fetched_at' => CarbonImmutable::now()->subMinute(),
        'is_active' => true,
        'feed_updated_at' => CarbonImmutable::now()->subMinute(),
    ]);

    logInfo('Phase 2A: Verify source=yrt feed payload includes frontend-required shape');

    $payload = makeInertiaPayload($httpKernel, $inertiaMiddleware, ['source' => 'yrt']);
    assertTrue(($payload['component'] ?? null) === 'gta-alerts', 'home inertia component remains gta-alerts');

    $filters = $payload['props']['filters'] ?? null;
    assertTrue(is_array($filters), 'filters payload exists');
    assertTrue(($filters['source'] ?? null) === 'yrt', 'filters.source reflects yrt query');

    $rows = $payload['props']['alerts']['data'] ?? null;
    assertTrue(is_array($rows), 'alerts.data is array');
    assertTrue(count($rows) === 1, 'source=yrt narrows to one row in seeded dataset', ['count' => is_array($rows) ? count($rows) : null]);

    /** @var array<string,mixed> $row */
    $row = $rows[0];
    assertTrue(($row['id'] ?? null) === 'yrt:97001', 'row id is yrt-prefixed id', ['id' => $row['id'] ?? null]);
    assertTrue(($row['source'] ?? null) === 'yrt', 'row source is yrt');
    assertTrue(($row['external_id'] ?? null) === '97001', 'external_id remains unprefixed');
    assertTrue(($row['is_active'] ?? null) === true, 'row is active');
    assertTrue(($row['title'] ?? null) === '97 - test detour', 'row title is preserved');

    $location = $row['location'] ?? null;
    assertTrue(is_array($location), 'location exists');
    assertTrue(($location['name'] ?? null) === '97', 'location.name maps from route_text', ['location' => $location]);
    assertTrue(($location['lat'] ?? null) === null, 'location.lat remains null for yrt');
    assertTrue(($location['lng'] ?? null) === null, 'location.lng remains null for yrt');

    $meta = $row['meta'] ?? null;
    assertTrue(is_array($meta), 'meta exists');
    assertTrue(array_key_exists('details_url', $meta), 'meta includes details_url');
    assertTrue(array_key_exists('description_excerpt', $meta), 'meta includes description_excerpt');
    assertTrue(array_key_exists('body_text', $meta), 'meta includes body_text');
    assertTrue(array_key_exists('posted_at', $meta), 'meta includes posted_at');
    assertTrue(array_key_exists('feed_updated_at', $meta), 'meta includes feed_updated_at');
    assertTrue(($meta['details_url'] ?? null) === 'https://www.yrt.ca/en/service-updates/97001.aspx', 'meta.details_url value preserved');
    assertTrue(($meta['description_excerpt'] ?? null) === 'Routing around temporary closure', 'meta.description_excerpt value preserved');
    assertTrue(($meta['body_text'] ?? null) === 'Use temporary stop on Main Street.', 'meta.body_text value preserved');
    assertTrue(is_string($meta['posted_at'] ?? null), 'meta.posted_at serializes as ISO timestamp string');
    assertTrue(is_string($meta['feed_updated_at'] ?? null), 'meta.feed_updated_at serializes as ISO timestamp string');

    logInfo('Phase 2B: Verify latest_feed_updated_at includes YRT recency');

    $latestFeedUpdatedAt = $payload['props']['latest_feed_updated_at'] ?? null;
    $expectedLatest = CarbonImmutable::now()->subMinute()->toIso8601String();
    assertTrue($latestFeedUpdatedAt === $expectedLatest, 'latest_feed_updated_at prefers newest yrt feed timestamp', [
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
