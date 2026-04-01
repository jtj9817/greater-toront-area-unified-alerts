<?php
/**
 * Manual Test: YRT Phase 2 Feed Service (List JSON + Conditional Detail HTML)
 * Generated: 2026-04-01
 * Purpose: Verify normalization, conditional detail-fetch behavior, resilience paths,
 * and circuit-breaker integration for YrtServiceAdvisoriesFeedService.
 */

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\YrtAlert;
use App\Services\YrtServiceAdvisoriesFeedService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

if (app()->environment('production')) {
    fwrite(STDERR, "Error: Cannot run manual tests in production.\n");
    exit(1);
}

$testRunId = 'yrt_phase2_'.Carbon::now()->format('Y_m_d_His');
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

function listUrl(): string
{
    return 'https://www.yrt.ca/Modules/NewsModule/services/getServiceAdvisories.ashx?categories=b8f1acba-f043-ec11-9468-0050569c41bf&lang=en';
}

function detailUrl(): string
{
    return 'https://www.yrt.ca/en/news/52-holland-landing-detour.aspx';
}

function listItem(array $overrides = []): array
{
    return array_merge([
        'title' => '52 - Holland Landing detour',
        'description' => 'Routes affected: 52, 58. Temporary detour in effect...',
        'link' => detailUrl(),
        'postedDate' => '03/31/2026',
        'postedTime' => '11:35 PM',
    ], $overrides);
}

function detailHtml(string $reason = 'Utility work.'): string
{
    return <<<HTML
    <html><body><article>
      <h1>Service Advisory</h1>
      <p>When: April 1, 2026</p>
      <p>Routes affected: 52, 58</p>
      <p>Reason: {$reason}</p>
    </article></body></html>
    HTML;
}

