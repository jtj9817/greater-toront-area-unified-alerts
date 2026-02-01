<?php

use App\Models\PoliceCall;

test('it has correct casts', function () {
    $call = new PoliceCall;
    $casts = $call->getCasts();

    expect($casts)->toMatchArray([
        'id' => 'int',
        'occurrence_time' => 'datetime',
        'feed_updated_at' => 'datetime',
        'is_active' => 'boolean',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ]);
});

test('it has correct fillable attributes', function () {
    $call = new PoliceCall;

    expect($call->getFillable())->toBe([
        'object_id',
        'call_type_code',
        'call_type',
        'division',
        'cross_streets',
        'latitude',
        'longitude',
        'occurrence_time',
        'is_active',
        'feed_updated_at',
    ]);
});
