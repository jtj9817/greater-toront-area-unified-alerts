<?php

use App\Enums\IncidentUpdateType;
use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

test('it has expected fillable attributes', function () {
    $update = new IncidentUpdate;

    expect($update->getFillable())->toBe([
        'event_num',
        'update_type',
        'content',
        'metadata',
        'source',
        'created_by',
    ]);
});

test('it casts attributes correctly', function () {
    $incident = FireIncident::factory()->create();
    $user = User::factory()->create();

    $update = IncidentUpdate::create([
        'event_num' => $incident->event_num,
        'update_type' => IncidentUpdateType::MILESTONE,
        'content' => 'Command established',
        'metadata' => ['unitCode' => 'P144'],
        'source' => 'manual',
        'created_by' => (string) $user->id,
    ]);

    expect($update->update_type)->toBe(IncidentUpdateType::MILESTONE);
    expect($update->metadata)->toBe(['unitCode' => 'P144']);
    expect($update->created_by)->toBe($user->id);
});

test('it belongs to a fire incident and creator', function () {
    $incident = FireIncident::factory()->create();
    $user = User::factory()->create();

    $update = IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
        'created_by' => $user->id,
    ]);

    expect($update->fireIncident)->not->toBeNull();
    expect($update->fireIncident->is($incident))->toBeTrue();
    expect($update->creator)->not->toBeNull();
    expect($update->creator->is($user))->toBeTrue();
});

test('incident update factory make does not persist a fire incident', function () {
    expect(FireIncident::query()->count())->toBe(0);

    IncidentUpdate::factory()->make();

    expect(FireIncident::query()->count())->toBe(0);
});

test('incident update factory does not create extra fire incident when event_num is overridden', function () {
    $incident = FireIncident::factory()->create();
    $startingCount = FireIncident::query()->count();

    IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
    ]);

    expect(FireIncident::query()->count())->toBe($startingCount);
});
