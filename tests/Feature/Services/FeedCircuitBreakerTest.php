<?php

use App\Services\GoTransitFeedService;
use App\Services\TorontoFireFeedService;
use App\Services\TorontoPoliceFeedService;
use App\Services\TtcAlertsFeedService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-02-17 00:00:00', 'UTC'));

    config([
        'cache.default' => 'array',
        'feeds.circuit_breaker.enabled' => true,
        'feeds.circuit_breaker.threshold' => 2,
        'feeds.circuit_breaker.ttl_seconds' => 60,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('it does nothing when the circuit breaker is disabled', function () {
    config(['feeds.circuit_breaker.enabled' => false]);

    Cache::put('feeds:circuit_breaker:disabled_feed', 999, 60);

    $breaker = app(\App\Services\FeedCircuitBreaker::class);
    $breaker->throwIfOpen('disabled_feed');

    $breaker->recordFailure('disabled_feed', new RuntimeException('test'));
    expect(Cache::get('feeds:circuit_breaker:disabled_feed'))->toBe(999);

    $breaker->recordSuccess('disabled_feed');
    expect(Cache::get('feeds:circuit_breaker:disabled_feed'))->toBe(999);
});

test('it logs and throws when the breaker is open', function () {
    Log::spy();
    config([
        'feeds.circuit_breaker.enabled' => true,
        'feeds.circuit_breaker.threshold' => 1,
        'feeds.circuit_breaker.ttl_seconds' => 60,
    ]);

    $breaker = app(\App\Services\FeedCircuitBreaker::class);
    $breaker->recordFailure('test_feed', new RuntimeException('upstream error'));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Feed circuit breaker opened'
            && ($context['feed'] ?? null) === 'test_feed'
            && ($context['failures'] ?? null) === 1
            && ($context['threshold'] ?? null) === 1)
        ->once();

    expect(fn () => $breaker->throwIfOpen('test_feed'))->toThrow(RuntimeException::class, "Circuit breaker open for feed 'test_feed'");

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Feed circuit breaker is open; skipping fetch attempt'
            && ($context['feed'] ?? null) === 'test_feed'
            && ($context['failures'] ?? null) === 1
            && ($context['threshold'] ?? null) === 1)
        ->once();
});

test('it tolerates cache failures when checking breaker state', function () {
    Log::spy();
    config(['feeds.circuit_breaker.enabled' => true]);

    Cache::shouldReceive('get')->andThrow(new RuntimeException('cache down'));

    $breaker = app(\App\Services\FeedCircuitBreaker::class);
    $breaker->throwIfOpen('test_feed');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Failed to evaluate feed circuit breaker state; proceeding without breaker'
            && ($context['feed'] ?? null) === 'test_feed')
        ->once();
});

test('it tolerates cache failures when recording breaker success', function () {
    Log::spy();
    config(['feeds.circuit_breaker.enabled' => true]);

    Cache::shouldReceive('forget')->andThrow(new RuntimeException('cache down'));

    $breaker = app(\App\Services\FeedCircuitBreaker::class);
    $breaker->recordSuccess('test_feed');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Failed to clear feed circuit breaker state'
            && ($context['feed'] ?? null) === 'test_feed')
        ->once();
});

test('it tolerates cache failures when recording breaker failure', function () {
    Log::spy();
    config([
        'feeds.circuit_breaker.enabled' => true,
        'feeds.circuit_breaker.threshold' => 5,
        'feeds.circuit_breaker.ttl_seconds' => 60,
    ]);

    Cache::shouldReceive('get')->andReturn(0);
    Cache::shouldReceive('put')->andThrow(new RuntimeException('cache down'));

    $breaker = app(\App\Services\FeedCircuitBreaker::class);
    $breaker->recordFailure('test_feed', new RuntimeException('upstream error'));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Failed to update feed circuit breaker state'
            && ($context['feed'] ?? null) === 'test_feed'
            && ($context['original_exception_class'] ?? null) === RuntimeException::class)
        ->once();
});

test('it treats non-int cache values as zero when recording failures', function () {
    config([
        'feeds.circuit_breaker.enabled' => true,
        'feeds.circuit_breaker.threshold' => 5,
        'feeds.circuit_breaker.ttl_seconds' => 60,
    ]);

    Cache::put('feeds:circuit_breaker:test_feed', 'not-an-int', 60);

    $breaker = app(\App\Services\FeedCircuitBreaker::class);
    $breaker->recordFailure('test_feed', new RuntimeException('upstream error'));

    expect(Cache::get('feeds:circuit_breaker:test_feed'))->toBe(1);
});

test('it clamps invalid threshold and ttl configuration values', function () {
    Log::spy();
    config([
        'feeds.circuit_breaker.enabled' => true,
        'feeds.circuit_breaker.threshold' => 0,
        'feeds.circuit_breaker.ttl_seconds' => 0,
    ]);

    $breaker = app(\App\Services\FeedCircuitBreaker::class);
    $breaker->recordFailure('test_feed', new RuntimeException('upstream error'));

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Feed circuit breaker opened'
            && ($context['threshold'] ?? null) === 1
            && ($context['ttl_seconds'] ?? null) === 1)
        ->once();
});

