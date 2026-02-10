<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateDailyDigestJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $windowEnd = now()->startOfDay();
        $windowStart = $windowEnd->subDay();
        $digestDate = $windowStart->toDateString();

        NotificationPreference::query()
            ->where('digest_mode', true)
            ->select(['id', 'user_id'])
            ->chunkById(200, function ($preferences) use ($windowStart, $windowEnd, $digestDate): void {
                foreach ($preferences as $preference) {
                    $this->createDigestLog(
                        userId: $preference->user_id,
                        digestDate: $digestDate,
                        windowStartIso: $windowStart->toIso8601String(),
                        windowEndIso: $windowEnd->toIso8601String(),
                        windowStart: $windowStart,
                        windowEnd: $windowEnd,
                    );
                }
            });
    }

    private function createDigestLog(
        int $userId,
        string $digestDate,
        string $windowStartIso,
        string $windowEndIso,
        \Carbon\CarbonImmutable $windowStart,
        \Carbon\CarbonImmutable $windowEnd,
    ): void {
        $digestAlertId = "digest:{$digestDate}";

        $existingDigest = NotificationLog::query()
            ->where('user_id', $userId)
            ->where('delivery_method', 'in_app_digest')
            ->where('alert_id', $digestAlertId)
            ->exists();

        if ($existingDigest) {
            return;
        }

        $notificationCount = NotificationLog::query()
            ->where('user_id', $userId)
            ->where('delivery_method', 'in_app')
            ->where('sent_at', '>=', $windowStart)
            ->where('sent_at', '<', $windowEnd)
            ->count();

        if ($notificationCount === 0) {
            return;
        }

        NotificationLog::query()->create([
            'user_id' => $userId,
            'alert_id' => $digestAlertId,
            'delivery_method' => 'in_app_digest',
            'status' => 'sent',
            'sent_at' => now(),
            'metadata' => [
                'type' => 'daily_digest',
                'digest_date' => $digestDate,
                'window_start' => $windowStartIso,
                'window_end' => $windowEndIso,
                'total_notifications' => $notificationCount,
            ],
        ]);
    }
}
