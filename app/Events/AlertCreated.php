<?php

namespace App\Events;

use App\Services\Notifications\NotificationAlert;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AlertCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly NotificationAlert $alert,
    ) {}
}
