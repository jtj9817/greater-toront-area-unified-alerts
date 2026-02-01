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

test('it uses default timestamps', function () {
    $call = new PoliceCall;

    // Eloquent's `getCasts()` doesn't include created_at/updated_at by default, but they are still treated as dates.
    expect($call->usesTimestamps())->toBeTrue();
    expect($call->getDates())->toContain('created_at', 'updated_at');
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
