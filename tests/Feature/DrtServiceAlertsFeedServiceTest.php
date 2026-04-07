<?php

use App\Models\DrtAlert;
use App\Services\DrtServiceAlertsFeedService;
use App\Services\FeedCircuitBreaker;
use App\Services\FeedCircuitBreakerOpenException;
use Carbon\Carbon;
use Carbon\CarbonInterface;
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

class TestableDrtServiceAlertsFeedService extends DrtServiceAlertsFeedService
{
    public function normalizeText(mixed $value): ?string
    {
        return parent::normalizeText($value);
    }

    public function normalizeDetailUrl(?string $url): ?string
    {
        return parent::normalizeDetailUrl($url);
    }

    public function extractExternalIdFromUrl(string $url): ?string
    {
        return parent::extractExternalIdFromUrl($url);
    }

    public function parsePostedAt(?string $postedOnLine): ?CarbonInterface
    {
        return parent::parsePostedAt($postedOnLine);
    }

    public function loadDomDocument(string $html): ?DOMDocument
    {
        return parent::loadDomDocument($html);
    }

    public function extractDetailBodyTextFromHtml(string $html): ?string
    {
        return parent::extractDetailBodyTextFromHtml($html);
    }

    public function extractTextBetweenMarkers(string $text, string $startMarker, string $endMarker): ?string
    {
        return parent::extractTextBetweenMarkers($text, $startMarker, $endMarker);
    }

    public function findListContextNode(DOMNode $node): ?DOMNode
    {
        return parent::findListContextNode($node);
    }

    public function extractLabelValue(string $contextText, string $label): ?string
    {
        return parent::extractLabelValue($contextText, $label);
    }

    public function extractLabelValueFromContextNode(DOMNode $contextNode, string $label): ?string
    {
        return parent::extractLabelValueFromContextNode($contextNode, $label);
    }
}

function drtTestableService(): TestableDrtServiceAlertsFeedService
{
    $breaker = mock(FeedCircuitBreaker::class);
    $breaker->shouldIgnoreMissing();

    return new TestableDrtServiceAlertsFeedService($breaker);
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

test('it preserves utf8 punctuation in detail body text', function () {
    $listHtml = <<<'HTML'
    <html><body>
    <div>
      <h2><a href="https://www.durhamregiontransit.com/en/news/utf8-alert.aspx">UTF-8 Alert</a></h2>
      <p>Posted on Tuesday, February 24, 2026 11:16 AM</p>
      <p><strong>When:</strong> when value</p>
      <p><strong>Route:</strong> route value</p>
      <p>excerpt</p>
      <a href="https://www.durhamregiontransit.com/en/news/utf8-alert.aspx">Read more</a>
    </div>
    </body></html>
    HTML;

    $detailHtml = <<<'HTML'
    <html><body>
      <div class="iCreateDynaToken">
        <p>DRT’s temporary detour • use Stop #123.</p>
      </div>
    </body></html>
    HTML;

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/utf8-alert.aspx' => Http::response($detailHtml, 200),
    ]);

    $alert = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'][0];

    expect($alert['body_text'])->toContain('DRT’s temporary detour • use Stop #123.');
    expect($alert['body_text'])->not->toContain('â');
    expect($alert['body_text'])->not->toContain('â¢');
});

test('it parses posted on values with non-zero-padded day values', function () {
    $listHtml = <<<'HTML'
    <html><body>
    <div>
      <h2><a href="https://www.durhamregiontransit.com/en/news/non-padded-day-alert.aspx">Non-Padded Day Alert</a></h2>
      <p>Posted on Monday, February 9, 2026 09:14 AM</p>
      <p><strong>When:</strong> when value</p>
      <p><strong>Route:</strong> route value</p>
      <p>excerpt</p>
      <a href="https://www.durhamregiontransit.com/en/news/non-padded-day-alert.aspx">Read more</a>
    </div>
    </body></html>
    HTML;

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/non-padded-day-alert.aspx' => Http::response('<html><body><main>detail</main></body></html>', 200),
    ]);

    $alert = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'][0];

    expect($alert['posted_at']->toDateTimeString())->toBe('2026-02-09 14:14:00');
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

