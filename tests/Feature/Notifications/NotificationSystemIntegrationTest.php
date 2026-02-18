<?php

use App\Events\AlertCreated;
use App\Events\AlertNotificationSent;
use App\Jobs\DeliverAlertNotificationJob;
use App\Jobs\DispatchAlertNotificationChunkJob;
use App\Jobs\FanOutAlertNotificationsJob;
use App\Jobs\GenerateDailyDigestJob;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\SavedPlace;
use App\Models\User;
use App\Services\Notifications\NotificationAlert;
use App\Services\Notifications\NotificationMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('matching alert flows through dispatch, delivery, broadcast, inbox, and mark-as-read', function () {
    // Phase 1: Event dispatches fan-out job and fan-out dispatches delivery jobs
    Queue::fake();

    $user = User::factory()->create();

    $preference = NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'alert_type' => 'emergency',
        'severity_threshold' => 'major',
        'subscriptions' => [],
        'push_enabled' => true,
        'digest_mode' => false,
    ]);

    SavedPlace::factory()->create([
        'user_id' => $preference->user_id,
        'name' => 'Downtown Toronto',
        'lat' => 43.6532,
        'long' => -79.3832,
        'radius' => 5000,
        'type' => 'address',
    ]);

    $alert = new NotificationAlert(
        alertId: 'police:integration-001',
        source: 'police',
        severity: 'major',
        summary: 'Police response near CN Tower',
        occurredAt: CarbonImmutable::parse('2026-02-11T14:00:00Z'),
        lat: 43.6426,
        lng: -79.3871,
    );

    event(new AlertCreated($alert));

    Queue::assertPushed(FanOutAlertNotificationsJob::class, 1);
    Queue::assertPushed(FanOutAlertNotificationsJob::class, function (FanOutAlertNotificationsJob $job): bool {
        return $job->payload['alert_id'] === 'police:integration-001'
            && $job->payload['source'] === 'police'
            && $job->payload['severity'] === 'major';
    });

    /** @var FanOutAlertNotificationsJob $fanOutJob */
    $fanOutJob = Queue::pushed(FanOutAlertNotificationsJob::class)->sole();

    Queue::fake();

    $fanOutJob->handle(app(NotificationMatcher::class));

    $chunkJobs = Queue::pushed(DispatchAlertNotificationChunkJob::class);
    expect($chunkJobs)->toHaveCount(1);

    Queue::fake();

    foreach ($chunkJobs as $chunkJob) {
        $chunkJob->handle();
    }

    Queue::assertPushed(DeliverAlertNotificationJob::class, 1);
    Queue::assertPushed(DeliverAlertNotificationJob::class, function (DeliverAlertNotificationJob $job) use ($user): bool {
        return $job->userId === $user->id
            && $job->payload['alert_id'] === 'police:integration-001'
            && $job->payload['source'] === 'police'
            && $job->payload['severity'] === 'major';
    });

    // Phase 2: Run the job synchronously, assert log created and event broadcast
    Queue::fake(); // reset
    Event::fake([AlertNotificationSent::class]);

    $job = new DeliverAlertNotificationJob(
        userId: $user->id,
        payload: $alert->toPayload(),
    );

    $job->handle();

    $this->assertDatabaseHas('notification_logs', [
        'user_id' => $user->id,
        'alert_id' => 'police:integration-001',
        'delivery_method' => 'in_app',
        'status' => 'delivered',
    ]);

    Event::assertDispatched(AlertNotificationSent::class, function (AlertNotificationSent $event) use ($user): bool {
        return $event->userId === $user->id
            && $event->alertId === 'police:integration-001'
            && $event->source === 'police'
            && $event->severity === 'major';
    });

    // Phase 3: Fetch inbox as user, assert notification appears
    $response = $this
        ->actingAs($user)
        ->getJson('/notifications/inbox');

    $response
        ->assertOk()
        ->assertJsonPath('meta.unread_count', 1)
        ->assertJsonPath('data.0.alert_id', 'police:integration-001')
        ->assertJsonPath('data.0.type', 'alert')
        ->assertJsonPath('data.0.status', 'delivered');

    $logId = $response->json('data.0.id');

    // Phase 4: Mark as read, assert status transition
    $readResponse = $this
        ->actingAs($user)
        ->patchJson("/notifications/inbox/{$logId}/read");

    $readResponse
        ->assertOk()
        ->assertJsonPath('data.status', 'read');

    $log = NotificationLog::find($logId);
    expect($log->status)->toBe('read');
    expect($log->read_at)->not->toBeNull();
});

