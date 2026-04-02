<?php

use App\Models\MiwayAlert;
use App\Models\YrtAlert;
use App\Services\Notifications\NotificationAlertFactory;
use App\Services\Notifications\NotificationSeverity;
use Carbon\CarbonImmutable;

uses(Tests\TestCase::class);

test('it maps miway gtfs-rt effects to notification severity', function (?string $effect, string $expectedSeverity) {
    $factory = app(NotificationAlertFactory::class);

    $alert = MiwayAlert::factory()->make([
        'effect' => $effect,
        'starts_at' => null,
        'created_at' => CarbonImmutable::parse('2026-03-31T00:00:00Z'),
    ]);

    $notificationAlert = $factory->fromMiwayAlert($alert);

    expect($notificationAlert->severity)->toBe($expectedSeverity);
})->with([
    [null, NotificationSeverity::MINOR],
    ['', NotificationSeverity::MINOR],
    ['UNKNOWN_EFFECT', NotificationSeverity::MINOR],
    ['NO_EFFECT', NotificationSeverity::MINOR],
    ['NO_SERVICE', NotificationSeverity::CRITICAL],
    ['REDUCED_SERVICE', NotificationSeverity::MAJOR],
    ['SIGNIFICANT_DELAYS', NotificationSeverity::MAJOR],
    ['DETOUR', NotificationSeverity::MAJOR],
]);

test('it maps yrt alerts to notification alert contract', function () {
    $factory = app(NotificationAlertFactory::class);

    $alert = YrtAlert::factory()->make([
        'external_id' => 'northbound-delay',
        'title' => 'Northbound delay on Route 99',
        'posted_at' => CarbonImmutable::parse('2026-04-01T12:00:00Z'),
        'route_text' => '99 - Yonge',
        'details_url' => 'https://www.yrt.ca/en/service-updates/northbound-delay.aspx',
        'description_excerpt' => 'Delay due to construction.',
        'body_text' => 'Expect delays for 20 minutes.',
    ]);

    $notificationAlert = $factory->fromYrtAlert($alert);

    expect($notificationAlert->alertId)->toBe('yrt:northbound-delay')
        ->and($notificationAlert->source)->toBe('yrt')
        ->and($notificationAlert->severity)->toBe(NotificationSeverity::MAJOR)
        ->and($notificationAlert->summary)->toBe('Northbound delay on Route 99')
        ->and($notificationAlert->occurredAt->toIso8601String())->toBe('2026-04-01T12:00:00+00:00')
        ->and($notificationAlert->routes)->toBe(['99 - Yonge'])
        ->and($notificationAlert->metadata)->toMatchArray([
            'details_url' => 'https://www.yrt.ca/en/service-updates/northbound-delay.aspx',
            'description_excerpt' => 'Delay due to construction.',
            'body_text' => 'Expect delays for 20 minutes.',
            'route_text' => '99 - Yonge',
        ]);
});
