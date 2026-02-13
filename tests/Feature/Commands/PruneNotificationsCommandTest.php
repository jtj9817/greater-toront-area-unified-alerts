<?php

use App\Models\NotificationLog;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it prunes notification logs older than 30 days', function () {
    CarbonImmutable::setTestNow('2026-02-13 12:00:00');

    try {
        $oldLog = NotificationLog::factory()->create([
            'sent_at' => CarbonImmutable::now()->subDays(31),
        ]);

        $boundaryLog = NotificationLog::factory()->create([
            'sent_at' => CarbonImmutable::now()->subDays(30),
        ]);

        $recentLog = NotificationLog::factory()->create([
            'sent_at' => CarbonImmutable::now()->subDays(7),
        ]);

        $this->artisan('notifications:prune')
            ->expectsOutput('Pruned 1 notification log(s) older than 30 days.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('notification_logs', ['id' => $oldLog->id]);
        $this->assertDatabaseHas('notification_logs', ['id' => $boundaryLog->id]);
        $this->assertDatabaseHas('notification_logs', ['id' => $recentLog->id]);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('it reports zero when there are no stale logs to prune', function () {
    CarbonImmutable::setTestNow('2026-02-13 12:00:00');

    try {
        NotificationLog::factory()->create([
            'sent_at' => CarbonImmutable::now()->subDays(30),
        ]);

        NotificationLog::factory()->create([
            'sent_at' => CarbonImmutable::now()->subDays(5),
        ]);

        $this->artisan('notifications:prune')
            ->expectsOutput('Pruned 0 notification log(s) older than 30 days.')
            ->assertExitCode(0);
    } finally {
        CarbonImmutable::setTestNow();
    }
});