test('it falls back to existing body when detail http request fails', function () {
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
        </div>
      </div>
    </div>
    </body></html>
    HTML;

    // Use a mismatched hash to force detail fetch (shouldFetchDetails returns true)
    $staleHash = sha1('stale-hash-value');

    DrtAlert::factory()->create([
        'external_id' => 'detail-fail-alert',
        'details_url' => 'https://www.durhamregiontransit.com/en/news/detail-fail-alert.aspx',
        'list_hash' => $staleHash,
        'body_text' => 'existing body',
        'details_fetched_at' => Carbon::now()->subHours(2),
    ]);

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/detail-fail-alert.aspx' => Http::response('', 500),
    ]);

    $alert = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'][0];

    // HTTP 500 causes fetchDetailBodyText to return null
    // Service falls back to existing body_text and details_fetched_at
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

    // Use a mismatched hash to force detail fetch (shouldFetchDetails returns true)
    $staleHash = sha1('stale-hash-value');

    DrtAlert::factory()->create([
        'external_id' => 'null-body-alert',
        'details_url' => 'https://www.durhamregiontransit.com/en/news/null-body-alert.aspx',
        'list_hash' => $staleHash,
        'body_text' => 'old body',
        'details_fetched_at' => Carbon::now()->subHours(2),
    ]);

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/null-body-alert.aspx' => Http::response('<html><body><main></main></body></html>', 200),
    ]);

    $alert = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'][0];

    // Assert detail request was attempted (empty main content)
    $detailRequests = Http::recorded()
        ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), '/en/news/null-body-alert.aspx'));

    expect($detailRequests)->toHaveCount(1);
    expect($alert['body_text'])->toBe('old body');
});

test('it skips entries with empty external id and validates good entries are preserved', function () {
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
        </div>
      </div>
    </div>
    <div class="blogItem">
      <div class="blogItem-contentContainer">
        <div class="blogPostDate">Posted on Tuesday, February 24, 2026 11:16 AM</div>
        <div class="newsTitle">
          <!-- URL slug is just .aspx which yields empty external_id after extension removal -->
          <a href="https://www.durhamregiontransit.com/en/news/.aspx">Empty Slug Alert</a>
        </div>
        <div class="blogItem-contentContainer">
          <p>When: when value</p>
          <p>Route: route value</p>
          <p>excerpt</p>
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

    // Verify the good alert exists
    $externalIds = array_column($result['alerts'], 'external_id');
    expect($externalIds)->toContain('good-alert');

    // Verify no empty or invalid external_ids exist in results
    foreach ($result['alerts'] as $alert) {
        expect($alert['external_id'])->not->toBeEmpty();
        expect($alert['external_id'])->not->toBe('.aspx');
    }
});

/*
 * Phase 6: DrtServiceAlertsFeedService Remaining Branch Coverage
 *
 * Gap lines targeted: 121, 148, 154, 281, 297-298, 307, 332, 361,
 * 366, 375, 379, 393, 408, 414, 420-425, 443, 451, 518-521, 553,
 * 563, 601.
 */

// Task 5: Circuit breaker open propagates (no swallowing)
test('circuit breaker open propagates without HTTP calls', function () {
    $breaker = mock(FeedCircuitBreaker::class, function (MockInterface $mock): void {
        $mock->shouldReceive('throwIfOpen')
            ->once()
            ->with('drt')
            ->andThrow(new FeedCircuitBreakerOpenException("Circuit breaker open for feed 'drt'"));
        $mock->shouldReceive('recordFailure')->never();
        $mock->shouldReceive('recordSuccess')->never();
    });

    Http::fake();

    $service = new DrtServiceAlertsFeedService($breaker);

    expect(fn () => $service->fetch())
        ->toThrow(FeedCircuitBreakerOpenException::class, "Circuit breaker open for feed 'drt'");

    expect(Http::recorded())->toHaveCount(0);
});

// Task 1: Cover list fetch failure/exception path deterministically
test('non-2xx list response includes status code in exception message', function () {
    Http::fake([drtListUrl().'*' => Http::response('', 503)]);

    expect(fn () => app(DrtServiceAlertsFeedService::class)->fetch())
        ->toThrow(RuntimeException::class, 'DRT service alerts feed request failed: 503');
});

