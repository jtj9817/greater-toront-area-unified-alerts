<?php

use App\Console\Commands\FetchFireIncidentsCommand;
use App\Console\Commands\FetchGoTransitAlertsCommand;
use App\Events\AlertCreated;
use App\Models\FireIncident;
use App\Models\GoTransitAlert;
use App\Models\PoliceCall;
use App\Models\TransitAlert;
use App\Services\GoTransitFeedService;
use App\Services\SceneIntel\SceneIntelProcessor;
use App\Services\TorontoFireFeedService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('scheduled fetch events are callback-based and named', function () {
    $schedule = app(Schedule::class);

    $expectedNames = [
        'fire:fetch-incidents',
        'police:fetch-calls',
        'transit:fetch-alerts',
        'go-transit:fetch-alerts',
    ];

    foreach ($expectedNames as $eventName) {
        $event = collect($schedule->events())->first(function ($event) use ($eventName) {
            return is_string($event->description) && $event->description === $eventName;
        });

        expect($event)->not->toBeNull();
        expect(is_string($event->command))->toBeFalse();
    }
});

test('fire command preserves existing incidents when feed is empty', function () {
    config(['feeds.allow_empty_feeds' => false]);

    $incident = FireIncident::factory()->create(['is_active' => true]);

    $xml = <<<'XML'
    <tfs_active_incidents>
      <update_from_db_time>2026-02-17 00:00:00</update_from_db_time>
    </tfs_active_incidents>
    XML;

    Http::fake([
        'https://www.toronto.ca/data/fire/livecad.xml*' => Http::response($xml, 200, ['Content-Type' => 'text/xml']),
    ]);

    $this->artisan('fire:fetch-incidents')->assertExitCode(1);

    expect($incident->refresh()->is_active)->toBeTrue();
});

test('police command preserves existing calls when feed is empty', function () {
    config(['feeds.allow_empty_feeds' => false]);

    $call = PoliceCall::factory()->create(['is_active' => true]);

    Http::fake([
        '*' => Http::response([
            'features' => [],
            'exceededTransferLimit' => false,
        ], 200),
    ]);

    $this->artisan('police:fetch-calls')->assertExitCode(1);

    expect($call->refresh()->is_active)->toBeTrue();
});

test('go transit command preserves existing alerts when feed is empty', function () {
    config(['feeds.allow_empty_feeds' => false]);

    $alert = GoTransitAlert::factory()->create(['is_active' => true]);

    Http::fake([
        'https://api.metrolinx.com/external/go/serviceupdate/en/all*' => Http::response([
            'LastUpdated' => '2026-02-17T00:00:00Z',
            'Trains' => ['Train' => []],
            'Buses' => ['Bus' => []],
            'Stations' => ['Station' => []],
        ], 200),
    ]);

    $this->artisan('go-transit:fetch-alerts')->assertExitCode(1);

    expect($alert->refresh()->is_active)->toBeTrue();
});

test('transit command preserves existing alerts when feed is empty', function () {
    config(['feeds.allow_empty_feeds' => false]);

    $alert = TransitAlert::factory()->create(['is_active' => true]);

    Http::fake(function (\Illuminate\Http\Client\Request $request) {
        $url = $request->url();

        if (str_starts_with($url, 'https://alerts.ttc.ca/api/alerts/live-alerts')) {
            return Http::response([
                'lastUpdated' => '2026-02-17T00:00:00Z',
                'routes' => [],
                'accessibility' => [],
                'siteWideCustom' => [],
                'generalCustom' => [],
                'stops' => [],
                'status' => 'success',
            ], 200);
        }

        if (str_contains($url, '/sxa/search/results/')) {
            return Http::response(['Results' => []], 200);
        }

        if (str_starts_with($url, 'https://www.ttc.ca/service-advisories/Streetcar-Service-Changes')) {
            return Http::response('<html></html>', 200);
        }

        return Http::response('unexpected url', 500);
    });

    $this->artisan('transit:fetch-alerts')->assertExitCode(1);

    expect($alert->refresh()->is_active)->toBeTrue();
});

test('fire command continues when a single record timestamp fails to parse', function () {
    Event::fake([AlertCreated::class]);
    Log::spy();

    $this->mock(TorontoFireFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            'updated_at' => '2026-02-17 00:00:00',
            'events' => [
                [
                    'event_num' => 'E_BAD',
                    'event_type' => 'Fire',
                    'prime_street' => 'Street 1',
                    'cross_streets' => null,
                    'dispatch_time' => 'not-a-timestamp',
                    'alarm_level' => 1,
                    'beat' => null,
                    'units_dispatched' => null,
                ],
                [
                    'event_num' => 'E_GOOD',
                    'event_type' => 'Medical',
                    'prime_street' => 'Street 2',
                    'cross_streets' => null,
                    'dispatch_time' => '2026-02-17T00:05:00',
                    'alarm_level' => 0,
                    'beat' => null,
                    'units_dispatched' => null,
                ],
            ],
        ]);
    });

    $this->mock(SceneIntelProcessor::class, function (MockInterface $mock) {
        $mock->shouldReceive('processIncidentUpdate')->andReturnNull();
    });

    $this->artisan(FetchFireIncidentsCommand::class)->assertExitCode(0);

    expect(FireIncident::query()->where('event_num', 'E_GOOD')->exists())->toBeTrue();
    expect(FireIncident::query()->where('event_num', 'E_BAD')->exists())->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Skipping fire incident due to dispatch_time parse failure' && isset($context['exception']))
        ->once();
});

test('go transit command continues when a single record timestamp fails to parse', function () {
    Event::fake([AlertCreated::class]);
    Log::spy();

    $this->mock(GoTransitFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            'updated_at' => '2026-02-17T00:00:00Z',
            'alerts' => [
                [
                    'external_id' => 'bad',
                    'alert_type' => 'notification',
                    'service_mode' => 'GO Train',
                    'corridor_or_route' => 'Test',
                    'corridor_code' => null,
                    'sub_category' => null,
                    'message_subject' => 'Bad timestamp',
                    'message_body' => null,
                    'direction' => null,
                    'trip_number' => null,
                    'delay_duration' => null,
                    'status' => null,
                    'line_colour' => null,
                    'posted_at' => 'not-a-timestamp',
                ],
                [
                    'external_id' => 'good',
                    'alert_type' => 'notification',
                    'service_mode' => 'GO Train',
                    'corridor_or_route' => 'Test',
                    'corridor_code' => null,
                    'sub_category' => null,
                    'message_subject' => 'Good timestamp',
                    'message_body' => null,
                    'direction' => null,
                    'trip_number' => null,
                    'delay_duration' => null,
                    'status' => null,
                    'line_colour' => null,
                    'posted_at' => '2026-02-17T00:01:00Z',
                ],
            ],
        ]);
    });

    $this->artisan(FetchGoTransitAlertsCommand::class)->assertExitCode(0);

    expect(GoTransitAlert::query()->where('external_id', 'good')->exists())->toBeTrue();
    expect(GoTransitAlert::query()->where('external_id', 'bad')->exists())->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message, array $context): bool => $message === 'Skipping GO Transit alert due to posted_at parse failure' && isset($context['exception']))
        ->once();
});
