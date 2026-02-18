<?php

use App\Models\FireIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('public timeline endpoint is rate limited', function () {
    $incident = FireIncident::factory()->create();

    // Make 60 requests which should all succeed
    for ($i = 0; $i < 60; $i++) {
        $this->getJson("/api/incidents/{$incident->event_num}/intel")
            ->assertOk();
    }

    // The 61st request should be rate limited (429 Too Many Requests)
    $this->getJson("/api/incidents/{$incident->event_num}/intel")
        ->assertStatus(429);
});
