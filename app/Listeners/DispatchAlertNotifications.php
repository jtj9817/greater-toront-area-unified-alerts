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
        $matchingPreferences = $this->matcher->matchingPreferences($event->alert);
        $payload = $event->alert->toPayload();

        foreach ($matchingPreferences as $preference) {
            DeliverAlertNotificationJob::dispatch(
                userId: $preference->user_id,
                payload: $payload,
            );
        }
    }
}
