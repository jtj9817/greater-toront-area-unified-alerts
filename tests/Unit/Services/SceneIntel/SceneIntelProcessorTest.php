<?php

use App\Enums\IncidentUpdateType;
use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use App\Services\SceneIntel\SceneIntelProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->processor = app(SceneIntelProcessor::class);
});

test('it generates alarm and resource updates from an incident snapshot diff', function () {
    $incident = FireIncident::factory()->create([
        'event_num' => 'F26020001',
        'alarm_level' => 2,
        'units_dispatched' => 'P100, R200',
        'is_active' => true,
    ]);

    $this->processor->processIncidentUpdate($incident, [
        'alarm_level' => 1,
        'units_dispatched' => 'P100, R300',
        'is_active' => true,
    ]);

    $updates = IncidentUpdate::query()
        ->forIncident($incident->event_num)
        ->orderBy('id')
        ->get();

    expect($updates)->toHaveCount(3);

    expect($updates[0]->update_type)->toBe(IncidentUpdateType::ALARM_CHANGE);
    expect($updates[0]->content)->toBe('Alarm level increased from 1 to 2');
    expect($updates[0]->metadata)->toEqual([
        'previous_level' => 1,
        'new_level' => 2,
        'direction' => 'up',
    ]);
    expect($updates[0]->source)->toBe('synthetic');

    expect($updates[1]->update_type)->toBe(IncidentUpdateType::RESOURCE_STATUS);
    expect($updates[1]->content)->toBe('Unit R200 dispatched');
    expect($updates[1]->metadata)->toEqualCanonicalizing([
        'unit_code' => 'R200',
        'status' => 'dispatched',
    ]);

    expect($updates[2]->update_type)->toBe(IncidentUpdateType::RESOURCE_STATUS);
    expect($updates[2]->content)->toBe('Unit R300 cleared');
    expect($updates[2]->metadata)->toEqualCanonicalizing([
        'unit_code' => 'R300',
        'status' => 'cleared',
    ]);
});

test('it generates a closure update once when an incident transitions to inactive', function () {
    $incident = FireIncident::factory()->create([
        'event_num' => 'F26020002',
        'is_active' => false,
    ]);

    $this->processor->processIncidentUpdate($incident, [
        'alarm_level' => $incident->alarm_level,
        'units_dispatched' => $incident->units_dispatched,
        'is_active' => true,
    ]);

    $this->processor->processIncidentUpdate($incident, [
        'alarm_level' => $incident->alarm_level,
        'units_dispatched' => $incident->units_dispatched,
        'is_active' => true,
    ]);

    $updates = IncidentUpdate::query()
        ->forIncident($incident->event_num)
        ->get();

    expect($updates)->toHaveCount(1);
    expect($updates[0]->update_type)->toBe(IncidentUpdateType::PHASE_CHANGE);
    expect($updates[0]->content)->toBe('Incident marked as resolved');
    expect($updates[0]->metadata)->toEqual([
        'previous_phase' => 'active',
        'new_phase' => 'resolved',
    ]);
});

test('it does not generate updates without previous incident data', function () {
    $incident = FireIncident::factory()->create();

    $this->processor->processIncidentUpdate($incident, null);

    expect(IncidentUpdate::query()->forIncident($incident->event_num)->count())->toBe(0);
});

test('it records phase changes across reactivation and deactivation cycles', function () {
    $incident = FireIncident::factory()->create([
        'event_num' => 'F26020003',
        'is_active' => true,
    ]);

    $this->processor->processIncidentUpdate($incident, [
        'alarm_level' => $incident->alarm_level,
        'units_dispatched' => $incident->units_dispatched,
        'is_active' => false,
    ]);

    $incident->is_active = false;

    $this->processor->processIncidentUpdate($incident, [
        'alarm_level' => $incident->alarm_level,
        'units_dispatched' => $incident->units_dispatched,
        'is_active' => true,
    ]);

    $this->processor->processIncidentUpdate($incident, [
        'alarm_level' => $incident->alarm_level,
        'units_dispatched' => $incident->units_dispatched,
        'is_active' => true,
    ]);

    $phaseUpdates = IncidentUpdate::query()
        ->forIncident($incident->event_num)
        ->where('update_type', IncidentUpdateType::PHASE_CHANGE)
        ->orderBy('id')
        ->get();

    expect($phaseUpdates)->toHaveCount(2);
    expect($phaseUpdates[0]->content)->toBe('Incident marked as active');
    expect($phaseUpdates[0]->metadata)->toEqual([
        'previous_phase' => 'resolved',
        'new_phase' => 'active',
    ]);
    expect($phaseUpdates[1]->content)->toBe('Incident marked as resolved');
    expect($phaseUpdates[1]->metadata)->toEqual([
        'previous_phase' => 'active',
        'new_phase' => 'resolved',
    ]);
});
