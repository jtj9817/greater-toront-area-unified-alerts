<?php

use App\Models\DrtAlert;
use App\Services\DrtServiceAlertsFeedService;
use App\Services\FeedCircuitBreaker;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery\MockInterface;

use function Pest\Laravel\mock;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'feeds.allow_empty_feeds' => false,
        'feeds.circuit_breaker.enabled' => false,
        'feeds.drt.max_pages' => 10,
        'feeds.drt.details_refresh_hours' => 24,
    ]);
});

function drtFixture(string $name): string
{
    return file_get_contents(base_path('tests/fixtures/drt/'.$name));
}

function drtListUrl(int $page = 1): string
{
    $base = 'https://www.durhamregiontransit.com/Modules/News/en/ServiceAlertsandDetours';

    return $page > 1 ? "{$base}?page={$page}" : $base;
}

function drtDetailUrlConlin(): string
{
    return 'https://www.durhamregiontransit.com/en/news/conlin-and-grandview-detour-update-on-february-24.aspx';
}

function drtFakeListAndDetailFixtures(): void
{
    $listPageOne = drtFixture('list-page-1.html');
    $listPageTwo = drtFixture('list-page-2.html');
    $detailConlin = drtFixture('detail-route-singular-conlin-grandview.html');
    $detailRoutes = drtFixture('detail-routes-bullets-odd-whitespace.html');

    Http::fake(function (\Illuminate\Http\Client\Request $request) use ($listPageOne, $listPageTwo, $detailConlin, $detailRoutes) {
        $url = $request->url();

        if (str_starts_with($url, drtListUrl()) && str_contains($url, 'page=2')) {
            return Http::response($listPageTwo, 200);
        }

        if (str_starts_with($url, drtListUrl())) {
            return Http::response($listPageOne, 200);
        }

        if ($url === drtDetailUrlConlin()) {
            return Http::response($detailConlin, 200);
        }

        if (str_contains($url, '/en/news/stop-closure-stop-1590-kingston-road-westbound-wicks-drive.aspx')) {
            return Http::response($detailRoutes, 200);
        }

        if (str_contains($url, '/en/news/')) {
            return Http::response('<html><body><main><p>When: fallback detail body.</p><p>Route: 999</p></main></body></html>', 200);
        }

        return Http::response('', 404);
    });
}

test('it fetches and normalizes DRT list pages with details parsing', function () {
    drtFakeListAndDetailFixtures();

    $result = app(DrtServiceAlertsFeedService::class)->fetch();

    expect($result['updated_at']->isUtc())->toBeTrue();
    expect($result['alerts'])->toHaveCount(9);

    $firstAlert = $result['alerts'][0];

    expect($firstAlert['external_id'])->toBe('conlin-and-grandview-detour-update-on-february-24');
    expect($firstAlert['title'])->toBe('Conlin and Grandview Detour update on February 24');
    expect($firstAlert['details_url'])->toBe(drtDetailUrlConlin());
    expect($firstAlert['posted_at']->toDateTimeString())->toBe('2026-02-24 16:16:00');
    expect($firstAlert['when_text'])->toBe('Monday, February 23, 2026, until further notice');
    expect($firstAlert['route_text'])->toBe('Pulse 915, 419, N1');
    expect($firstAlert['description_excerpt'])->toContain('Due to road work at the intersection of Conlin Road and Grandview Street');
    expect($firstAlert['list_hash'])->toBeString()->toHaveLength(40);
    expect($firstAlert['body_text'])->toContain('When: Monday, February 23, 2026, until further notice');
    expect($firstAlert['body_text'])->toContain('Route: Pulse 915, 419, N1');
    expect($firstAlert['details_fetched_at'])->not->toBeNull();
    expect($firstAlert['is_active'])->toBeTrue();
});

