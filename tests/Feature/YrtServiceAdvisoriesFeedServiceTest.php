<?php

use App\Models\YrtAlert;
use App\Services\FeedCircuitBreaker;
use App\Services\YrtServiceAdvisoriesFeedService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'feeds.allow_empty_feeds' => false,
        'feeds.circuit_breaker.enabled' => false,
    ]);
});

function yrtListItem(array $overrides = []): array
{
    return array_merge([
        'title' => '52 - Holland Landing detour',
        'description' => 'Routes affected: 52, 58. Temporary detour in effect...',
        'link' => 'https://www.yrt.ca/en/news/52-holland-landing-detour.aspx',
        'postedDate' => '03/31/2026',
        'postedTime' => '11:35 PM',
    ], $overrides);
}

function yrtListUrl(): string
{
    return 'https://www.yrt.ca/Modules/NewsModule/services/getServiceAdvisories.ashx?categories=b8f1acba-f043-ec11-9468-0050569c41bf&lang=en';
}

function yrtDetailBody(): string
{
    return <<<'HTML'
    <html>
      <body>
        <article>
          <h1>Service Advisory</h1>
          <p>When: April 1, 2026</p>
          <p>Routes affected: 52, 58</p>
          <p>Reason: Utility work.</p>
        </article>
      </body>
    </html>
    HTML;
}

test('it fetches and normalizes YRT advisories from list json', function () {
    Http::fake([
        yrtListUrl() => Http::response([
            yrtListItem(),
        ], 200),
        'https://www.yrt.ca/en/news/*' => Http::response(yrtDetailBody(), 200),
    ]);

    $result = app(YrtServiceAdvisoriesFeedService::class)->fetch();

    expect($result['updated_at']->isUtc())->toBeTrue();
    expect($result['alerts'])->toHaveCount(1);

    $alert = $result['alerts'][0];

    expect($alert['external_id'])->toBe('52-holland-landing-detour');
    expect($alert['title'])->toBe('52 - Holland Landing detour');
    expect($alert['posted_at']->toDateTimeString())->toBe('2026-04-01 03:35:00');
    expect($alert['details_url'])->toBe('https://www.yrt.ca/en/news/52-holland-landing-detour.aspx');
    expect($alert['description_excerpt'])->toBe('Routes affected: 52, 58. Temporary detour in effect...');
    expect($alert['route_text'])->toBe('52 - Holland Landing detour');
    expect($alert['list_hash'])->toBe(sha1('52 - Holland Landing detour|Routes affected: 52, 58. Temporary detour in effect...|03/31/2026|11:35 PM|https://www.yrt.ca/en/news/52-holland-landing-detour.aspx'));
    expect($alert['body_text'])->toContain('Routes affected: 52, 58');
    expect($alert['details_fetched_at'])->not->toBeNull();
});

test('it derives route text from routes affected segment when title prefix is absent', function () {
    Http::fake([
        yrtListUrl() => Http::response([
            yrtListItem([
                'title' => 'Temporary stop closure near Finch',
                'description' => 'Notice update. Route affected: 86B, 98. Expect delays.',
            ]),
        ], 200),
        'https://www.yrt.ca/en/news/*' => Http::response(yrtDetailBody(), 200),
    ]);

    $result = app(YrtServiceAdvisoriesFeedService::class)->fetch();

    expect($result['alerts'][0]['route_text'])->toBe('86B, 98');
});

test('it fetches detail body for new alerts', function () {
    Http::fake([
        yrtListUrl() => Http::response([yrtListItem()], 200),
        'https://www.yrt.ca/en/news/*' => Http::response(yrtDetailBody(), 200),
    ]);

    $result = app(YrtServiceAdvisoriesFeedService::class)->fetch();

    expect($result['alerts'][0]['body_text'])->toContain('Reason: Utility work.');

    $detailRequests = Http::recorded()->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/en/news/'));
    expect($detailRequests)->toHaveCount(1);
});

