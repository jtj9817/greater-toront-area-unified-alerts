<?php

use App\Events\AlertCreated;
use App\Models\YrtAlert;
use App\Services\YrtServiceAdvisoriesFeedService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function yrtPayload(string $externalId, array $overrides = []): array
{
    return array_merge([
        'external_id' => $externalId,
        'title' => 'Service advisory for '.$externalId,
        'posted_at' => CarbonImmutable::parse('2026-04-01T12:00:00Z'),
        'details_url' => "https://www.yrt.ca/en/service-updates/{$externalId}.aspx",
        'description_excerpt' => 'Route detour in effect.',
        'route_text' => '99 - Yonge',
        'body_text' => 'Detour due to roadwork.',
        'list_hash' => sha1($externalId.'-hash'),
        'details_fetched_at' => CarbonImmutable::parse('2026-04-01T12:05:00Z'),
        'is_active' => true,
    ], $overrides);
}

it('syncs active yrt advisories into storage', function () {
    Event::fake([AlertCreated::class]);

    $feedData = [
        'updated_at' => CarbonImmutable::parse('2026-04-01T12:10:00Z'),
        'alerts' => [
            yrtPayload('northbound-delay'),
            yrtPayload('detour-yonge'),
        ],
    ];

    $this->mock(YrtServiceAdvisoriesFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('yrt:fetch-alerts')
        ->expectsOutput('Fetching YRT service advisories...')
        ->expectsOutputToContain('Done. 2 active alerts synced, 0 marked inactive.')
        ->assertExitCode(0);

    expect(YrtAlert::query()->count())->toBe(2)
        ->and(YrtAlert::query()->where('external_id', 'northbound-delay')->first()?->is_active)->toBeTrue()
        ->and(YrtAlert::query()->where('external_id', 'detour-yonge')->first()?->feed_updated_at?->toIso8601String())
        ->toBe('2026-04-01T12:10:00+00:00');
});

it('deactivates stale advisories that are no longer present in the latest feed', function () {
    Event::fake([AlertCreated::class]);

    YrtAlert::factory()->create([
        'external_id' => 'stale-advisory',
        'is_active' => true,
    ]);
    YrtAlert::factory()->create([
        'external_id' => 'still-active',
        'is_active' => true,
    ]);

    $feedData = [
        'updated_at' => CarbonImmutable::parse('2026-04-01T13:00:00Z'),
        'alerts' => [
            yrtPayload('still-active'),
            yrtPayload('new-advisory'),
        ],
    ];

    $this->mock(YrtServiceAdvisoriesFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('yrt:fetch-alerts')
        ->expectsOutputToContain('Done. 2 active alerts synced, 1 marked inactive.')
        ->assertExitCode(0);

    expect(YrtAlert::query()->where('external_id', 'stale-advisory')->first()?->is_active)->toBeFalse()
        ->and(YrtAlert::query()->where('external_id', 'still-active')->first()?->is_active)->toBeTrue()
        ->and(YrtAlert::query()->where('external_id', 'new-advisory')->first()?->is_active)->toBeTrue();
});

it('is idempotent for unchanged repeated runs', function () {
    Event::fake([AlertCreated::class]);

    $feedData = [
        'updated_at' => CarbonImmutable::parse('2026-04-01T14:00:00Z'),
        'alerts' => [
            yrtPayload('same-alert'),
        ],
    ];

    $this->mock(YrtServiceAdvisoriesFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->twice()->andReturn($feedData);
    });

    $this->artisan('yrt:fetch-alerts')->assertExitCode(0);
    $this->artisan('yrt:fetch-alerts')->assertExitCode(0);

    expect(YrtAlert::query()->count())->toBe(1)
        ->and(YrtAlert::query()->where('external_id', 'same-alert')->first()?->is_active)->toBeTrue();

    Event::assertDispatchedTimes(AlertCreated::class, 1);
});

it('dispatches AlertCreated only for new or reactivated advisories', function () {
    Event::fake([AlertCreated::class]);

    YrtAlert::factory()->create([
        'external_id' => 'already-active',
        'is_active' => true,
    ]);
    YrtAlert::factory()->inactive()->create([
        'external_id' => 'reactivated-alert',
        'is_active' => false,
    ]);

    $feedData = [
        'updated_at' => CarbonImmutable::parse('2026-04-01T15:00:00Z'),
        'alerts' => [
            yrtPayload('already-active'),
            yrtPayload('reactivated-alert'),
            yrtPayload('brand-new'),
        ],
    ];

    $this->mock(YrtServiceAdvisoriesFeedService::class, function (MockInterface $mock) use ($feedData) {
        $mock->shouldReceive('fetch')->once()->andReturn($feedData);
    });

    $this->artisan('yrt:fetch-alerts')->assertExitCode(0);

    Event::assertDispatchedTimes(AlertCreated::class, 2);
    Event::assertDispatched(AlertCreated::class, function (AlertCreated $event): bool {
        return in_array($event->alert->alertId, ['yrt:reactivated-alert', 'yrt:brand-new'], true);
    });
    Event::assertNotDispatched(AlertCreated::class, function (AlertCreated $event): bool {
        return $event->alert->alertId === 'yrt:already-active';
    });
});

it('handles feed service failures gracefully', function () {
    $this->mock(YrtServiceAdvisoriesFeedService::class, function (MockInterface $mock) {
        $mock->shouldReceive('fetch')->once()->andThrow(new \RuntimeException('Service unavailable'));
    });

    $this->artisan('yrt:fetch-alerts')
        ->expectsOutput('Feed fetch failed: Service unavailable')
        ->assertExitCode(1);
});
