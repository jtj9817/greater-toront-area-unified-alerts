<?php

use App\Models\FireIncident;
use App\Models\IncidentUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('it prunes incident updates older than 90 days by default', function () {
    CarbonImmutable::setTestNow('2026-02-13 12:00:00');

    try {
        $incident = FireIncident::factory()->create();

        $oldUpdate = IncidentUpdate::factory()->create([
            'event_num' => $incident->event_num,
            'created_at' => CarbonImmutable::now()->subDays(91),
        ]);

        $boundaryUpdate = IncidentUpdate::factory()->create([
            'event_num' => $incident->event_num,
            'created_at' => CarbonImmutable::now()->subDays(90),
        ]);

        $recentUpdate = IncidentUpdate::factory()->create([
            'event_num' => $incident->event_num,
            'created_at' => CarbonImmutable::now()->subDays(7),
        ]);

        $this->artisan('scene-intel:prune')
            ->expectsOutput('Pruned 1 incident update(s) older than 90 days.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('incident_updates', ['id' => $oldUpdate->id]);
        $this->assertDatabaseHas('incident_updates', ['id' => $boundaryUpdate->id]);
        $this->assertDatabaseHas('incident_updates', ['id' => $recentUpdate->id]);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('it prunes incident updates based on the days option', function () {
    CarbonImmutable::setTestNow('2026-02-13 12:00:00');

    try {
        $incident = FireIncident::factory()->create();

        $oldUpdate = IncidentUpdate::factory()->create([
            'event_num' => $incident->event_num,
            'created_at' => CarbonImmutable::now()->subDays(31),
        ]);

        $boundaryUpdate = IncidentUpdate::factory()->create([
            'event_num' => $incident->event_num,
            'created_at' => CarbonImmutable::now()->subDays(30),
        ]);

        $recentUpdate = IncidentUpdate::factory()->create([
            'event_num' => $incident->event_num,
            'created_at' => CarbonImmutable::now()->subDays(7),
        ]);

        $this->artisan('scene-intel:prune', ['--days' => 30])
            ->expectsOutput('Pruned 1 incident update(s) older than 30 days.')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('incident_updates', ['id' => $oldUpdate->id]);
        $this->assertDatabaseHas('incident_updates', ['id' => $boundaryUpdate->id]);
        $this->assertDatabaseHas('incident_updates', ['id' => $recentUpdate->id]);
    } finally {
        CarbonImmutable::setTestNow();
    }
});

test('it reports zero when there are no stale updates to prune', function () {
    CarbonImmutable::setTestNow('2026-02-13 12:00:00');

    try {
        $incident = FireIncident::factory()->create();

        IncidentUpdate::factory()->create([
            'event_num' => $incident->event_num,
            'created_at' => CarbonImmutable::now()->subDays(89),
        ]);

        IncidentUpdate::factory()->create([
            'event_num' => $incident->event_num,
            'created_at' => CarbonImmutable::now()->subDays(5),
        ]);

        $this->artisan('scene-intel:prune')
            ->expectsOutput('Pruned 0 incident update(s) older than 90 days.')
            ->assertExitCode(0);
    } finally {
        CarbonImmutable::setTestNow();
    }
});
