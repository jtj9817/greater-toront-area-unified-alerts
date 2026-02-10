<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AlertNotificationSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $alertId,
        public readonly string $source,
        public readonly string $severity,
        public readonly string $summary,
        public readonly string $sentAt,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("users.{$this->userId}.notifications")];
    }

    public function broadcastAs(): string
    {
        return 'alert.notification.sent';
    }

    /**
     * @return array<string, string>
     */
    public function broadcastWith(): array
    {
        return [
            'alert_id' => $this->alertId,
            'source' => $this->source,
            'severity' => $this->severity,
            'summary' => $this->summary,
            'sent_at' => $this->sentAt,
        ];
    }
}
