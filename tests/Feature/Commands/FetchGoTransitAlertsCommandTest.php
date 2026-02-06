<?php

use App\Models\GoTransitAlert;
use App\Services\GoTransitFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('it syncs go transit alerts from the service', function () {
    $feedData = [
        'updated_at' => '2026-02-05T14:30:00-05:00',
        'alerts' => [
            [
                'external_id' => 'notif:LW:TDELAY:abc123',
                'alert_type' => 'notification',
                'service_mode' => 'GO Train',
                'corridor_or_route' => 'Lakeshore West',
                'corridor_code' => 'LW',
                'sub_category' => 'TDELAY',
                'message_subject' => 'Lakeshore West delays',
                'message_body' => 'Expect 15 min delays',
                'direction' => null,
                'trip_number' => null,
                'delay_duration' => null,
                'status' => 'INIT',
                'line_colour' => '#8B4513',
                'posted_at' => '02/05/2026 14:00:00',
            ],
            [
                'external_id' => 'saag:LW:4521',
                'alert_type' => 'saag',
                'service_mode' => 'GO Train',
                'corridor_or_route' => 'Lakeshore West',
                'corridor_code' => 'LW',
                'sub_category' => null,
                'message_subject' => 'Lakeshore West - Union Station delayed (00:12:00)',
                'message_body' => 'Departure: 2:30 PM. Arrival: 3:15 PM. Status: Moving',
                'direction' => 'EASTBOUND',
                'trip_number' => '4521',
                'delay_duration' => '00:12:00',
                'status' => 'Moving',
                'line_colour' => '#8B4513',
                'posted_at' => '2026-02-05 14:25:00',
            ],
        ],
    ];

    $this->mock(GoTransitFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('go-transit:fetch-alerts')
        ->expectsOutput('Fetching GO Transit service alerts...')
        ->expectsOutputToContain('Done. 2 active alerts synced, 0 marked inactive.')
        ->assertExitCode(0);

    expect(GoTransitAlert::count())->toBe(2);
    expect(GoTransitAlert::where('external_id', 'notif:LW:TDELAY:abc123')->first()->is_active)->toBeTrue();
    expect(GoTransitAlert::where('external_id', 'saag:LW:4521')->first()->is_active)->toBeTrue();
});

test('it deactivates alerts no longer in the feed', function () {
    GoTransitAlert::factory()->create([
        'external_id' => 'notif:LW:TDELAY:old',
        'is_active' => true,
    ]);

    GoTransitAlert::factory()->create([
        'external_id' => 'notif:LW:TDELAY:keep',
        'is_active' => true,
    ]);

    $feedData = [
        'updated_at' => '2026-02-05T14:30:00-05:00',
        'alerts' => [
            [
                'external_id' => 'notif:LW:TDELAY:keep',
                'alert_type' => 'notification',
                'service_mode' => 'GO Train',
                'corridor_or_route' => 'Lakeshore West',
                'corridor_code' => 'LW',
                'sub_category' => 'TDELAY',
                'message_subject' => 'Lakeshore West delays',
                'message_body' => null,
                'direction' => null,
                'trip_number' => null,
                'delay_duration' => null,
                'status' => 'INIT',
                'line_colour' => null,
                'posted_at' => '02/05/2026 14:00:00',
            ],
            [
                'external_id' => 'notif:LW:BCANCEL:new',
                'alert_type' => 'notification',
                'service_mode' => 'GO Bus',
                'corridor_or_route' => 'Route 12',
                'corridor_code' => '12',
                'sub_category' => 'BCANCEL',
                'message_subject' => 'Route 12 cancelled',
                'message_body' => null,
                'direction' => null,
                'trip_number' => null,
                'delay_duration' => null,
                'status' => 'INIT',
                'line_colour' => null,
                'posted_at' => '02/05/2026 13:00:00',
            ],
        ],
    ];

    $this->mock(GoTransitFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('go-transit:fetch-alerts')
        ->expectsOutputToContain('Done. 2 active alerts synced, 1 marked inactive.')
        ->assertExitCode(0);

    expect(GoTransitAlert::where('external_id', 'notif:LW:TDELAY:old')->first()->is_active)->toBeFalse();
    expect(GoTransitAlert::where('external_id', 'notif:LW:TDELAY:keep')->first()->is_active)->toBeTrue();
    expect(GoTransitAlert::where('external_id', 'notif:LW:BCANCEL:new')->first()->is_active)->toBeTrue();
});

test('it handles empty feed gracefully', function () {
    $feedData = [
        'updated_at' => '2026-02-05T14:30:00-05:00',
        'alerts' => [],
    ];

    $this->mock(GoTransitFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('go-transit:fetch-alerts')
        ->expectsOutputToContain('Done. 0 active alerts synced, 0 marked inactive.')
        ->assertExitCode(0);
});

test('it handles service failures gracefully', function () {
    $this->mock(GoTransitFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andThrow(new RuntimeException('Service unavailable'));
    });

    $this->artisan('go-transit:fetch-alerts')
        ->expectsOutput('Feed fetch failed: Service unavailable')
        ->assertExitCode(1);
});
