<?php

use App\Models\MiwayAlert;
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