test('it fetches detail when list hash changed', function () {
    YrtAlert::factory()->create([
        'external_id' => '52-holland-landing-detour',
        'details_url' => 'https://www.yrt.ca/en/news/52-holland-landing-detour.aspx',
        'list_hash' => sha1('old-hash'),
        'body_text' => 'Existing body',
        'details_fetched_at' => Carbon::now()->subHour(),
    ]);

    Http::fake([
        yrtListUrl() => Http::response([yrtListItem()], 200),
        'https://www.yrt.ca/en/news/*' => Http::response(yrtDetailBody(), 200),
    ]);

    $result = app(YrtServiceAdvisoriesFeedService::class)->fetch();

    expect($result['alerts'][0]['body_text'])->toContain('Service Advisory');
});

test('it fetches detail when existing body is missing', function () {
    $item = yrtListItem();
    $hash = sha1(implode('|', [
        $item['title'],
        $item['description'],
        $item['postedDate'],
        $item['postedTime'],
        $item['link'],
    ]));

    YrtAlert::factory()->create([
        'external_id' => '52-holland-landing-detour',
        'details_url' => 'https://www.yrt.ca/en/news/52-holland-landing-detour.aspx',
        'list_hash' => $hash,
        'body_text' => null,
        'details_fetched_at' => Carbon::now()->subHour(),
    ]);

    Http::fake([
        yrtListUrl() => Http::response([$item], 200),
        'https://www.yrt.ca/en/news/*' => Http::response(yrtDetailBody(), 200),
    ]);

    $result = app(YrtServiceAdvisoriesFeedService::class)->fetch();

    expect($result['alerts'][0]['body_text'])->toContain('Reason: Utility work.');
});

test('it fetches detail when details_fetched_at is stale', function () {
    config(['feeds.yrt.details_refresh_hours' => 24]);

    $item = yrtListItem();
    $hash = sha1(implode('|', [
        $item['title'],
        $item['description'],
        $item['postedDate'],
        $item['postedTime'],
        $item['link'],
    ]));

    YrtAlert::factory()->create([
        'external_id' => '52-holland-landing-detour',
        'details_url' => 'https://www.yrt.ca/en/news/52-holland-landing-detour.aspx',
        'list_hash' => $hash,
        'body_text' => 'Existing body',
        'details_fetched_at' => Carbon::now()->subHours(25),
    ]);

    Http::fake([
        yrtListUrl() => Http::response([$item], 200),
        'https://www.yrt.ca/en/news/*' => Http::response(yrtDetailBody(), 200),
    ]);

    $result = app(YrtServiceAdvisoriesFeedService::class)->fetch();

    expect($result['alerts'][0]['body_text'])->toContain('Service Advisory');
});

test('it preserves existing details when refresh is required but detail fetch fails', function () {
    config(['feeds.yrt.details_refresh_hours' => 24]);

    $item = yrtListItem();
    $hash = sha1(implode('|', [
        $item['title'],
        $item['description'],
        $item['postedDate'],
        $item['postedTime'],
        $item['link'],
    ]));
    $detailsFetchedAt = Carbon::now()->subHours(25);

    YrtAlert::factory()->create([
        'external_id' => '52-holland-landing-detour',
        'details_url' => 'https://www.yrt.ca/en/news/52-holland-landing-detour.aspx',
        'list_hash' => $hash,
        'body_text' => 'Persisted body text',
        'details_fetched_at' => $detailsFetchedAt,
    ]);

    Http::fake([
        yrtListUrl() => Http::response([$item], 200),
        'https://www.yrt.ca/en/news/*' => Http::failedConnection(),
    ]);

    $result = app(YrtServiceAdvisoriesFeedService::class)->fetch();

    expect($result['alerts'][0]['body_text'])->toBe('Persisted body text');
    expect($result['alerts'][0]['details_fetched_at']?->toDateTimeString())->toBe($detailsFetchedAt->toDateTimeString());
});

