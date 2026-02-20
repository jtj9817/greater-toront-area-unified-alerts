<?php

use App\Models\SavedPlace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('saved place name is sanitized on create', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/saved-places', [
            'name' => '<script>alert("xss")</script> My Place',
            'lat' => 43.6426,
            'long' => -79.3871,
            'radius' => 750,
            'type' => 'poi',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'alert("xss") My Place');

    $this->assertDatabaseHas('saved_places', [
        'user_id' => $user->id,
        'name' => 'alert("xss") My Place',
    ]);
});

test('saved place name is sanitized on update', function () {
    $user = User::factory()->create();
    $place = SavedPlace::factory()->create([
        'user_id' => $user->id,
        'name' => 'Original Name',
    ]);

    $this->actingAs($user)
        ->patchJson("/api/saved-places/{$place->id}", [
            'name' => '<b>Bold</b> Name',
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Bold Name');

    $this->assertDatabaseHas('saved_places', [
        'id' => $place->id,
        'name' => 'Bold Name',
    ]);
});
