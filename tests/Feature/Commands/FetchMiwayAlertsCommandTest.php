<?php

use App\Events\AlertCreated;
use App\Models\MiwayAlert;
use App\Services\MiwayGtfsRtAlertsFeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

it('syncs active miway alerts and logs feed timestamp', function () {
    Event::fake();

    $feedData = [
        'updated_at' => \Carbon\Carbon::parse('2026-03-31T12:00:00Z'),
        'alerts' => [
            [
                'external_id' => 'alert_1',
                'header_text' => 'Stop 123 Closed',
                'description_text' => 'Stop is closed.',
                'cause' => 'CONSTRUCTION',
                'effect' => 'DETOUR',
                'starts_at' => \Carbon\Carbon::parse('2026-03-31T08:00:00Z'),
                'ends_at' => \Carbon\Carbon::parse('2026-04-05T23:59:59Z'),
                'url' => 'https://example.com/alert1',
                'detour_pdf_url' => 'https://example.com/detour.pdf',
            ],
            [
                'external_id' => 'alert_2',
                'header_text' => 'Delay on 17',
                'description_text' => null,
                'cause' => 'TRAFFIC',
                'effect' => 'SIGNIFICANT_DELAYS',
                'starts_at' => null,
                'ends_at' => null,
                'url' => null,
                'detour_pdf_url' => null,
            ],
        ],
    ];

    $this->mock(MiwayGtfsRtAlertsFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('miway:fetch-alerts')
        ->expectsOutput('Fetching MiWay service alerts...')
        ->expectsOutputToContain('Done. 2 active alerts synced, 0 marked inactive.')
        ->assertExitCode(0);

    expect(MiwayAlert::count())->toBe(2);

    $alert1 = MiwayAlert::where('external_id', 'alert_1')->first();
    expect($alert1->is_active)->toBeTrue()
        ->and($alert1->header_text)->toBe('Stop 123 Closed')
        ->and($alert1->cause)->toBe('CONSTRUCTION')
        ->and($alert1->url)->toBe('https://example.com/alert1')
        ->and($alert1->detour_pdf_url)->toBe('https://example.com/detour.pdf')
        ->and($alert1->feed_updated_at->toIso8601String())->toBe('2026-03-31T12:00:00+00:00');

    Event::assertDispatched(AlertCreated::class, 2);
});

it('deactivates stale records when they disappear from the feed', function () {
    Event::fake();

    MiwayAlert::factory()->create([
        'external_id' => 'stale_1',
        'is_active' => true,
    ]);

    MiwayAlert::factory()->create([
        'external_id' => 'keep_1',
        'is_active' => true,
    ]);

    $feedData = [
        'updated_at' => \Carbon\Carbon::parse('2026-03-31T12:00:00Z'),
        'alerts' => [
            [
                'external_id' => 'keep_1',
                'header_text' => 'Kept Alert',
                'description_text' => null,
                'cause' => 'OTHER_CAUSE',
                'effect' => 'OTHER_EFFECT',
                'starts_at' => null,
                'ends_at' => null,
                'url' => null,
                'detour_pdf_url' => null,
            ],
            [
                'external_id' => 'new_1',
                'header_text' => 'New Alert',
                'description_text' => null,
                'cause' => 'OTHER_CAUSE',
                'effect' => 'OTHER_EFFECT',
                'starts_at' => null,
                'ends_at' => null,
                'url' => null,
                'detour_pdf_url' => null,
            ],
        ],
    ];

    $this->mock(MiwayGtfsRtAlertsFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('miway:fetch-alerts')
        ->expectsOutputToContain('Done. 2 active alerts synced, 1 marked inactive.')
        ->assertExitCode(0);

    expect(MiwayAlert::where('external_id', 'stale_1')->first()->is_active)->toBeFalse();
    expect(MiwayAlert::where('external_id', 'keep_1')->first()->is_active)->toBeTrue();
    expect(MiwayAlert::where('external_id', 'new_1')->first()->is_active)->toBeTrue();

    // AlertCreated only dispatched for new_1. keep_1 was already active.
    Event::assertDispatched(AlertCreated::class, 1);
});

it('exits early on not_modified and does not touch the database', function () {
    Event::fake();

    MiwayAlert::factory()->create([
        'external_id' => 'existing_1',
        'is_active' => true,
    ]);

    $feedData = [
        'updated_at' => \Carbon\Carbon::now(),
        'alerts' => [],
        'not_modified' => true,
    ];

    $this->mock(MiwayGtfsRtAlertsFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('miway:fetch-alerts')
        ->expectsOutputToContain('Feed not modified. Exiting.')
        ->assertExitCode(0);

    expect(MiwayAlert::where('external_id', 'existing_1')->first()->is_active)->toBeTrue();

    Event::assertNotDispatched(AlertCreated::class);
});

it('reactivates previously inactive alerts and dispatches event', function () {
    Event::fake();

    MiwayAlert::factory()->create([
        'external_id' => 'revived_1',
        'is_active' => false,
    ]);

    $feedData = [
        'updated_at' => \Carbon\Carbon::parse('2026-03-31T12:00:00Z'),
        'alerts' => [
            [
                'external_id' => 'revived_1',
                'header_text' => 'Revived Alert',
                'description_text' => null,
                'cause' => 'OTHER_CAUSE',
                'effect' => 'OTHER_EFFECT',
                'starts_at' => null,
                'ends_at' => null,
                'url' => null,
                'detour_pdf_url' => null,
            ],
        ],
    ];

    $this->mock(MiwayGtfsRtAlertsFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('miway:fetch-alerts')
        ->assertExitCode(0);

    expect(MiwayAlert::where('external_id', 'revived_1')->first()->is_active)->toBeTrue();

    Event::assertDispatched(AlertCreated::class, 1);
});

it('handles service failures gracefully', function () {
    $this->mock(MiwayGtfsRtAlertsFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andThrow(new RuntimeException('Service unavailable'));
    });

    $this->artisan('miway:fetch-alerts')
        ->expectsOutput('Feed fetch failed: Service unavailable')
        ->assertExitCode(1);
});
