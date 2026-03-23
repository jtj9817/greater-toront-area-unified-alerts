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

test('geocoding search endpoint is rate limited', function () {
    $user = \App\Models\User::factory()->create();

    // Make 60 requests which should all succeed
    for ($i = 0; $i < 60; $i++) {
        $this->actingAs($user)
            ->getJson('/api/geocoding/search?q=Toronto')
            ->assertOk();
    }

    // The 61st request should be rate limited (429 Too Many Requests)
    $this->actingAs($user)
        ->getJson('/api/geocoding/search?q=Toronto')
        ->assertStatus(429);
});

test('saved places store endpoint is rate limited', function () {
    $user = \App\Models\User::factory()->create();

    // Make 60 requests which should all succeed (though some might fail validation or logic, the 429 is what we test)
    for ($i = 0; $i < 60; $i++) {
        $response = $this->actingAs($user)->postJson('/api/saved-places', [
            'name' => 'Place ' . $i,
            'lat' => 43.6532,
            'long' => -79.3832,
            'radius' => 500,
            'type' => 'address',
        ]);

        // Either 201 Created or 403 Forbidden (if over limit, but not 429)
        expect($response->status())->not->toBe(429);
    }

    // The 61st request should be rate limited (429 Too Many Requests)
    $this->actingAs($user)
        ->postJson('/api/saved-places', [
            'name' => 'Place 61',
            'lat' => 43.6532,
            'long' => -79.3832,
            'radius' => 500,
            'type' => 'address',
        ])
        ->assertStatus(429);
});

test('saved alerts store endpoint is rate limited', function () {
    $user = \App\Models\User::factory()->create();

    // Make 60 requests
    for ($i = 0; $i < 60; $i++) {
        $response = $this->actingAs($user)->postJson('/api/saved-alerts', [
            'alert_id' => "fire:alert-{$i}",
        ]);

        expect($response->status())->not->toBe(429);
    }

    // The 61st request should be rate limited (429 Too Many Requests)
    $this->actingAs($user)
        ->postJson('/api/saved-alerts', [
            'alert_id' => "fire:alert-61",
        ])
        ->assertStatus(429);
});
