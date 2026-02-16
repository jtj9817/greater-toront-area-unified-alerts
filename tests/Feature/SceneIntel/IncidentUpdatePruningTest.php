<?php

use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it prunes incident updates older than 90 days', function () {
    // Travel back to create old data
    $this->travelTo(now()->subDays(91));

    $incident = FireIncident::factory()->create();
    $oldUpdate = IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
    ]);

    // Travel to now to simulate time passing
    $this->travelBack();

    // Create recent data
    $recentUpdate = IncidentUpdate::factory()->create([
        'event_num' => $incident->event_num,
    ]);

    // Run the pruner specifically for IncidentUpdate
    $this->artisan('model:prune', [
        '--model' => [IncidentUpdate::class],
    ])->assertSuccessful();

    // Assertions
    $this->assertDatabaseMissing('incident_updates', ['id' => $oldUpdate->id]);
    $this->assertDatabaseHas('incident_updates', ['id' => $recentUpdate->id]);
});
