<?php

use App\Events\AlertCreated;
use App\Models\DrtAlert;
use App\Services\DrtServiceAlertsFeedService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function drtPayload(string $externalId, array $overrides = []): array
{
    return array_merge([
        'external_id' => $externalId,
        'title' => 'Service alert for '.$externalId,
        'posted_at' => CarbonImmutable::parse('2026-04-03T12:00:00Z'),
        'when_text' => 'Apr 3, 2026 10:00 AM - Until further notice',
        'route_text' => 'Route 901',
        'details_url' => "https://www.durhamregiontransit.com/en/news/{$externalId}.aspx",
        'body_text' => 'Temporary detour in effect.',
        'list_hash' => sha1($externalId.'-hash'),
        'details_fetched_at' => CarbonImmutable::parse('2026-04-03T12:05:00Z'),
        'is_active' => true,
    ], $overrides);
}

it('syncs active drt alerts into storage', function () {
    Event::fake([AlertCreated::class]);

    $feedData = [
        'updated_at' => CarbonImmutable::parse('2026-04-03T12:10:00Z'),
        'alerts' => [
            drtPayload('conlin-grandview-detour'),
            drtPayload('route-920-921-stop-change'),
        ],
    ];

    $this->mock(DrtServiceAlertsFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('drt:fetch-alerts')
        ->expectsOutput('Fetching DRT service alerts...')
        ->expectsOutputToContain('Done. 2 active alerts synced, 0 marked inactive.')
        ->assertExitCode(0);

    expect(DrtAlert::query()->count())->toBe(2)
        ->and(DrtAlert::query()->where('external_id', 'conlin-grandview-detour')->first()?->is_active)->toBeTrue()
        ->and(DrtAlert::query()->where('external_id', 'route-920-921-stop-change')->first()?->feed_updated_at?->toIso8601String())
        ->toBe('2026-04-03T12:10:00+00:00');
});

it('deactivates stale drt alerts missing from latest feed', function () {
    Event::fake([AlertCreated::class]);

    DrtAlert::factory()->create([
        'external_id' => 'stale-alert',
        'is_active' => true,
    ]);
    DrtAlert::factory()->create([
        'external_id' => 'still-active',
        'is_active' => true,
    ]);

    $feedData = [
        'updated_at' => CarbonImmutable::parse('2026-04-03T13:00:00Z'),
        'alerts' => [
            drtPayload('still-active'),
            drtPayload('new-alert'),
        ],
    ];

    $this->mock(DrtServiceAlertsFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('drt:fetch-alerts')
        ->expectsOutputToContain('Done. 2 active alerts synced, 1 marked inactive.')
        ->assertExitCode(0);

    expect(DrtAlert::query()->where('external_id', 'stale-alert')->first()?->is_active)->toBeFalse()
        ->and(DrtAlert::query()->where('external_id', 'still-active')->first()?->is_active)->toBeTrue()
        ->and(DrtAlert::query()->where('external_id', 'new-alert')->first()?->is_active)->toBeTrue();
});

it('is idempotent across unchanged repeated runs', function () {
    Event::fake([AlertCreated::class]);

    $feedData = [
        'updated_at' => CarbonImmutable::parse('2026-04-03T14:00:00Z'),
        'alerts' => [
            drtPayload('same-alert'),
        ],
    ];

    $this->mock(DrtServiceAlertsFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->twice()->andReturn($feedData);
    });

    $this->artisan('drt:fetch-alerts')->assertExitCode(0);
    $this->artisan('drt:fetch-alerts')->assertExitCode(0);

    expect(DrtAlert::query()->count())->toBe(1)
        ->and(DrtAlert::query()->where('external_id', 'same-alert')->first()?->is_active)->toBeTrue();

    Event::assertDispatchedTimes(AlertCreated::class, 1);
});

it('dispatches AlertCreated only for new or reactivated drt alerts', function () {
    Event::fake([AlertCreated::class]);

    DrtAlert::factory()->create([
        'external_id' => 'already-active',
        'is_active' => true,
    ]);
    DrtAlert::factory()->inactive()->create([
        'external_id' => 'reactivated-alert',
        'is_active' => false,
    ]);

    $feedData = [
        'updated_at' => CarbonImmutable::parse('2026-04-03T15:00:00Z'),
        'alerts' => [
            drtPayload('already-active'),
            drtPayload('reactivated-alert'),
            drtPayload('brand-new'),
        ],
    ];

    $this->mock(DrtServiceAlertsFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('drt:fetch-alerts')->assertExitCode(0);

    Event::assertDispatchedTimes(AlertCreated::class, 2);
    Event::assertDispatched(AlertCreated::class, function (AlertCreated $event): bool {
        return in_array($event->alert->alertId, ['drt:reactivated-alert', 'drt:brand-new'], true);
    });
    Event::assertNotDispatched(AlertCreated::class, function (AlertCreated $event): bool {
        return $event->alert->alertId === 'drt:already-active';
    });
});

it('handles feed service failures gracefully', function () {
    $this->mock(DrtServiceAlertsFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andThrow(new RuntimeException('Service unavailable'));
    });

    $this->artisan('drt:fetch-alerts')
        ->expectsOutput('Feed fetch failed: Service unavailable')
        ->assertExitCode(1);
});
