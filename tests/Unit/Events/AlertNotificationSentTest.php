<?php

use App\Events\AlertNotificationSent;
use Illuminate\Broadcasting\PrivateChannel;

test('it exposes the expected broadcast payload and private channel', function () {
    $event = new AlertNotificationSent(
        userId: 42,
        alertId: 'police:123',
        source: 'police',
        severity: 'major',
        summary: 'Assault in progress',
        sentAt: '2026-02-10T16:30:00+00:00',
    );

    expect($event->broadcastAs())->toBe('alert.notification.sent');

    $channels = $event->broadcastOn();

    expect($channels)->toHaveCount(1);
    expect($channels[0])->toBeInstanceOf(PrivateChannel::class);
    expect($channels[0]->name)->toBe('private-users.42.notifications');

    expect($event->broadcastWith())->toBe([
        'alert_id' => 'police:123',
        'source' => 'police',
        'severity' => 'major',
        'summary' => 'Assault in progress',
        'sent_at' => '2026-02-10T16:30:00+00:00',
    ]);
});
