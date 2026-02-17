<?php

namespace App\Jobs;

use App\Events\AlertNotificationSent;
use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Services\Notifications\NotificationAlert;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class DeliverAlertNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $backoff = 10;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $userId,
        public array $payload,
    ) {}

    public function handle(): void
    {
        $preference = NotificationPreference::query()
            ->where('user_id', $this->userId)
            ->first();

        if ($preference === null || ! $preference->push_enabled) {
            return;
        }

        $alert = NotificationAlert::fromPayload($this->payload);
        if ($alert->alertId === '' || $alert->source === '') {
            return;
        }

        $log = NotificationLog::query()->firstOrCreate(
            [
                'user_id' => $this->userId,
                'alert_id' => $alert->alertId,
                'delivery_method' => 'in_app',
            ],
            [
                'status' => 'sent',
                'sent_at' => now(),
                'metadata' => [
                    'source' => $alert->source,
                    'severity' => $alert->severity,
                    'summary' => $alert->summary,
                    'occurred_at' => $alert->occurredAt->toIso8601String(),
                    'routes' => $alert->routes,
                ],
            ],
        );

        if (! $log->wasRecentlyCreated && in_array($log->status, ['delivered', 'read', 'dismissed'], true)) {
            return;
        }

        $claimed = NotificationLog::query()
            ->whereKey($log->id)
            ->where('status', 'sent')
            ->update(['status' => 'processing']);

        if ($claimed === 0) {
            return;
        }

        $log->refresh();

        try {
            event(new AlertNotificationSent(
                userId: $this->userId,
                alertId: $alert->alertId,
                source: $alert->source,
                severity: $alert->severity,
                summary: $alert->summary,
                sentAt: $log->sent_at?->toIso8601String() ?? now()->toIso8601String(),
            ));

            $log->status = 'delivered';
            $log->save();
        } catch (\Throwable $exception) {
            NotificationLog::query()
                ->whereKey($log->id)
                ->where('status', 'processing')
                ->update(['status' => 'sent']);

            throw $exception;
        }
    }
}
