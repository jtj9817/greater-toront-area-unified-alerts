<?php

use App\Services\TtcAlertsFeedService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

test('it fetches and normalizes TTC alerts from live API, SXA, and static sources', function () {
    Http::fake([
        'https://alerts.ttc.ca/api/alerts/live-alerts*' => Http::response([
            'lastUpdated' => '2026-02-03T04:41:06.633Z',
            'routes' => [
                [
                    'id' => '61748',
                    'alertType' => 'Planned',
                    'routeType' => 'Subway',
                    'route' => '1',
                    'title' => 'Line 1 service adjustment',
                    'description' => '<p>Shuttle <strong>buses</strong> will operate.</p>',
                    'severity' => 'Critical',
                    'effect' => 'REDUCED_SERVICE',
                    'causeDescription' => 'Other',
                    'activePeriod' => [
                        'start' => '2026-02-02T10:22:53.697Z',
                        'end' => '0001-01-01T00:00:00Z',
                    ],
                    'direction' => 'Both Ways',
                    'stopStart' => 'Finch',
                    'stopEnd' => 'Eglinton',
                    'url' => 'https://www.ttc.ca/service-alerts',
                ],
            ],
            'accessibility' => [],
            'siteWideCustom' => [],
            'generalCustom' => [],
            'stops' => [],
            'status' => 'success',
        ], 200),
        '*sxa/search/results*' => Http::sequence()
            ->push([
                'Results' => [
                    [
                        'Id' => '4976b805-daf7-43f7-96c1-c3da717a7877',
                        'Url' => '/service-advisories/Service-Changes/510-310',
                        'Html' => '<div><span class="field-route">510|310</span><span class="field-satitle">Temporary service change</span><span class="field-starteffectivedate">February 2, 2026 - 11:00 PM</span><span class="field-endeffectivedate">February 5, 2026 - 04:00 AM</span></div>',
                    ],
                ],
            ], 200)
            ->push(['Results' => []], 200)
            ->push(['Results' => []], 200)
            ->push(['Results' => []], 200),
        'https://www.ttc.ca/service-advisories/Streetcar-Service-Changes*' => Http::response(
            <<<'HTML'
            <html><body>
            <article class="streetcar-advisory">
              <h3>504 Temporary service change overnight</h3>
              <p>Streetcars replaced by buses due to track work.</p>
              <a href="/service-advisories/Streetcar-Service-Changes">Details</a>
            </article>
            </body></html>
            HTML,
            200
        ),
    ]);

    $result = (new TtcAlertsFeedService)->fetch();

    expect($result['updated_at'])->toBeInstanceOf(CarbonInterface::class);

    $apiAlert = collect($result['alerts'])->firstWhere('external_id', 'api:61748');
    $sxaAlert = collect($result['alerts'])->firstWhere('external_id', 'sxa:4976b805-daf7-43f7-96c1-c3da717a7877');
    $staticAlert = collect($result['alerts'])->first(fn (array $alert) => str_starts_with($alert['external_id'], 'static:'));

    expect($apiAlert)->not->toBeNull();
    expect($apiAlert['source_feed'])->toBe('live-api');
    expect($apiAlert['route_type'])->toBe('Subway');
    expect($apiAlert['description'])->toBe('Shuttle buses will operate.');
    expect($apiAlert['active_period_end'])->toBeNull();
    expect($apiAlert['active_period_start'])->toBeInstanceOf(CarbonInterface::class);

    expect($sxaAlert)->not->toBeNull();
    expect($sxaAlert['source_feed'])->toBe('sxa');
    expect($sxaAlert['title'])->toBe('Temporary service change');
    expect($sxaAlert['route'])->toBe('510,310');

    expect($staticAlert)->not->toBeNull();
    expect($staticAlert['source_feed'])->toBe('static');
    expect($staticAlert['route_type'])->toBe('Streetcar');
});

test('it throws when the TTC live API source fails', function () {
    Http::fake([
        'https://alerts.ttc.ca/api/alerts/live-alerts*' => Http::response('error', 500),
    ]);

    (new TtcAlertsFeedService)->fetch();
})->throws(RuntimeException::class, 'TTC live alerts request failed: 500');

test('it logs warnings and continues when SXA or static sources fail', function () {
    Log::spy();

    Http::fake([
        'https://alerts.ttc.ca/api/alerts/live-alerts*' => Http::response([
            'lastUpdated' => '2026-02-03T04:41:06.633Z',
            'routes' => [
                [
                    'id' => '61748',
                    'title' => 'Line 1 service adjustment',
                    'activePeriod' => [
                        'start' => '2026-02-02T10:22:53.697Z',
                        'end' => '2026-02-05T23:00:00Z',
                    ],
                ],
            ],
            'accessibility' => [],
            'siteWideCustom' => [],
            'generalCustom' => [],
            'stops' => [],
        ], 200),
        '*sxa/search/results*' => Http::response(['unexpected' => true], 200),
        'https://www.ttc.ca/service-advisories/Streetcar-Service-Changes*' => Http::response('down', 500),
    ]);

    $result = (new TtcAlertsFeedService)->fetch();

    expect($result['alerts'])->toHaveCount(1);
    expect($result['alerts'][0]['external_id'])->toBe('api:61748');

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'TTC SXA source failed'))
        ->atLeast()
        ->once();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'TTC static source failed'))
        ->once();
});
