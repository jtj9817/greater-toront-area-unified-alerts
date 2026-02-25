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

function makeDeliveryPayload(array $overrides = []): array
{
    return array_replace([
        'alert_id' => 'police:123',
        'source' => 'police',
        'severity' => 'major',
        'summary' => 'Police response in progress',
        'occurred_at' => '2026-02-10T09:58:00+00:00',
        'routes' => [],
        'metadata' => [],
    ], $overrides);
}

test('it retries broadcast for existing sent notification log and marks delivered', function () {
    Event::fake([AlertNotificationSent::class]);

    $user = User::factory()->create();

    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'push_enabled' => true,
        'alert_type' => 'all',
        'severity_threshold' => 'all',
        'subscriptions' => [],
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
        ]
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
        'subscriptions' => [],
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
        ]
    );

    $job->handle();

    Event::assertNotDispatched(AlertNotificationSent::class);
});

test('it has correct retry configuration', function () {
    $job = new DeliverAlertNotificationJob(
        userId: 1,
        payload: [
            'alert_id' => 'police:123',
            'source' => 'police',
            'severity' => 'major',
            'summary' => 'Police response in progress',
            'occurred_at' => '2026-02-10T09:58:00+00:00',
            'routes' => [],
            'metadata' => [],
        ]
    );

    expect($job->tries)->toBe(5);
    expect($job->backoff)->toBe(10);
});

test('it exits early when user has no notification preference', function () {
    Event::fake([AlertNotificationSent::class]);

    $user = User::factory()->create();

    $job = new DeliverAlertNotificationJob(
        userId: $user->id,
        payload: makeDeliveryPayload(),
    );

    $job->handle();

    Event::assertNotDispatched(AlertNotificationSent::class);
    expect(NotificationLog::query()->count())->toBe(0);
});

test('it exits early when push notifications are disabled', function () {
    Event::fake([AlertNotificationSent::class]);

    $user = User::factory()->create();
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'push_enabled' => false,
        'alert_type' => 'all',
        'severity_threshold' => 'all',
        'subscriptions' => [],
    ]);

    $job = new DeliverAlertNotificationJob(
        userId: $user->id,
        payload: makeDeliveryPayload(),
    );

    $job->handle();

    Event::assertNotDispatched(AlertNotificationSent::class);
    expect(NotificationLog::query()->count())->toBe(0);
});

test('it exits early when payload cannot produce a valid alert id or source', function () {
    Event::fake([AlertNotificationSent::class]);

    $user = User::factory()->create();
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'push_enabled' => true,
        'alert_type' => 'all',
        'severity_threshold' => 'all',
        'subscriptions' => [],
    ]);

    $job = new DeliverAlertNotificationJob(
        userId: $user->id,
        payload: makeDeliveryPayload([
            'alert_id' => '',
            'source' => '',
        ]),
    );

    $job->handle();

    Event::assertNotDispatched(AlertNotificationSent::class);
    expect(NotificationLog::query()->count())->toBe(0);
});

test('it exits safely when notification log claim update affects zero rows', function () {
    Event::fake([AlertNotificationSent::class]);

    $user = User::factory()->create();
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'push_enabled' => true,
        'alert_type' => 'all',
        'severity_threshold' => 'all',
        'subscriptions' => [],
    ]);

    $log = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'alert_id' => 'police:123',
        'delivery_method' => 'in_app',
        'status' => 'processing',
    ]);

    $job = new DeliverAlertNotificationJob(
        userId: $user->id,
        payload: makeDeliveryPayload(),
    );

    $job->handle();

    Event::assertNotDispatched(AlertNotificationSent::class);
    $log->refresh();
    expect($log->status)->toBe('processing');
});

test('it resets processing status to sent when event dispatch throws', function () {
    $user = User::factory()->create();
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'push_enabled' => true,
        'alert_type' => 'all',
        'severity_threshold' => 'all',
        'subscriptions' => [],
    ]);

    $log = NotificationLog::factory()->create([
        'user_id' => $user->id,
        'alert_id' => 'police:123',
        'delivery_method' => 'in_app',
        'status' => 'sent',
    ]);

    Event::listen(AlertNotificationSent::class, function (): void {
        throw new RuntimeException('dispatch failed');
    });

    $job = new DeliverAlertNotificationJob(
        userId: $user->id,
        payload: makeDeliveryPayload(),
    );

    expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'dispatch failed');

    $log->refresh();
    expect($log->status)->toBe('sent');
});

test('it stores expected metadata shape when creating a notification log for first delivery', function () {
    Event::fake([AlertNotificationSent::class]);

    $user = User::factory()->create();
    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'push_enabled' => true,
        'alert_type' => 'all',
        'severity_threshold' => 'all',
        'subscriptions' => [],
    ]);

    $job = new DeliverAlertNotificationJob(
        userId: $user->id,
        payload: makeDeliveryPayload([
            'alert_id' => 'fire:meta-123',
            'source' => 'fire',
            'severity' => 'critical',
            'occurred_at' => '2026-02-22T14:33:00+00:00',
            'routes' => ['501', '504'],
        ]),
    );

    $job->handle();

    $log = NotificationLog::query()->where('alert_id', 'fire:meta-123')->firstOrFail();

    expect($log->status)->toBe('delivered');
    expect($log->metadata)->toBeArray();
    expect($log->metadata['source'] ?? null)->toBe('fire');
    expect($log->metadata['severity'] ?? null)->toBe('critical');
    expect($log->metadata['summary'] ?? null)->toBe('Police response in progress');
    expect($log->metadata['occurred_at'] ?? null)->toBe('2026-02-22T14:33:00+00:00');
    expect($log->metadata['routes'] ?? null)->toBe(['501', '504']);
});
