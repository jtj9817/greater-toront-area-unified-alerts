<?php

use App\Http\Controllers\Notifications\SavedPlaceController;
use App\Models\SavedPlace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('user cannot create more than maximum allowed saved places', function () {
    $user = User::factory()->create();
    $limit = SavedPlaceController::MAX_SAVED_PLACES;

    // Create max allowed places
    SavedPlace::factory()->count($limit)->create([
        'user_id' => $user->id,
    ]);

    // Attempt to create one more
    $this->actingAs($user)
        ->postJson('/api/saved-places', [
            'name' => 'Overflow Place',
            'lat' => 43.6426,
            'long' => -79.3871,
            'radius' => 750,
            'type' => 'poi',
        ])
        ->assertForbidden()
        ->assertJson([
            'message' => "You have reached the maximum limit of {$limit} saved places.",
        ]);
});

test('user can create saved places up to the limit', function () {
    $user = User::factory()->create();
    $limit = SavedPlaceController::MAX_SAVED_PLACES;

    // Create limit - 1 places
    SavedPlace::factory()->count($limit - 1)->create([
        'user_id' => $user->id,
    ]);

    // Attempt to create the last allowed place
    $this->actingAs($user)
        ->postJson('/api/saved-places', [
            'name' => 'Last Allowed Place',
            'lat' => 43.6426,
            'long' => -79.3871,
            'radius' => 750,
            'type' => 'poi',
        ])
        ->assertCreated();
});