test('it trims feed names when building cache keys', function () {
    config([
        'feeds.circuit_breaker.enabled' => true,
        'feeds.circuit_breaker.threshold' => 5,
        'feeds.circuit_breaker.ttl_seconds' => 60,
    ]);

    $breaker = app(\App\Services\FeedCircuitBreaker::class);
    $breaker->recordFailure('  test_feed  ', new RuntimeException('upstream error'));

    expect(Cache::get('feeds:circuit_breaker:test_feed'))->toBe(1);
});

test('circuit breaker opens after threshold and recovers after ttl (fire feed)', function () {
    $shouldSucceed = false;
    $xml = <<<'XML'
    <tfs_active_incidents>
      <update_from_db_time>2026-02-17 00:00:00</update_from_db_time>
      <event>
        <event_num>E001</event_num>
        <event_type>Fire</event_type>
        <prime_street>Test St</prime_street>
        <cross_streets>Test Ave</cross_streets>
        <dispatch_time>2026-02-17T00:00:00</dispatch_time>
        <alarm_lev>1</alarm_lev>
        <beat>B1</beat>
        <units_disp>U1</units_disp>
      </event>
    </tfs_active_incidents>
    XML;

    Http::fake(function (\Illuminate\Http\Client\Request $request) use (&$shouldSucceed, $xml) {
        if (str_starts_with($request->url(), 'https://www.toronto.ca/data/fire/livecad.xml')) {
            return $shouldSucceed
                ? Http::response($xml, 200, ['Content-Type' => 'text/xml'])
                : Http::response('upstream error', 500);
        }

        return Http::response('unexpected url', 500);
    });

    $service = app(TorontoFireFeedService::class);

    expect(fn () => $service->fetch())->toThrow(RuntimeException::class);
    expect(fn () => $service->fetch())->toThrow(RuntimeException::class);

    $sentBeforeOpen = count(Http::recorded());
    expect($sentBeforeOpen)->toBeGreaterThan(0);

    expect(fn () => $service->fetch())->toThrow(RuntimeException::class, "Circuit breaker open for feed 'toronto_fire'");
    expect(count(Http::recorded()))->toBe($sentBeforeOpen);

    Carbon::setTestNow(Carbon::now('UTC')->addSeconds(61));

    $shouldSucceed = true;

    $result = $service->fetch();

    expect($result['events'])->toHaveCount(1);
    expect(count(Http::recorded()))->toBeGreaterThan($sentBeforeOpen);
});

test('circuit breaker blocks repeated failures (police feed)', function () {
    Http::fake([
        '*' => Http::response([], 500),
    ]);

    $service = app(TorontoPoliceFeedService::class);

    expect(fn () => $service->fetch())->toThrow(RuntimeException::class);
    expect(fn () => $service->fetch())->toThrow(RuntimeException::class);
    $sentBeforeOpen = count(Http::recorded());
    expect($sentBeforeOpen)->toBeGreaterThan(0);

    expect(fn () => $service->fetch())->toThrow(RuntimeException::class, "Circuit breaker open for feed 'toronto_police'");
    expect(count(Http::recorded()))->toBe($sentBeforeOpen);
});

test('circuit breaker blocks repeated failures (go transit feed)', function () {
    Http::fake([
        'https://api.metrolinx.com/external/go/serviceupdate/en/all*' => Http::response([], 500),
    ]);

    $service = app(GoTransitFeedService::class);

    expect(fn () => $service->fetch())->toThrow(RuntimeException::class);
    expect(fn () => $service->fetch())->toThrow(RuntimeException::class);
    $sentBeforeOpen = count(Http::recorded());
    expect($sentBeforeOpen)->toBeGreaterThan(0);

    expect(fn () => $service->fetch())->toThrow(RuntimeException::class, "Circuit breaker open for feed 'go_transit'");
    expect(count(Http::recorded()))->toBe($sentBeforeOpen);
});

test('circuit breaker blocks repeated failures (ttc alerts feed)', function () {
    Http::fake([
        'https://alerts.ttc.ca/api/alerts/live-alerts*' => Http::response([], 500),
    ]);

    $service = app(TtcAlertsFeedService::class);

    expect(fn () => $service->fetch())->toThrow(RuntimeException::class);
    expect(fn () => $service->fetch())->toThrow(RuntimeException::class);
    $sentBeforeOpen = count(Http::recorded());
    expect($sentBeforeOpen)->toBeGreaterThan(0);

    expect(fn () => $service->fetch())->toThrow(RuntimeException::class, "Circuit breaker open for feed 'ttc_alerts'");
    expect(count(Http::recorded()))->toBe($sentBeforeOpen);
});
