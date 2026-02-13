<?php

use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('it has correct fillable attributes', function () {
    $incident = new FireIncident;

    $expectedFillable = [
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
    ];

    expect($incident->getFillable())->toBe($expectedFillable);
});

test('it casts attributes correctly', function () {
    $incident = new FireIncident([
        'dispatch_time' => '2026-01-31 13:15:32',
        'feed_updated_at' => '2026-01-31 13:45:01',
        'alarm_level' => '2',
        'is_active' => 1,
    ]);

    expect($incident->dispatch_time)->toBeInstanceOf(DateTimeInterface::class);
    expect($incident->feed_updated_at)->toBeInstanceOf(DateTimeInterface::class);
    expect($incident->alarm_level)->toBe(2);
    expect($incident->is_active)->toBeTrue();
});

test('it can scope active incidents', function () {
    FireIncident::factory()->create(['is_active' => true]);
    FireIncident::factory()->create(['is_active' => true]);
    FireIncident::factory()->create(['is_active' => false]);

    expect(FireIncident::active()->count())->toBe(2);
});

test('it has many incident updates', function () {
    $incident = FireIncident::factory()->create();
    $otherIncident = FireIncident::factory()->create();

    IncidentUpdate::factory()->count(2)->create(['event_num' => $incident->event_num]);
    IncidentUpdate::factory()->create(['event_num' => $otherIncident->event_num]);

    expect($incident->incidentUpdates)->toHaveCount(2);
    expect($incident->incidentUpdates->pluck('event_num')->unique()->all())
        ->toBe([$incident->event_num]);
});