try {
    DB::beginTransaction();

    config([
        'cache.default' => 'array',
        'feeds.allow_empty_feeds' => false,
        'feeds.circuit_breaker.enabled' => true,
        'feeds.circuit_breaker.threshold' => 2,
        'feeds.circuit_breaker.ttl_seconds' => 300,
        'feeds.yrt.max_records' => 200,
        'feeds.yrt.details_refresh_hours' => 24,
    ]);

    Cache::flush();

    logInfo("=== Starting Manual Test: {$testRunId} ===");
    logInfo('Phase 1: Setup');

    $service = app(YrtServiceAdvisoriesFeedService::class);

    logInfo('Phase 2A: Happy path normalization + new detail fetch');

    Http::fake([
        listUrl() => Http::response([listItem()], 200),
        'https://www.yrt.ca/en/news/*' => Http::response(detailHtml('Utility work.'), 200),
    ]);

    $result = $service->fetch();
    $alert = $result['alerts'][0] ?? null;

    assertTrue(isset($result['updated_at']) && $result['updated_at']->isUtc(), 'updated_at is UTC');
    assertTrue(is_array($result['alerts']) && count($result['alerts']) === 1, 'exactly one alert returned');
    assertTrue(is_array($alert), 'normalized alert exists');
    assertTrue(($alert['external_id'] ?? null) === '52-holland-landing-detour', 'slug external_id extracted');
    assertTrue(($alert['posted_at'] ?? null)?->toDateTimeString() === '2026-04-01 03:35:00', 'posted_at parsed Toronto->UTC');
    assertTrue(($alert['route_text'] ?? null) === '52 - Holland Landing detour', 'route_text from title prefix');

    $expectedHash = sha1('52 - Holland Landing detour|Routes affected: 52, 58. Temporary detour in effect...|03/31/2026|11:35 PM|'.detailUrl());
    assertTrue(($alert['list_hash'] ?? null) === $expectedHash, 'list_hash deterministic');
    assertTrue(str_contains((string) ($alert['body_text'] ?? ''), 'Reason: Utility work.'), 'body_text extracted from detail HTML');

    logInfo('Phase 2B: Skip detail fetch when hash unchanged and details are fresh');

    YrtAlert::factory()->create([
        'external_id' => '52-holland-landing-detour',
        'title' => '52 - Holland Landing detour',
        'posted_at' => Carbon::parse('2026-04-01 03:35:00', 'UTC'),
        'details_url' => detailUrl(),
        'description_excerpt' => 'Routes affected: 52, 58. Temporary detour in effect...',
        'route_text' => '52 - Holland Landing detour',
        'body_text' => 'Persisted body text',
        'list_hash' => $expectedHash,
        'details_fetched_at' => Carbon::now()->subHours(2),
        'is_active' => true,
    ]);

    Http::fake([
        listUrl() => Http::response([listItem()], 200),
        'https://www.yrt.ca/en/news/*' => Http::response(detailHtml('Should not be fetched'), 200),
    ]);

    $result = $service->fetch();
    $alert = $result['alerts'][0] ?? null;

    assertTrue(($alert['body_text'] ?? null) === 'Persisted body text', 'skip-path preserves existing body text');

    $detailRequests = Http::recorded()->filter(
        fn (array $pair): bool => str_contains($pair[0]->url(), '/en/news/')
    );
    assertTrue($detailRequests->count() === 0, 'skip-path made zero detail requests', ['detail_count' => $detailRequests->count()]);

    logInfo('Phase 2C: Fetch detail when stale');

    YrtAlert::query()->where('external_id', '52-holland-landing-detour')->update([
        'body_text' => 'Old body text',
        'details_fetched_at' => Carbon::now()->subHours(30),
    ]);

    Http::fake([
        listUrl() => Http::response([listItem()], 200),
        'https://www.yrt.ca/en/news/*' => Http::response(detailHtml('Stale refresh'), 200),
    ]);

    $result = $service->fetch();
    $alert = $result['alerts'][0] ?? null;

    assertTrue(($alert['body_text'] ?? null) !== 'Old body text', 'stale-path replaced old body text');

    $detailRequests = Http::recorded()->filter(
        fn (array $pair): bool => str_contains($pair[0]->url(), '/en/news/')
    );
    assertTrue($detailRequests->count() === 1, 'stale-path issued one detail request', ['detail_count' => $detailRequests->count()]);

    logInfo('Phase 2D: Malformed JSON payload failure path');

    Http::fake(['*' => Http::response(['unexpected' => 'shape'], 200)]);

    $threwMalformed = false;
    try {
        $service->fetch();
    } catch (RuntimeException $e) {
        $threwMalformed = str_contains($e->getMessage(), 'invalid payload');
    }
    assertTrue($threwMalformed, 'malformed payload throws expected RuntimeException');

    logInfo('Phase 2E: Malformed detail HTML does not crash fetch');

    Http::fake([
        listUrl() => Http::response([listItem(['description' => 'Route affected: 52'])], 200),
        'https://www.yrt.ca/en/news/*' => Http::response('<html><body><article><p>broken', 200),
    ]);

    $result = $service->fetch();
    assertTrue(count($result['alerts']) === 1, 'malformed detail html still returns normalized alert');

    logInfo('Phase 2F: Circuit breaker opens after repeated failures');

    Cache::flush();
    Http::fake(['*' => Http::failedConnection()]);

    $firstFailed = false;
    $secondFailed = false;
    $thirdOpen = false;

    try {
        $service->fetch();
    } catch (RuntimeException $e) {
        $firstFailed = str_contains($e->getMessage(), 'request failed');
    }

    try {
        $service->fetch();
    } catch (RuntimeException $e) {
        $secondFailed = str_contains($e->getMessage(), 'request failed');
    }

    try {
        $service->fetch();
    } catch (RuntimeException $e) {
        $thirdOpen = str_contains($e->getMessage(), "Circuit breaker open for feed 'yrt'");
    }

    assertTrue($firstFailed && $secondFailed && $thirdOpen, 'circuit breaker opened after threshold failures', [
        'first_failed' => $firstFailed,
        'second_failed' => $secondFailed,
        'third_open' => $thirdOpen,
    ]);

    logInfo('Phase 3: Cleanup');
    DB::rollBack();

    logInfo("=== Manual Test Completed: {$testRunId} ===");
    logInfo('Manual verification assertions passed');

    echo "\n✓ Manual verification passed. Log: {$logFile}\n";
    exit(0);
} catch (Throwable $e) {
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