test('detail fetch connection exception falls back to existing body text', function () {
    $listHtml = <<<'HTML'
    <html><body>
    <div class="blogItem">
      <div class="blogItem-contentContainer">
        <div class="blogPostDate">Posted on Tuesday, February 24, 2026 11:16 AM</div>
        <div class="newsTitle">
          <a href="https://www.durhamregiontransit.com/en/news/exception-detail-alert.aspx">Exception Detail Alert</a>
        </div>
        <div class="blogItem-contentContainer">
          <p>When: when value</p>
          <p>Route: route value</p>
          <p>excerpt</p>
        </div>
      </div>
    </div>
    </body></html>
    HTML;

    DrtAlert::factory()->create([
        'external_id' => 'exception-detail-alert',
        'details_url' => 'https://www.durhamregiontransit.com/en/news/exception-detail-alert.aspx',
        'list_hash' => sha1('old-hash'),
        'body_text' => 'preserved body',
        'details_fetched_at' => Carbon::now()->subHours(2),
    ]);

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/exception-detail-alert.aspx' => Http::failedConnection(),
    ]);

    $alert = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'][0];
    expect($alert['body_text'])->toBe('preserved body');
});

// Task 2: Cover "empty HTML" parse behavior for list/detail
test('empty list response body returns zero alerts and throws without allow empty feeds', function () {
    Http::fake([drtListUrl().'*' => Http::response('   ', 200)]);

    expect(fn () => app(DrtServiceAlertsFeedService::class)->fetch())
        ->toThrow(RuntimeException::class, 'DRT service alerts feed returned zero alerts');

    config(['feeds.allow_empty_feeds' => true]);

    $result = app(DrtServiceAlertsFeedService::class)->fetch();
    expect($result['alerts'])->toBe([]);
});

test('empty detail response falls back to existing body text', function () {
    $listHtml = <<<'HTML'
    <html><body>
    <div class="blogItem">
      <div class="blogItem-contentContainer">
        <div class="blogPostDate">Posted on Tuesday, February 24, 2026 11:16 AM</div>
        <div class="newsTitle">
          <a href="https://www.durhamregiontransit.com/en/news/empty-detail-alert.aspx">Empty Detail Alert</a>
        </div>
        <div class="blogItem-contentContainer">
          <p>When: when value</p>
          <p>Route: route value</p>
          <p>excerpt</p>
        </div>
      </div>
    </div>
    </body></html>
    HTML;

    DrtAlert::factory()->create([
        'external_id' => 'empty-detail-alert',
        'details_url' => 'https://www.durhamregiontransit.com/en/news/empty-detail-alert.aspx',
        'list_hash' => sha1('old-hash'),
        'body_text' => 'existing body',
        'details_fetched_at' => Carbon::now()->subHours(2),
    ]);

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/empty-detail-alert.aspx' => Http::response('   ', 200),
    ]);

    $alert = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'][0];
    expect($alert['body_text'])->toBe('existing body');
});

// Task 3: Cover URL normalization rejection paths
test('URL normalization rejects various invalid URLs', function (?string $url) {
    expect(drtTestableService()->normalizeDetailUrl($url))->toBeNull();
})->with([
    'null' => null,
    'empty string' => '',
    'missing en-news path' => 'https://www.durhamregiontransit.com/other/path.aspx',
    'missing aspx extension' => 'https://www.durhamregiontransit.com/en/news/some-alert.html',
    'completely invalid URL' => '://not-a-url',
]);

test('URL normalization prepends slash to relative URL without leading slash', function () {
    $result = drtTestableService()->normalizeDetailUrl('en/news/test-alert.aspx');
    expect($result)->toBe('https://www.durhamregiontransit.com/en/news/test-alert.aspx');
});

