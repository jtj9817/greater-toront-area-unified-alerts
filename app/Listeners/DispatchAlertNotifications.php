<?php

namespace App\Listeners;

use App\Events\AlertCreated;
use App\Jobs\DeliverAlertNotificationJob;
use App\Services\Notifications\NotificationMatcher;

class DispatchAlertNotifications
{
    public function __construct(
        private readonly NotificationMatcher $matcher,
    ) {}

    public function handle(AlertCreated $event): void
    {
        $payload = $event->alert->toPayload();

        $this->matcher
            ->matchingPreferences($event->alert)
            ->chunk(250)
            ->each(function ($preferences) use ($payload): void {
                foreach ($preferences as $preference) {
                    DeliverAlertNotificationJob::dispatch(
                        userId: $preference->user_id,
                        payload: $payload,
                    );
                }
            });
    }
}
