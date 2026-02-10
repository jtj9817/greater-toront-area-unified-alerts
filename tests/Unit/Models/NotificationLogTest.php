<?php

use App\Models\NotificationLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('it has expected fillable attributes and casts', function () {
    $log = new NotificationLog([
        'sent_at' => '2026-02-10 10:00:00',
        'read_at' => '2026-02-10 10:02:00',
        'dismissed_at' => null,
        'metadata' => ['source' => 'fire'],
    ]);

    expect($log->getFillable())->toBe([
        'user_id',
        'alert_id',
        'delivery_method',
        'status',
        'sent_at',
        'read_at',
        'dismissed_at',
        'metadata',
    ]);

    expect($log->sent_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($log->read_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($log->dismissed_at)->toBeNull();
    expect($log->metadata)->toBeArray();
});

test('it scopes unread logs', function () {
    NotificationLog::factory()->create(['read_at' => null]);
    NotificationLog::factory()->create(['read_at' => null]);
    NotificationLog::factory()->create(['read_at' => now()]);

    expect(NotificationLog::query()->unread()->count())->toBe(2);
});

test('it scopes undismissed logs', function () {
    NotificationLog::factory()->create(['dismissed_at' => null]);
    NotificationLog::factory()->create(['dismissed_at' => null]);
    NotificationLog::factory()->create(['dismissed_at' => now()]);

    expect(NotificationLog::query()->undismissed()->count())->toBe(2);
});