test('non-matching geofence alert does not dispatch notification job', function () {
    Queue::fake();

    NotificationPreference::factory()->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'major',
        'subscriptions' => [],
        'push_enabled' => true,
    ]);

    $preference = NotificationPreference::query()->latest('id')->firstOrFail();

    SavedPlace::factory()->create([
        'user_id' => $preference->user_id,
        'name' => 'Vancouver',
        'lat' => 49.2827,
        'long' => -123.1207,
        'radius' => 5000,
        'type' => 'address',
    ]);

    event(new AlertCreated(new NotificationAlert(
        alertId: 'police:faraway-001',
        source: 'police',
        severity: 'major',
        summary: 'Police response in downtown Toronto',
        occurredAt: CarbonImmutable::parse('2026-02-11T14:00:00Z'),
        lat: 43.6532,
        lng: -79.3832,
    )));

    Queue::assertPushed(FanOutAlertNotificationsJob::class, 1);

    /** @var FanOutAlertNotificationsJob $fanOutJob */
    $fanOutJob = Queue::pushed(FanOutAlertNotificationsJob::class)->sole();

    Queue::fake();

    $fanOutJob->handle(app(NotificationMatcher::class));

    Queue::assertNotPushed(DispatchAlertNotificationChunkJob::class);
});

test('digest user receives daily digest entry in inbox', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-02-11T08:00:00Z'));

    try {
        $user = User::factory()->create();

        NotificationPreference::factory()->create([
            'user_id' => $user->id,
            'digest_mode' => true,
            'push_enabled' => true,
            'subscriptions' => [],
        ]);

        // Create notification logs from the previous day (within digest window)
        NotificationLog::factory()->create([
            'user_id' => $user->id,
            'alert_id' => 'police:digest-test-1',
            'delivery_method' => 'in_app',
            'status' => 'sent',
            'sent_at' => CarbonImmutable::parse('2026-02-10T10:00:00Z'),
        ]);

        NotificationLog::factory()->create([
            'user_id' => $user->id,
            'alert_id' => 'fire:digest-test-2',
            'delivery_method' => 'in_app',
            'status' => 'sent',
            'sent_at' => CarbonImmutable::parse('2026-02-10T18:00:00Z'),
        ]);

        // Run digest job
        app(GenerateDailyDigestJob::class)->handle();

        // Assert digest log created
        $this->assertDatabaseHas('notification_logs', [
            'user_id' => $user->id,
            'alert_id' => 'digest:2026-02-10',
            'delivery_method' => 'in_app_digest',
        ]);

        $digestLog = NotificationLog::query()
            ->where('user_id', $user->id)
            ->where('delivery_method', 'in_app_digest')
            ->first();

        expect($digestLog)->not->toBeNull();
        expect($digestLog->metadata)->toBeArray();
        expect($digestLog->metadata['digest_date'])->toBe('2026-02-10');
        expect($digestLog->metadata['total_notifications'])->toBe(2);

        // Fetch inbox and assert digest entry appears
        $response = $this
            ->actingAs($user)
            ->getJson('/notifications/inbox');

        $response->assertOk();

        $digestItem = collect($response->json('data'))
            ->first(fn (array $item): bool => $item['type'] === 'digest');

        expect($digestItem)->not->toBeNull();
        expect($digestItem['alert_id'])->toBe('digest:2026-02-10');
    } finally {
        Carbon::setTestNow();
    }
});
