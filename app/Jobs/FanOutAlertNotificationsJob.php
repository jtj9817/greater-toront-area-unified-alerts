<?php

namespace App\Jobs;

use App\Services\Notifications\NotificationAlert;
use App\Services\Notifications\NotificationMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FanOutAlertNotificationsJob implements ShouldQueue
{
    use Queueable;

    public const RECIPIENT_CHUNK_SIZE = 250;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $payload,
    ) {}

    public function handle(NotificationMatcher $matcher): void
    {
        $alert = NotificationAlert::fromPayload($this->payload);

        if ($alert->alertId === '' || $alert->source === '') {
            return;
        }

        $matcher
            ->matchingPreferences($alert)
            ->chunk(self::RECIPIENT_CHUNK_SIZE)
            ->each(function ($preferences): void {
                $userIds = $preferences
                    ->pluck('user_id')
                    ->map(static fn (int|string $userId): int => (int) $userId)
                    ->unique()
                    ->values()
                    ->all();

                if ($userIds === []) {
                    return;
                }

                DispatchAlertNotificationChunkJob::dispatch(
                    userIds: $userIds,
                    payload: $this->payload,
                );
            });
    }
}