test('list parsing skips links that normalize to invalid URLs without failing the whole fetch', function () {
    $listHtml = <<<'HTML'
    <html><body>
    <div>
      <h2><a href="https://www.durhamregiontransit.com/en/news/valid-alert.aspx">Valid Alert</a></h2>
      <p>Posted on Tuesday, February 24, 2026 11:16 AM</p>
      <p><strong>When:</strong> when value</p>
      <p><strong>Route:</strong> route value</p>
      <p>excerpt</p>
      <a href="https://www.durhamregiontransit.com/en/news/valid-alert.aspx">Read more</a>
    </div>
    <div>
      <h2><a href="https://www.durhamregiontransit.com/other/invalid-path.aspx">Invalid Path Alert</a></h2>
      <p>Posted on Tuesday, February 24, 2026 11:16 AM</p>
      <p><strong>When:</strong> when value</p>
      <p><strong>Route:</strong> route value</p>
      <p>excerpt</p>
      <a href="https://www.durhamregiontransit.com/other/invalid-path.aspx">Read more</a>
    </div>
    </body></html>
    HTML;

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/*' => Http::response('<html><body><main>detail</main></body></html>', 200),
    ]);

    $result = app(DrtServiceAlertsFeedService::class)->fetch();
    expect($result['alerts'])->toHaveCount(1);
    expect($result['alerts'][0]['external_id'])->toBe('valid-alert');
});

// Task 4: Cover normalizeText() non-scalar guard (defensive branch)
test('normalizeText returns null for non-scalar inputs', function (mixed $value) {
    expect(drtTestableService()->normalizeText($value))->toBeNull();
})->with([
    'array' => [['foo', 'bar']],
    'object' => [new stdClass],
]);

// Additional branch coverage: shouldFetchDetails when details_fetched_at is null
test('shouldFetchDetails returns true when details fetched at is null', function () {
    $listHtml = <<<'HTML'
    <html><body>
    <div>
      <h2><a href="https://www.durhamregiontransit.com/en/news/no-fetched-at-alert.aspx">No Fetched At Alert</a></h2>
      <p>Posted on Tuesday, February 24, 2026 11:16 AM</p>
      <p><strong>When:</strong> when value</p>
      <p><strong>Route:</strong> route value</p>
      <p>excerpt</p>
      <a href="https://www.durhamregiontransit.com/en/news/no-fetched-at-alert.aspx">Read more</a>
    </div>
    </body></html>
    HTML;

    $hash = sha1('No Fetched At Alert|Posted on Tuesday, February 24, 2026 11:16 AM|when value|route value|excerpt|https://www.durhamregiontransit.com/en/news/no-fetched-at-alert.aspx');

    DrtAlert::factory()->create([
        'external_id' => 'no-fetched-at-alert',
        'details_url' => 'https://www.durhamregiontransit.com/en/news/no-fetched-at-alert.aspx',
        'list_hash' => $hash,
        'body_text' => 'existing body',
        'details_fetched_at' => null,
    ]);

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/no-fetched-at-alert.aspx' => Http::response('<html><body><main><p>Refreshed body.</p></main></body></html>', 200),
    ]);

    $alert = app(DrtServiceAlertsFeedService::class)->fetch()['alerts'][0];
    expect($alert['body_text'])->toContain('Refreshed body');
    expect($alert['details_fetched_at'])->not->toBeNull();
});

// parsePostedAt branch coverage: lines 408, 414, 420-425
test('parsePostedAt returns null for various invalid inputs', function (?string $input) {
    expect(drtTestableService()->parsePostedAt($input))->toBeNull();
})->with([
    'null' => null,
    'empty string' => '',
    'whitespace only' => '   ',
    'text without posted on prefix' => 'Not a date at all',
    'posted on without date after prefix' => 'Posted on ',
    'date without time component' => 'Posted on Monday, February 24, 2026',
]);

// extractExternalIdFromUrl: line 393
test('extractExternalIdFromUrl returns null for empty path', function () {
    expect(drtTestableService()->extractExternalIdFromUrl('https://example.com/'))->toBeNull();
    expect(drtTestableService()->extractExternalIdFromUrl('https://example.com'))->toBeNull();
});

// extractTextBetweenMarkers: lines 518-521, 332
test('extractTextBetweenMarkers returns null when markers are missing or misordered', function (string $text) {
    expect(drtTestableService()->extractTextBetweenMarkers($text, 'Start', 'End'))->toBeNull();
})->with([
    'missing start marker' => 'Some text End more text',
    'missing end marker' => 'Start some text more text',
    'end before start' => 'End comes before Start marker',
]);

test('extractTextBetweenMarkers returns text between found markers', function () {
    $result = drtTestableService()->extractTextBetweenMarkers('Before Start middle text End after', 'Start', 'End');
    expect($result)->toBe('middle text');
});

// findListContextNode: lines 553, 563
test('findListContextNode returns null when cursor reaches root without Posted on text', function () {
    $doc = new DOMDocument;
    $doc->loadHTML('<html><body><div><a href="/en/news/test.aspx">Link</a></div></body></html>', LIBXML_NOWARNING | LIBXML_NOERROR);
    $link = (new DOMXPath($doc))->query('//a')->item(0);

    expect(drtTestableService()->findListContextNode($link))->toBeNull();
});

test('findListContextNode returns null for deeply nested node exceeding max depth', function () {
    $html = '<html><body>';
    for ($i = 0; $i < 10; $i++) {
        $html .= '<div>';
    }
    $html .= '<a href="/en/news/test.aspx">Link</a>';
    for ($i = 0; $i < 10; $i++) {
        $html .= '</div>';
    }
    $html .= '</body></html>';

    $doc = new DOMDocument;
    $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    $link = (new DOMXPath($doc))->query('//a')->item(0);

    expect(drtTestableService()->findListContextNode($link))->toBeNull();
});

// loadDomDocument: line 601
test('loadDomDocument returns null for empty or whitespace-only HTML', function () {
    expect(drtTestableService()->loadDomDocument(''))->toBeNull();
    expect(drtTestableService()->loadDomDocument('   '))->toBeNull();
    expect(drtTestableService()->loadDomDocument("\n\t"))->toBeNull();
});

// extractDetailBodyTextFromHtml: line 332 (text between markers path)
test('extractDetailBodyTextFromHtml extracts text between Back to Search and Subscribe markers', function () {
    $html = '<html><body>
        <p>Back to Search</p>
        <p>This is the alert detail content that should be extracted.</p>
        <p>Subscribe</p>
    </body></html>';

    $result = drtTestableService()->extractDetailBodyTextFromHtml($html);
    expect($result)->toContain('This is the alert detail content that should be extracted');
});

// extractLabelValue: line 443
test('extractLabelValue returns trimmed value when label is found', function () {
    $context = "Route: 900 and N1\nWhen: until further notice\nRead more";
    expect(drtTestableService()->extractLabelValue($context, 'Route'))->toBe('900 and N1');
});

// extractLabelValueFromContextNode: line 451
test('extractLabelValueFromContextNode returns null when context node has no owner document', function () {
    $detachedNode = new DOMElement('p');
    expect(drtTestableService()->extractLabelValueFromContextNode($detachedNode, 'Route'))->toBeNull();
});

// List parse edge cases: lines 148, 154
test('list parsing skips links with empty text or missing context', function () {
    $deepWrap = str_repeat('<div>', 10);
    $deepClose = str_repeat('</div>', 10);

    $listHtml = <<<HTML
    <html><body>
    <div>
      <h2><a href="https://www.durhamregiontransit.com/en/news/valid-alert.aspx">Valid Alert</a></h2>
      <p>Posted on Tuesday, February 24, 2026 11:16 AM</p>
      <p><strong>When:</strong> when value</p>
      <p><strong>Route:</strong> route value</p>
      <p>excerpt</p>
      <a href="https://www.durhamregiontransit.com/en/news/valid-alert.aspx">Read more</a>
    </div>
    <div>
      <h2><a href="https://www.durhamregiontransit.com/en/news/empty-text-alert.aspx">   </a></h2>
      <p>Posted on Tuesday, February 24, 2026 11:16 AM</p>
    </div>
    <div>
      {$deepWrap}<a href="https://www.durhamregiontransit.com/en/news/no-context-alert.aspx">No Context Alert</a>{$deepClose}
    </div>
    </body></html>
    HTML;

    Http::fake([
        drtListUrl().'*' => Http::response($listHtml, 200),
        'https://www.durhamregiontransit.com/en/news/*' => Http::response('<html><body><main>detail</main></body></html>', 200),
    ]);

    $result = app(DrtServiceAlertsFeedService::class)->fetch();
    expect($result['alerts'])->toHaveCount(1);
    expect($result['alerts'][0]['external_id'])->toBe('valid-alert');
});
