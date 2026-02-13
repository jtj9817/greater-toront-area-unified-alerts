<?php

use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('incident updates table exists with expected columns and indexes', function () {
    expect(Schema::hasTable('incident_updates'))->toBeTrue();

    expect(Schema::hasColumns('incident_updates', [
        'id',
        'event_num',
        'update_type',
        'content',
        'metadata',
        'source',
        'created_by',
        'created_at',
        'updated_at',
    ]))->toBeTrue();

    expect(Schema::hasIndex('incident_updates', ['event_num', 'created_at']))->toBeTrue();
    expect(Schema::hasIndex('incident_updates', ['update_type']))->toBeTrue();
    expect(Schema::hasIndex('incident_updates', ['created_at']))->toBeTrue();
});

test('incident updates are deleted when parent fire incident is deleted', function () {
    $incident = FireIncident::factory()->create();

    IncidentUpdate::factory()->count(2)->create([
        'event_num' => $incident->event_num,
    ]);

    expect(IncidentUpdate::count())->toBe(2);

    $incident->delete();

    expect(IncidentUpdate::count())->toBe(0);
});
