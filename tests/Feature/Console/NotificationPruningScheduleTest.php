<?php

use Illuminate\Console\Scheduling\Schedule;

test('notifications prune command is scheduled daily', function () {
    $schedule = app(Schedule::class);

    $pruneEvent = collect($schedule->events())->first(function ($event) {
        return is_string($event->command) && str_contains($event->command, 'notifications:prune');
    });

    expect($pruneEvent)->not->toBeNull();
    expect($pruneEvent->expression)->toBe('0 0 * * *');
});
