<?php

use App\Events\AlertNotificationSent;
use App\Jobs\DeliverAlertNotificationJob;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

test('it retries broadcast for existing sent notification log and marks delivered', function () {
    Event::fake([AlertNotificationSent::class]);

    $user = User::factory()->create();

    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'push_enabled' => true,
        'alert_type' => 'all',
        'severity_threshold' => 'all',
        'subscribed_routes' => [],
    ]);

    NotificationLog::factory()->create([
        'user_id' => $user->id,
        'alert_id' => 'police:123',
        'delivery_method' => 'in_app',
        'status' => 'sent',
        'sent_at' => CarbonImmutable::parse('2026-02-10T10:00:00Z'),
    ]);

    $job = new DeliverAlertNotificationJob(
        userId: $user->id,
        payload: [
            'alert_id' => 'police:123',
            'source' => 'police',
            'severity' => 'major',
            'summary' => 'Police response in progress',
            'occurred_at' => '2026-02-10T09:58:00+00:00',
            'routes' => [],
            'metadata' => [],
        ],
    );

    $job->handle();

    Event::assertDispatched(AlertNotificationSent::class, function (AlertNotificationSent $event) use ($user): bool {
        return $event->userId === $user->id
            && $event->alertId === 'police:123'
            && $event->source === 'police';
    });

    $this->assertDatabaseHas('notification_logs', [
        'user_id' => $user->id,
        'alert_id' => 'police:123',
        'delivery_method' => 'in_app',
        'status' => 'delivered',
    ]);
});

test('it skips rebroadcast when notification log is already delivered', function () {
    Event::fake([AlertNotificationSent::class]);

    $user = User::factory()->create();

    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'push_enabled' => true,
        'alert_type' => 'all',
        'severity_threshold' => 'all',
        'subscribed_routes' => [],
    ]);

    NotificationLog::factory()->create([
        'user_id' => $user->id,
        'alert_id' => 'police:123',
        'delivery_method' => 'in_app',
        'status' => 'delivered',
    ]);

    $job = new DeliverAlertNotificationJob(
        userId: $user->id,
        payload: [
            'alert_id' => 'police:123',
            'source' => 'police',
            'severity' => 'major',
            'summary' => 'Police response in progress',
            'occurred_at' => '2026-02-10T09:58:00+00:00',
            'routes' => [],
            'metadata' => [],
        ],
    );

    $job->handle();

    Event::assertNotDispatched(AlertNotificationSent::class);
});
