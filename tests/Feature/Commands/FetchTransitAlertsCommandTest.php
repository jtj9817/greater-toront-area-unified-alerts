<?php

use App\Models\TransitAlert;
use App\Services\TtcAlertsFeedService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

test('it syncs transit alerts by external_id and deactivates stale rows', function () {
    TransitAlert::factory()->create([
        'external_id' => 'api:old-alert',
        'title' => 'Old alert',
        'is_active' => true,
    ]);

    TransitAlert::factory()->create([
        'external_id' => 'api:stay-alert',
        'title' => 'Outdated title',
        'is_active' => true,
    ]);

    $feedUpdatedAt = CarbonImmutable::parse('2026-02-05T15:00:00Z');

    $this->mock(TtcAlertsFeedService::class, function (MockInterface $mock) use ($feedUpdatedAt) {
        $mock->shouldReceive('fetch')->once()->andReturn([
            'updated_at' => $feedUpdatedAt,
            'alerts' => [
                [
                    'external_id' => 'api:stay-alert',
                    'source_feed' => 'live-api',
                    'alert_type' => 'Planned',
                    'route_type' => 'Subway',
                    'route' => '1',
                    'title' => 'Updated title',
                    'description' => 'Updated description',
                    'severity' => 'Critical',
                    'effect' => 'REDUCED_SERVICE',
                    'cause' => 'Other',
                    'active_period_start' => CarbonImmutable::parse('2026-02-05T14:00:00Z'),
                    'active_period_end' => null,
                    'direction' => 'Both Ways',
                    'stop_start' => 'Finch',
                    'stop_end' => 'Eglinton',
                    'url' => 'https://www.ttc.ca/service-alerts',
                ],
                [
                    'external_id' => 'sxa:4976b805-daf7-43f7-96c1-c3da717a7877',
                    'source_feed' => 'sxa',
                    'alert_type' => 'Planned',
                    'route_type' => null,
                    'route' => '510,310',
                    'title' => 'Temporary service change',
                    'description' => null,
                    'severity' => null,
                    'effect' => null,
                    'cause' => null,
                    'active_period_start' => CarbonImmutable::parse('2026-02-03T04:00:00Z'),
                    'active_period_end' => CarbonImmutable::parse('2026-02-05T09:00:00Z'),
                    'direction' => null,
                    'stop_start' => null,
                    'stop_end' => null,
                    'url' => 'https://www.ttc.ca/service-advisories/Service-Changes/510-310',
                ],
            ],
        ]);
    });

    $this->artisan('transit:fetch-alerts')
        ->expectsOutput('Fetching TTC transit alerts...')
        ->expectsOutputToContain('Done. 2 active alerts synced, 1 marked inactive.')
        ->assertExitCode(0);

    expect(TransitAlert::count())->toBe(3);

    $this->assertDatabaseHas('transit_alerts', [
        'external_id' => 'api:stay-alert',
        'title' => 'Updated title',
        'is_active' => true,
        'feed_updated_at' => '2026-02-05 15:00:00',
    ]);

    $this->assertDatabaseHas('transit_alerts', [
        'external_id' => 'sxa:4976b805-daf7-43f7-96c1-c3da717a7877',
        'is_active' => true,
        'feed_updated_at' => '2026-02-05 15:00:00',
    ]);

    $this->assertDatabaseHas('transit_alerts', [
        'external_id' => 'api:old-alert',
        'is_active' => false,
    ]);
});

test('it returns failure when feed fetch throws', function () {
    $this->mock(TtcAlertsFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andThrow(new RuntimeException('Primary source unavailable'));
    });

    $this->artisan('transit:fetch-alerts')
        ->expectsOutput('Feed fetch failed: Primary source unavailable')
        ->assertExitCode(1);
});
