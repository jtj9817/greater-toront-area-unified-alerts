<?php

use App\Enums\IncidentUpdateType;

test('incident update type exposes ordered values', function () {
    $values = array_map(
        static fn (IncidentUpdateType $case) => $case->value,
        IncidentUpdateType::cases(),
    );

    expect($values)->toBe([
        'milestone',
        'resource_status',
        'alarm_change',
        'phase_change',
        'manual_note',
    ]);
});
