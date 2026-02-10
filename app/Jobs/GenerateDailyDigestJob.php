<?php

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

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
                $digestAlertId = "digest:{$digestDate}";
                $userIds = $preferences
                    ->pluck('user_id')
                    ->map(static fn (int|string $userId): int => (int) $userId)
                    ->values()
                    ->all();

                if ($userIds === []) {
                    return;
                }

                $existingDigestUserIds = $this->existingDigestUserIds($userIds, $digestAlertId);
                $notificationCounts = $this->notificationCountsForUsers($userIds, $windowStart, $windowEnd);

                foreach ($preferences as $preference) {
                    if (isset($existingDigestUserIds[$preference->user_id])) {
                        continue;
                    }

                    $notificationCount = (int) $notificationCounts->get($preference->user_id, 0);
                    if ($notificationCount === 0) {
                        continue;
                    }

                    $this->createDigestLog(
                        userId: $preference->user_id,
                        digestAlertId: $digestAlertId,
                        digestDate: $digestDate,
                        windowStartIso: $windowStart->toIso8601String(),
                        windowEndIso: $windowEnd->toIso8601String(),
                        notificationCount: $notificationCount,
                    );
                }
            });
    }

    /**
     * @param  array<int, int>  $userIds
     * @return array<int, bool>
     */
    private function existingDigestUserIds(array $userIds, string $digestAlertId): array
    {
        return NotificationLog::query()
            ->whereIn('user_id', $userIds)
            ->where('delivery_method', 'in_app_digest')
            ->where('alert_id', $digestAlertId)
            ->pluck('user_id')
            ->mapWithKeys(static fn (int|string $userId): array => [(int) $userId => true])
            ->all();
    }

    /**
     * @param  array<int, int>  $userIds
     * @return Collection<int, int>
     */
    private function notificationCountsForUsers(
        array $userIds,
        CarbonImmutable $windowStart,
        CarbonImmutable $windowEnd,
    ): Collection {
        return NotificationLog::query()
            ->whereIn('user_id', $userIds)
            ->where('delivery_method', 'in_app')
            ->where('sent_at', '>=', $windowStart)
            ->where('sent_at', '<', $windowEnd)
            ->selectRaw('user_id, COUNT(*) as aggregate_count')
            ->groupBy('user_id')
            ->pluck('aggregate_count', 'user_id')
            ->map(static fn (int|string $count): int => (int) $count);
    }

    private function createDigestLog(
        int $userId,
        string $digestAlertId,
        string $digestDate,
        string $windowStartIso,
        string $windowEndIso,
        int $notificationCount,
    ): void {
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
