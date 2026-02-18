<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DispatchAlertNotificationChunkJob implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<int, int|string>  $userIds
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public array $userIds,
        public array $payload,
    ) {}

    public function handle(): void
    {
        foreach ($this->normalizedUserIds() as $userId) {
            DeliverAlertNotificationJob::dispatch(
                userId: $userId,
                payload: $this->payload,
            );
        }
    }

    /**
     * @return array<int, int>
     */
    private function normalizedUserIds(): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (int|string $userId): int => (int) $userId, $this->userIds),
            static fn (int $userId): bool => $userId > 0,
        )));
    }
}
