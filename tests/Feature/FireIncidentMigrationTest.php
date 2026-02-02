<?php

use Illuminate\Support\Facades\Schema;

test('fire_incidents table exists and has expected columns', function () {
    $this->artisan('migrate');

    expect(Schema::hasTable('fire_incidents'))->toBeTrue();

    $columns = [
        'id',
        'event_num',
        'event_type',
        'prime_street',
        'cross_streets',
        'dispatch_time',
        'alarm_level',
        'beat',
        'units_dispatched',
        'is_active',
        'feed_updated_at',
        'created_at',
        'updated_at',
    ];

    foreach ($columns as $column) {
        expect(Schema::hasColumn('fire_incidents', $column))
            ->toBeTrue("Column '$column' is missing in 'fire_incidents' table");
    }
});
