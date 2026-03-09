<?php

use App\Models\SavedPlace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('saved place name array payload does not crash', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)
        ->postJson('/api/saved-places', [
            'name' => ['<script>alert("xss")</script> My Place'],
            'lat' => 43.6426,
            'long' => -79.3871,
            'radius' => 750,
            'type' => 'poi',
        ]);

    $response->assertStatus(422); // Validation should fail, it shouldn't be a 500
});
