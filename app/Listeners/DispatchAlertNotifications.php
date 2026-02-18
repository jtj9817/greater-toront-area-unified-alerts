<?php

namespace App\Listeners;

use App\Events\AlertCreated;
use App\Jobs\FanOutAlertNotificationsJob;

class DispatchAlertNotifications
{
    public function handle(AlertCreated $event): void
    {
        FanOutAlertNotificationsJob::dispatch(
            payload: $event->alert->toPayload(),
        );
    }
}
