<?php

use App\Jobs\DeliverAlertNotificationJob;
use App\Jobs\DispatchAlertNotificationChunkJob;
use App\Jobs\FanOutAlertNotificationsJob;
use App\Models\NotificationPreference;
use App\Services\Notifications\NotificationAlert;
use App\Services\Notifications\NotificationMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('fan-out job splits high-volume recipients into chunk jobs', function () {
    NotificationPreference::factory()->count(520)->create([
        'alert_type' => 'emergency',
        'severity_threshold' => 'minor',
        'subscriptions' => [],
        'push_enabled' => true,
    ]);

    Queue::fake();

    $job = new FanOutAlertNotificationsJob(
        payload: (new NotificationAlert(
            alertId: 'police:bulk-001',
            source: 'police',
            severity: 'major',
            summary: 'High-volume emergency alert',
            occurredAt: CarbonImmutable::parse('2026-02-13T10:00:00Z'),
            lat: 43.7000,
            lng: -79.4000,
        ))->toPayload(),
    );

    $job->handle(app(NotificationMatcher::class));

    $chunkJobs = Queue::pushed(DispatchAlertNotificationChunkJob::class);

    expect($chunkJobs)->toHaveCount(3);
    expect($chunkJobs->every(function (DispatchAlertNotificationChunkJob $chunk): bool {
        $recipientCount = count($chunk->userIds);

        return $recipientCount > 0
            && $recipientCount <= FanOutAlertNotificationsJob::RECIPIENT_CHUNK_SIZE
            && $chunk->payload['alert_id'] === 'police:bulk-001';
    }))->toBeTrue();
    expect($chunkJobs->sum(fn (DispatchAlertNotificationChunkJob $chunk): int => count($chunk->userIds)))->toBe(520);
});

test('chunk job queues one delivery job per unique valid user id', function () {
    Queue::fake();

    $chunkJob = new DispatchAlertNotificationChunkJob(
        userIds: [101, '202', 202, 0, -2, 303],
        payload: [
            'alert_id' => 'police:chunk-001',
            'source' => 'police',
            'severity' => 'major',
            'summary' => 'Chunk fan-out test',
            'occurred_at' => '2026-02-13T10:00:00+00:00',
            'lat' => 43.7000,
            'lng' => -79.4000,
            'routes' => [],
            'metadata' => [],
        ],
    );

    $chunkJob->handle();

    $deliveries = Queue::pushed(DeliverAlertNotificationJob::class);

    expect($deliveries)->toHaveCount(3);
    expect($deliveries->map(fn (DeliverAlertNotificationJob $job): int => $job->userId)->sort()->values()->all())
        ->toBe([101, 202, 303]);
});
