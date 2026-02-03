<?php

use App\Http\Resources\FireIncidentResource;
use App\Models\FireIncident;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

uses(Tests\TestCase::class);

test('fire incident resource maps model to transport shape', function () {
    Carbon::setTestNow(Carbon::parse('2026-02-02 12:00:00'));

    $dispatchTime = Carbon::now()->subMinutes(5);
    $feedUpdatedAt = Carbon::now()->subMinutes(4);

    $incident = new FireIncident([
        'event_num' => 'E123',
        'event_type' => 'STRUCTURE FIRE',
        'prime_street' => 'Yonge St',
        'cross_streets' => 'Dundas St',
        'dispatch_time' => $dispatchTime,
        'alarm_level' => 2,
        'beat' => '12A',
        'units_dispatched' => 'P1, P2',
        'is_active' => true,
        'feed_updated_at' => $feedUpdatedAt,
    ]);
    $incident->id = 123;

    $data = (new FireIncidentResource($incident))->toArray(Request::create('/', 'GET'));

    expect($data)->toBe([
        'id' => 123,
        'event_num' => 'E123',
        'event_type' => 'STRUCTURE FIRE',
        'prime_street' => 'Yonge St',
        'cross_streets' => 'Dundas St',
        'dispatch_time' => $dispatchTime->toIso8601String(),
        'alarm_level' => 2,
        'beat' => '12A',
        'units_dispatched' => 'P1, P2',
        'is_active' => true,
        'feed_updated_at' => $feedUpdatedAt->toIso8601String(),
    ]);
});