test('it tolerates route and routes labels plus odd whitespace and nbsp text', function () {
    drtFakeListAndDetailFixtures();

    $result = app(DrtServiceAlertsFeedService::class)->fetch();

    $routeVariant = collect($result['alerts'])
        ->first(fn (array $alert): bool => $alert['external_id'] === 'stop-closure-stop-1590-kingston-road-westbound-wicks-drive');

    expect($routeVariant)->not->toBeNull();
    expect($routeVariant['route_text'])->toContain('PULSE 900 and N1');
    expect($routeVariant['body_text'])->toContain('Routes: 920and 921');
    expect($routeVariant['body_text'])->toContain('Stop #93998');
});

test('it normalizes relative and non-canonical host detail urls to canonical host', function () {
    $listHtml = <<<'HTML'
    <html><body>
    <div>
      <h2><a href="/en/news/my-relative-alert.aspx">Relative URL Alert</a></h2>
      <p>Posted on Tuesday, February 24, 2026 11:16 AM</p>
      <p><strong>When:</strong> test when</p>
      <p><strong>Route:</strong> route 1</p>
      <p>Example excerpt</p>
      <a href="/en/news/my-relative-alert.aspx">Read more</a>
    </div>
    <div>
      <h2><a href="https://durhamregiontransit.icreate7.esolutionsgroup.ca/en/news/non-canonical-host-alert.aspx">Host URL Alert</a></h2>
      <p>Posted on Tuesday, February 24, 2026 11:16 AM</p>
      <p><strong>When:</strong> test when</p>
      <p><strong>Routes:</strong> route 2</p>
      <p>Example excerpt</p>
      <a href="https://durhamregiontransit.icreate7.esolutionsgroup.ca/en/news/non-canonical-host-alert.aspx">Read more</a>
    </div>
    </body></html>
    HTML;

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/*' => Http::response('<html><body><main>detail</main></body></html>', 200),
    ]);

    $alerts = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'];

    expect($alerts)->toHaveCount(2);
    expect($alerts[0]['details_url'])->toBe('https://www.durhamregiontransit.com/en/news/my-relative-alert.aspx');
    expect($alerts[1]['details_url'])->toBe('https://www.durhamregiontransit.com/en/news/non-canonical-host-alert.aspx');
});

test('it generates deterministic list hash with stable separators when optional fields are missing', function () {
    $listHtml = <<<'HTML'
    <html><body>
    <div>
      <h2><a href="/en/news/hash-check-alert.aspx">Hash Check Alert</a></h2>
      <p>Posted on Tuesday, February 24, 2026 11:16 AM</p>
      <p>Only excerpt text exists</p>
      <a href="/en/news/hash-check-alert.aspx">Read more</a>
    </div>
    </body></html>
    HTML;

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/*' => Http::response('<html><body><main>detail</main></body></html>', 200),
    ]);

    $alert = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'][0];

    expect($alert['list_hash'])->toBe(sha1(implode('|', [
        'Hash Check Alert',
        'Posted on Tuesday, February 24, 2026 11:16 AM',
        '',
        '',
        'Only excerpt text exists',
        'https://www.durhamregiontransit.com/en/news/hash-check-alert.aspx',
    ])));
});

test('it is resistant to css class renames by relying on url pattern and label text', function () {
    $classlessHtml = str_replace(
        ['blogItem', 'blogItem-contentContainer', 'blogPostDate', 'newsTitle'],
        ['x-item', 'x-content', 'x-date', 'x-title'],
        drtFixture('list-page-1.html'),
    );

    Http::fake([
        drtListUrl().'*' => Http::response($classlessHtml, 200),
        'https://www.durhamregiontransit.com/en/news/*' => Http::response('<html><body><main><p>When: detail</p><p>Route: 123</p></main></body></html>', 200),
    ]);

    $result = app(DrtServiceAlertsFeedService::class)->fetch();

    expect($result['alerts'])->not->toBeEmpty();
    expect($result['alerts'][0]['details_url'])->toContain('/en/news/');
    expect($result['alerts'][0]['external_id'])->toBe('conlin-and-grandview-detour-update-on-february-24');
});

test('it follows pagination and respects max page cap', function () {
    config(['feeds.drt.max_pages' => 1]);

    drtFakeListAndDetailFixtures();

    $result = app(DrtServiceAlertsFeedService::class)->fetch();

    expect($result['alerts'])->toHaveCount(5);
});

test('it fetches detail for new alerts', function () {
    drtFakeListAndDetailFixtures();

    app(DrtServiceAlertsFeedService::class)->fetch();

    $detailRequests = Http::recorded()
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/en/news/'));

    expect($detailRequests->count())->toBeGreaterThan(0);
});

test('it fetches detail when list hash changed', function () {
    DrtAlert::factory()->create([
        'external_id' => 'conlin-and-grandview-detour-update-on-february-24',
        'details_url' => drtDetailUrlConlin(),
        'list_hash' => sha1('old-hash'),
        'body_text' => 'old body',
        'details_fetched_at' => Carbon::now()->subHour(),
    ]);

    drtFakeListAndDetailFixtures();

    $alerts = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'];

    $target = collect($alerts)->firstWhere('external_id', 'conlin-and-grandview-detour-update-on-february-24');
    expect($target['body_text'])->toContain('Due to road work at the intersection of Conlin Road and Grandview Street');
});

test('it fetches detail when existing body is missing', function () {
    $listHtml = <<<'HTML'
    <html><body>
    <div>
      <h2><a href="https://www.durhamregiontransit.com/en/news/body-missing-alert.aspx">Body Missing Alert</a></h2>
      <p>Posted on Tuesday, February 24, 2026 11:16 AM</p>
      <p><strong>When:</strong> when value</p>
      <p><strong>Route:</strong> route value</p>
      <p>excerpt</p>
      <a href="https://www.durhamregiontransit.com/en/news/body-missing-alert.aspx">Read more</a>
    </div>
    </body></html>
    HTML;

    $hash = sha1('Body Missing Alert|Posted on Tuesday, February 24, 2026 11:16 AM|when value|route value|excerpt|https://www.durhamregiontransit.com/en/news/body-missing-alert.aspx');

    DrtAlert::factory()->create([
        'external_id' => 'body-missing-alert',
        'details_url' => 'https://www.durhamregiontransit.com/en/news/body-missing-alert.aspx',
        'list_hash' => $hash,
        'body_text' => null,
        'details_fetched_at' => Carbon::now()->subHour(),
    ]);

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/body-missing-alert.aspx' => Http::response('<html><body><main><p>Fresh detail body.</p></main></body></html>', 200),
    ]);

    $alert = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'][0];

    expect($alert['body_text'])->toContain('Fresh detail body');
    expect($alert['details_fetched_at'])->not->toBeNull();
});

test('it fetches detail when details fetched timestamp is stale', function () {
    $listHtml = <<<'HTML'
    <html><body>
    <div>
      <h2><a href="https://www.durhamregiontransit.com/en/news/stale-detail-alert.aspx">Stale Detail Alert</a></h2>
      <p>Posted on Tuesday, February 24, 2026 11:16 AM</p>
      <p><strong>When:</strong> when value</p>
      <p><strong>Route:</strong> route value</p>
      <p>excerpt</p>
      <a href="https://www.durhamregiontransit.com/en/news/stale-detail-alert.aspx">Read more</a>
    </div>
    </body></html>
    HTML;

    $hash = sha1('Stale Detail Alert|Posted on Tuesday, February 24, 2026 11:16 AM|when value|route value|excerpt|https://www.durhamregiontransit.com/en/news/stale-detail-alert.aspx');

    DrtAlert::factory()->create([
        'external_id' => 'stale-detail-alert',
        'details_url' => 'https://www.durhamregiontransit.com/en/news/stale-detail-alert.aspx',
        'list_hash' => $hash,
        'body_text' => 'existing body',
        'details_fetched_at' => Carbon::now()->subHours(25),
    ]);

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/stale-detail-alert.aspx' => Http::response('<html><body><main><p>Refreshed detail body.</p></main></body></html>', 200),
    ]);

    $alert = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'][0];

    expect($alert['body_text'])->toContain('Refreshed detail body');
});

test('it skips detail fetch when hash unchanged body exists and details fetched timestamp is fresh', function () {
    $listHtml = <<<'HTML'
    <html><body>
    <div>
      <h2><a href="https://www.durhamregiontransit.com/en/news/skip-detail-alert.aspx">Skip Detail Alert</a></h2>
      <p>Posted on Tuesday, February 24, 2026 11:16 AM</p>
      <p><strong>When:</strong> when value</p>
      <p><strong>Route:</strong> route value</p>
      <p>excerpt</p>
      <a href="https://www.durhamregiontransit.com/en/news/skip-detail-alert.aspx">Read more</a>
    </div>
    </body></html>
    HTML;

    $hash = sha1('Skip Detail Alert|Posted on Tuesday, February 24, 2026 11:16 AM|when value|route value|excerpt|https://www.durhamregiontransit.com/en/news/skip-detail-alert.aspx');
    $detailsFetchedAt = Carbon::now()->subHours(2);

    DrtAlert::factory()->create([
        'external_id' => 'skip-detail-alert',
        'details_url' => 'https://www.durhamregiontransit.com/en/news/skip-detail-alert.aspx',
        'list_hash' => $hash,
        'body_text' => 'persisted body',
        'details_fetched_at' => $detailsFetchedAt,
    ]);

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/skip-detail-alert.aspx' => Http::response('<html><body><main><p>new body should not be used</p></main></body></html>', 200),
    ]);

    $alert = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'][0];

    expect($alert['body_text'])->toBe('persisted body');
    expect($alert['details_fetched_at']?->toDateTimeString())->toBe($detailsFetchedAt->toDateTimeString());

    $detailRequests = Http::recorded()
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/en/news/skip-detail-alert.aspx'));

    expect($detailRequests)->toHaveCount(0);
});

test('it throws on list network failure', function () {
    Http::fake(['*' => Http::response('', 500)]);

    expect(fn () => app(DrtServiceAlertsFeedService::class)->fetch())
        ->toThrow(RuntimeException::class, 'DRT service alerts feed request failed');
});

test('it handles malformed html response with allow empty feed rules', function () {
    Http::fake([
        drtListUrl().'*' => Http::response('<html><div><broken', 200),
    ]);

    expect(fn () => app(DrtServiceAlertsFeedService::class)->fetch())
        ->toThrow(RuntimeException::class, 'DRT service alerts feed returned zero alerts');

    config(['feeds.allow_empty_feeds' => true]);

    $result = app(DrtServiceAlertsFeedService::class)->fetch();

    expect($result['alerts'])->toBe([]);
});

test('it records circuit breaker success and failure', function () {
    $breaker = mock(FeedCircuitBreaker::class, function (MockInterface $mock): void {
        $mock->shouldReceive('throwIfOpen')->once()->with('drt');
        $mock->shouldReceive('recordSuccess')->once()->with('drt');
        $mock->shouldReceive('recordFailure')->never();
    });

    drtFakeListAndDetailFixtures();

    $service = new DrtServiceAlertsFeedService($breaker);
    $service->fetch();

    $breaker = mock(FeedCircuitBreaker::class, function (MockInterface $mock): void {
        $mock->shouldReceive('throwIfOpen')->once()->with('drt');
        $mock->shouldReceive('recordSuccess')->never();
        $mock->shouldReceive('recordFailure')->once()->withArgs(fn (string $feed, \Throwable $exception): bool => $feed === 'drt' && $exception instanceof RuntimeException);
    });

    Http::fake(['*' => Http::failedConnection()]);

    $service = new DrtServiceAlertsFeedService($breaker);

    expect(fn () => $service->fetch())->toThrow(RuntimeException::class);
});

test('it skips detail fetch when detail http request fails', function () {
    $listHtml = <<<'HTML'
    <html><body>
    <div class="blogItem">
      <div class="blogItem-contentContainer">
        <div class="blogPostDate">Posted on Tuesday, February 24, 2026 11:16 AM</div>
        <div class="newsTitle">
          <a href="https://www.durhamregiontransit.com/en/news/detail-fail-alert.aspx">Detail Fail Alert</a>
        </div>
        <div class="blogItem-contentContainer">
          <p>When: when value</p>
          <p>Route: route value</p>
          <p>excerpt</p>
          <a href="https://www.durhamregiontransit.com/en/news/detail-fail-alert.aspx">Read more</a>
        </div>
      </div>
    </div>
    </body></html>
    HTML;

    $hash = sha1('Detail Fail Alert|Posted on Tuesday, February 24, 2026 11:16 AM|when value|route value|excerpt|https://www.durhamregiontransit.com/en/news/detail-fail-alert.aspx');

    DrtAlert::factory()->create([
        'external_id' => 'detail-fail-alert',
        'details_url' => 'https://www.durhamregiontransit.com/en/news/detail-fail-alert.aspx',
        'list_hash' => $hash,
        'body_text' => 'existing body',
        'details_fetched_at' => Carbon::now()->subHours(2),
    ]);

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/detail-fail-alert.aspx' => Http::response('', 500),
    ]);

    $alert = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'][0];

    expect($alert['body_text'])->toBe('existing body');
    expect($alert['details_fetched_at'])->not->toBeNull();
});

test('it handles detail page returning null body gracefully', function () {
    $listHtml = <<<'HTML'
    <html><body>
    <div class="blogItem">
      <div class="blogItem-contentContainer">
        <div class="blogPostDate">Posted on Tuesday, February 24, 2026 11:16 AM</div>
        <div class="newsTitle">
          <a href="https://www.durhamregiontransit.com/en/news/null-body-alert.aspx">Null Body Alert</a>
        </div>
        <div class="blogItem-contentContainer">
          <p>When: when value</p>
          <p>Route: route value</p>
          <p>excerpt</p>
          <a href="https://www.durhamregiontransit.com/en/news/null-body-alert.aspx">Read more</a>
        </div>
      </div>
    </div>
    </body></html>
    HTML;

    $hash = sha1('Null Body Alert|Posted on Tuesday, February 24, 2026 11:16 AM|when value|route value|excerpt|https://www.durhamregiontransit.com/en/news/null-body-alert.aspx');

    DrtAlert::factory()->create([
        'external_id' => 'null-body-alert',
        'details_url' => 'https://www.durhamregiontransit.com/en/news/null-body-alert.aspx',
        'list_hash' => $hash,
        'body_text' => 'old body',
        'details_fetched_at' => Carbon::now()->subHours(2),
    ]);

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/null-body-alert.aspx' => Http::response('<html><body><main></main></body></html>', 200),
    ]);

    $alert = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'][0];

    expect($alert['body_text'])->toBe('old body');
});

test('it skips entries with null posted at or external id', function () {
    $listHtml = <<<'HTML'
    <html><body>
    <div class="blogItem">
      <div class="blogItem-contentContainer">
        <div class="blogPostDate">Posted on Tuesday, February 24, 2026 11:16 AM</div>
        <div class="newsTitle">
          <a href="https://www.durhamregiontransit.com/en/news/good-alert.aspx">Good Alert</a>
        </div>
        <div class="blogItem-contentContainer">
          <p>When: when value</p>
          <p>Route: route value</p>
          <p>excerpt</p>
          <a href="https://www.durhamregiontransit.com/en/news/good-alert.aspx">Read more</a>
        </div>
      </div>
    </div>
    </body></html>
    HTML;

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/good-alert.aspx' => Http::response('<html><body><main><p>When: when value</p><p>Route: route value</p></main></body></html>', 200),
    ]);

    $result = app(DrtServiceAlertsFeedService::class)->fetch();

    expect($result['alerts'])->toHaveCount(1);
    expect($result['alerts'][0]['external_id'])->toBe('good-alert');
});
