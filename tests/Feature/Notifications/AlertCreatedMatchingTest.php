<?php

use App\Events\AlertCreated;
use App\Jobs\DeliverAlertNotificationJob;
use App\Models\NotificationPreference;
use App\Models\SavedPlace;
use App\Services\Notifications\NotificationAlert;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('alert created queues notification jobs only for matching preferences', function () {
    Queue::fake();

    $matching = NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'major',
        'subscriptions' => [],
        'push_enabled' => true,
    ]);

    SavedPlace::factory()->create([
        'user_id' => $matching->user_id,
        'name' => 'Downtown',
        'lat' => 43.7000,
        'long' => -79.4000,
        'radius' => 3000,
        'type' => 'address',
    ]);

    NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'major',
        'subscriptions' => [],
        'push_enabled' => false,
    ]);

    NotificationPreference::factory()->create([
        'alert_type' => 'transit',
        'severity_threshold' => 'minor',
        'subscriptions' => [],
        'push_enabled' => true,
    ]);

    NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'critical',
        'subscriptions' => [],
        'push_enabled' => true,
    ]);

    NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'major',
        'subscriptions' => [],
        'push_enabled' => true,
    ]);

    $farAway = NotificationPreference::query()
        ->where('alert_type', 'emergency')
        ->where('severity_threshold', 'major')
        ->where('push_enabled', true)
        ->where('user_id', '!=', $matching->user_id)
        ->latest('id')
        ->firstOrFail();

    SavedPlace::factory()->create([
        'user_id' => $farAway->user_id,
        'name' => 'Far Away',
        'lat' => 44.1000,
        'long' => -79.1000,
        'radius' => 1000,
        'type' => 'address',
    ]);

    event(new AlertCreated(new NotificationAlert(
        alertId: 'police:123',
        source: 'police',
        severity: 'major',
        summary: 'Police response in progress',
        occurredAt: CarbonImmutable::parse('2026-02-10T16:00:00Z'),
        lat: 43.7010,
        lng: -79.4010,
    )));

    Queue::assertPushed(DeliverAlertNotificationJob::class, 1);
    Queue::assertPushed(DeliverAlertNotificationJob::class, function (DeliverAlertNotificationJob $job) use ($matching): bool {
        return $job->userId === $matching->user_id
            && $job->payload['alert_id'] === 'police:123'
            && $job->payload['severity'] === 'major'
            && $job->payload['source'] === 'police';
    });
});

test('transit alerts respect subscribed route matching when provided', function () {
    Queue::fake();

    $matching = NotificationPreference::factory()->create([
        'alert_type' => 'transit',
        'severity_threshold' => 'minor',
        'subscriptions' => ['route:501', 'route:go-lw'],
        'push_enabled' => true,
    ]);

    NotificationPreference::factory()->create([
        'alert_type' => 'transit',
        'severity_threshold' => 'minor',
        'subscriptions' => ['route:504'],
        'push_enabled' => true,
    ]);

    event(new AlertCreated(new NotificationAlert(
        alertId: 'transit:api:501-test',
        source: 'transit',
        severity: 'minor',
        summary: 'Route 501 service adjustment',
        occurredAt: CarbonImmutable::parse('2026-02-10T17:00:00Z'),
        routes: ['501'],
    )));

    Queue::assertPushed(DeliverAlertNotificationJob::class, 1);
    Queue::assertPushed(DeliverAlertNotificationJob::class, fn (DeliverAlertNotificationJob $job): bool => $job->userId === $matching->user_id);
});
