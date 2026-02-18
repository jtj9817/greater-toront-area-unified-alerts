<?php

use App\Events\AlertCreated;
use App\Jobs\DeliverAlertNotificationJob;
use App\Jobs\DispatchAlertNotificationChunkJob;
use App\Jobs\FanOutAlertNotificationsJob;
use App\Models\NotificationPreference;
use App\Models\SavedPlace;
use App\Services\Notifications\NotificationAlert;
use App\Services\Notifications\NotificationMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * @return Collection<int, DeliverAlertNotificationJob>
 */
function fanOutDeliveriesForAlert(NotificationAlert $alert): Collection
{
    Queue::fake();

    $fanOutJob = new FanOutAlertNotificationsJob(
        payload: $alert->toPayload(),
    );
    $fanOutJob->handle(app(NotificationMatcher::class));

    $chunkJobs = Queue::pushed(DispatchAlertNotificationChunkJob::class);

    Queue::fake();

    foreach ($chunkJobs as $chunkJob) {
        $chunkJob->handle();
    }

    return Queue::pushed(DeliverAlertNotificationJob::class);
}

test('alert created queues a fan-out job with alert payload', function () {
    Queue::fake();

    $alert = new NotificationAlert(
        alertId: 'police:listener-001',
        source: 'police',
        severity: 'major',
        summary: 'Police response in progress',
        occurredAt: CarbonImmutable::parse('2026-02-10T16:00:00Z'),
        lat: 43.7010,
        lng: -79.4010,
    );

    event(new AlertCreated($alert));

    Queue::assertPushed(FanOutAlertNotificationsJob::class, 1);
    Queue::assertPushed(FanOutAlertNotificationsJob::class, function (FanOutAlertNotificationsJob $job): bool {
        return $job->payload['alert_id'] === 'police:listener-001'
            && $job->payload['source'] === 'police'
            && $job->payload['severity'] === 'major';
    });
});

test('fan-out pipeline queues notification jobs only for matching preferences', function () {
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

    $deliveries = fanOutDeliveriesForAlert(new NotificationAlert(
        alertId: 'police:123',
        source: 'police',
        severity: 'major',
        summary: 'Police response in progress',
        occurredAt: CarbonImmutable::parse('2026-02-10T16:00:00Z'),
        lat: 43.7010,
        lng: -79.4010,
    ));

    expect($deliveries)->toHaveCount(1);

    $deliveryJob = $deliveries->sole();

    expect($deliveryJob->userId)->toBe($matching->user_id);
    expect($deliveryJob->payload['alert_id'])->toBe('police:123');
    expect($deliveryJob->payload['severity'])->toBe('major');
    expect($deliveryJob->payload['source'])->toBe('police');
});

test('transit alerts respect subscribed route matching when provided', function () {
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

    $deliveries = fanOutDeliveriesForAlert(new NotificationAlert(
        alertId: 'transit:api:501-test',
        source: 'transit',
        severity: 'minor',
        summary: 'Route 501 service adjustment',
        occurredAt: CarbonImmutable::parse('2026-02-10T17:00:00Z'),
        routes: ['501'],
    ));

    expect($deliveries)->toHaveCount(1);
    expect($deliveries->sole()->userId)->toBe($matching->user_id);
});

test('geofence matching includes alerts exactly on the saved-place boundary', function () {
    $preference = NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'minor',
        'subscriptions' => [],
        'push_enabled' => true,
    ]);

    $centerLat = 43.6532;
    $centerLng = -79.3832;

    SavedPlace::factory()->create([
        'user_id' => $preference->user_id,
        'name' => 'Boundary Place',
        'lat' => $centerLat,
        'long' => $centerLng,
        'radius' => 0,
        'type' => 'address',
    ]);

    $deliveries = fanOutDeliveriesForAlert(new NotificationAlert(
        alertId: 'police:boundary-001',
        source: 'police',
        severity: 'major',
        summary: 'Alert exactly on geofence boundary',
        occurredAt: CarbonImmutable::parse('2026-02-12T16:00:00Z'),
        lat: $centerLat,
        lng: $centerLng,
    ));

    expect($deliveries)->toHaveCount(1);
    expect($deliveries->sole()->userId)->toBe($preference->user_id);
});

test('alerts with missing coordinates do not match users with saved places', function () {
    $preference = NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'minor',
        'subscriptions' => [],
        'push_enabled' => true,
    ]);

    SavedPlace::factory()->create([
        'user_id' => $preference->user_id,
        'name' => 'Coordinate Required',
        'lat' => 43.6532,
        'long' => -79.3832,
        'radius' => 2000,
        'type' => 'address',
    ]);

    $deliveries = fanOutDeliveriesForAlert(new NotificationAlert(
        alertId: 'police:no-lat-lng',
        source: 'police',
        severity: 'major',
        summary: 'Coordinates unavailable',
        occurredAt: CarbonImmutable::parse('2026-02-12T17:00:00Z'),
    ));

    expect($deliveries)->toHaveCount(0);
});

test('geofence matching succeeds when at least one saved place is within range', function () {
    $preference = NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'minor',
        'subscriptions' => [],
        'push_enabled' => true,
    ]);

    SavedPlace::factory()->create([
        'user_id' => $preference->user_id,
        'name' => 'Far Place',
        'lat' => 44.2000,
        'long' => -79.8000,
        'radius' => 500,
        'type' => 'address',
    ]);

    SavedPlace::factory()->create([
        'user_id' => $preference->user_id,
        'name' => 'Nearby Place',
        'lat' => 43.7000,
        'long' => -79.4000,
        'radius' => 2000,
        'type' => 'address',
    ]);

    $deliveries = fanOutDeliveriesForAlert(new NotificationAlert(
        alertId: 'police:multi-place-001',
        source: 'police',
        severity: 'major',
        summary: 'Nearby alert',
        occurredAt: CarbonImmutable::parse('2026-02-12T18:00:00Z'),
        lat: 43.7010,
        lng: -79.4010,
    ));

    expect($deliveries)->toHaveCount(1);
    expect($deliveries->sole()->userId)->toBe($preference->user_id);
});
