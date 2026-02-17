<?php

use Illuminate\Console\Scheduling\Schedule;

test('failed job pruning is scheduled daily', function () {
    $schedule = app(Schedule::class);

    $pruneEvent = collect($schedule->events())->first(function ($event) {
        return is_string($event->command)
            && str_contains($event->command, 'queue:prune-failed')
            && str_contains($event->command, '--hours=168');
    });

    expect($pruneEvent)->not->toBeNull();
    expect($pruneEvent->expression)->toBe('0 0 * * *');
});

