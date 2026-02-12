<?php

use App\Jobs\GenerateDailyDigestJob;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

test('daily digest aggregates only prior day notifications and prevents duplicates', function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-02-11T08:00:00Z'));

    $digestPreference = NotificationPreference::factory()->create([
        'digest_mode' => true,
        'push_enabled' => true,
        'subscriptions' => [],
    ]);

    $nonDigestPreference = NotificationPreference::factory()->create([
        'digest_mode' => false,
        'push_enabled' => true,
        'subscriptions' => [],
    ]);

    NotificationLog::factory()->create([
        'user_id' => $digestPreference->user_id,
        'alert_id' => 'police:prev-day-midday',
        'delivery_method' => 'in_app',
        'status' => 'sent',
        'sent_at' => CarbonImmutable::parse('2026-02-10T12:00:00Z'),
    ]);

    NotificationLog::factory()->create([
        'user_id' => $digestPreference->user_id,
        'alert_id' => 'police:window-start',
        'delivery_method' => 'in_app',
        'status' => 'sent',
        'sent_at' => CarbonImmutable::parse('2026-02-10T00:00:00Z'),
    ]);

    NotificationLog::factory()->create([
        'user_id' => $digestPreference->user_id,
        'alert_id' => 'police:window-end-excluded',
        'delivery_method' => 'in_app',
        'status' => 'sent',
        'sent_at' => CarbonImmutable::parse('2026-02-11T00:00:00Z'),
    ]);

    NotificationLog::factory()->create([
        'user_id' => $nonDigestPreference->user_id,
        'alert_id' => 'police:other-user',
        'delivery_method' => 'in_app',
        'status' => 'sent',
        'sent_at' => CarbonImmutable::parse('2026-02-10T12:00:00Z'),
    ]);

    app(GenerateDailyDigestJob::class)->handle();
    app(GenerateDailyDigestJob::class)->handle();

    $digestLogs = NotificationLog::query()
        ->where('user_id', $digestPreference->user_id)
        ->where('delivery_method', 'in_app_digest')
        ->where('alert_id', 'digest:2026-02-10')
        ->get();

    expect($digestLogs)->toHaveCount(1);

    $digest = $digestLogs->first();

    expect($digest)->not->toBeNull();
    expect($digest->metadata)->toBeArray();
    expect($digest->metadata['digest_date'])->toBe('2026-02-10');
    expect($digest->metadata['total_notifications'])->toBe(2);
    expect($digest->metadata['window_start'])->toBe('2026-02-10T00:00:00+00:00');
    expect($digest->metadata['window_end'])->toBe('2026-02-11T00:00:00+00:00');

    expect(NotificationLog::query()
        ->where('user_id', $nonDigestPreference->user_id)
        ->where('delivery_method', 'in_app_digest')
        ->count())->toBe(0);

    Carbon::setTestNow();
});
