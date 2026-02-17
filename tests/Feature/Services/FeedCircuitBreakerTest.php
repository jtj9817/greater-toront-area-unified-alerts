<?php

use App\Services\GoTransitFeedService;
use App\Services\TorontoFireFeedService;
use App\Services\TorontoPoliceFeedService;
use App\Services\TtcAlertsFeedService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

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

    $service = new TorontoFireFeedService;

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

    $service = new TorontoPoliceFeedService;

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

    $service = new GoTransitFeedService;

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

    $service = new TtcAlertsFeedService;

    expect(fn () => $service->fetch())->toThrow(RuntimeException::class);
    expect(fn () => $service->fetch())->toThrow(RuntimeException::class);
    $sentBeforeOpen = count(Http::recorded());
    expect($sentBeforeOpen)->toBeGreaterThan(0);

    expect(fn () => $service->fetch())->toThrow(RuntimeException::class, "Circuit breaker open for feed 'ttc_alerts'");
    expect(count(Http::recorded()))->toBe($sentBeforeOpen);
});