test('it skips detail fetch when hash is unchanged and body is fresh', function () {
    config(['feeds.yrt.details_refresh_hours' => 24]);

    $item = yrtListItem();
    $hash = sha1(implode('|', [
        $item['title'],
        $item['description'],
        $item['postedDate'],
        $item['postedTime'],
        $item['link'],
    ]));

    YrtAlert::factory()->create([
        'external_id' => '52-holland-landing-detour',
        'details_url' => 'https://www.yrt.ca/en/news/52-holland-landing-detour.aspx',
        'list_hash' => $hash,
        'body_text' => 'Persisted body text',
        'details_fetched_at' => Carbon::now()->subHours(2),
    ]);

    Http::fake([
        yrtListUrl() => Http::response([$item], 200),
        'https://www.yrt.ca/en/news/*' => Http::response(yrtDetailBody(), 200),
    ]);

    $result = app(YrtServiceAdvisoriesFeedService::class)->fetch();

    expect($result['alerts'][0]['body_text'])->toBe('Persisted body text');
    expect($result['alerts'][0]['details_fetched_at'])->not->toBeNull();

    $detailRequests = Http::recorded()->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/en/news/'));
    expect($detailRequests)->toHaveCount(0);
});

test('it throws on network failure for list fetch', function () {
    Http::fake([
        '*' => Http::failedConnection(),
    ]);

    expect(fn () => app(YrtServiceAdvisoriesFeedService::class)->fetch())
        ->toThrow(RuntimeException::class, 'YRT advisories feed request failed');
});

test('it throws on malformed payload shape', function () {
    Http::fake([
        '*' => Http::response(['unexpected' => 'shape'], 200),
    ]);

    expect(fn () => app(YrtServiceAdvisoriesFeedService::class)->fetch())
        ->toThrow(RuntimeException::class, 'YRT advisories feed returned invalid payload');
});

test('it handles malformed detail html without crashing', function () {
    Http::fake([
        yrtListUrl() => Http::response([yrtListItem()], 200),
        'https://www.yrt.ca/en/news/*' => Http::response('<html><body><article><p>broken', 200),
    ]);

    $result = app(YrtServiceAdvisoriesFeedService::class)->fetch();

    expect($result['alerts'])->toHaveCount(1);
    expect($result['alerts'][0]['body_text'])->toBeString();
});

test('it respects allow_empty_feeds for empty payloads', function () {
    config(['feeds.allow_empty_feeds' => false]);

    Http::fake([
        yrtListUrl() => Http::response([], 200),
    ]);

    expect(fn () => app(YrtServiceAdvisoriesFeedService::class)->fetch())
        ->toThrow(RuntimeException::class, 'YRT advisories feed returned zero alerts');

    config(['feeds.allow_empty_feeds' => true]);

    $result = app(YrtServiceAdvisoriesFeedService::class)->fetch();

    expect($result['alerts'])->toBe([]);
});

test('it records circuit breaker success and failure', function () {
    $breaker = $this->mock(FeedCircuitBreaker::class, function (MockInterface $mock): void {
        $mock->shouldReceive('throwIfOpen')->once()->with('yrt');
        $mock->shouldReceive('recordSuccess')->once()->with('yrt');
        $mock->shouldReceive('recordFailure')->never();
    });

    Http::fake([
        yrtListUrl() => Http::response([yrtListItem()], 200),
        'https://www.yrt.ca/en/news/*' => Http::response(yrtDetailBody(), 200),
    ]);

    $service = new YrtServiceAdvisoriesFeedService($breaker);
    $service->fetch();

    $breaker = $this->mock(FeedCircuitBreaker::class, function (MockInterface $mock): void {
        $mock->shouldReceive('throwIfOpen')->once()->with('yrt');
        $mock->shouldReceive('recordSuccess')->never();
        $mock->shouldReceive('recordFailure')->once()->withArgs(fn (string $feed, \Throwable $exception): bool => $feed === 'yrt' && $exception instanceof RuntimeException);
    });

    Http::fake(['*' => Http::failedConnection()]);

    $service = new YrtServiceAdvisoriesFeedService($breaker);

    expect(fn () => $service->fetch())->toThrow(RuntimeException::class);
});
