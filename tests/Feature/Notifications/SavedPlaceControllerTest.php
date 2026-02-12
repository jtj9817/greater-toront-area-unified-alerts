<?php

use App\Models\SavedPlace;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('saved places endpoints require authentication', function () {
    $this->getJson('/api/saved-places')->assertUnauthorized();
    $this->postJson('/api/saved-places', [])->assertUnauthorized();
    $this->patchJson('/api/saved-places/1', [])->assertUnauthorized();
    $this->deleteJson('/api/saved-places/1')->assertUnauthorized();
});

test('authenticated user can list, create, update, and delete saved places', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/saved-places', [
            'name' => 'CN Tower',
            'lat' => 43.6426,
            'long' => -79.3871,
            'radius' => 750,
            'type' => 'poi',
        ])
        ->assertCreated()
        ->assertJsonPath('data.name', 'CN Tower')
        ->assertJsonPath('data.radius', 750)
        ->assertJsonPath('data.type', 'poi');

    $savedPlace = SavedPlace::query()->where('user_id', $user->id)->firstOrFail();

    $this->actingAs($user)
        ->getJson('/api/saved-places')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $savedPlace->id);

    $this->actingAs($user)
        ->patchJson("/api/saved-places/{$savedPlace->id}", [
            'name' => 'Updated CN Tower',
            'radius' => 1000,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated CN Tower')
        ->assertJsonPath('data.radius', 1000);

    $this->actingAs($user)
        ->deleteJson("/api/saved-places/{$savedPlace->id}")
        ->assertOk()
        ->assertJsonPath('meta.deleted', true);

    $this->assertDatabaseMissing('saved_places', ['id' => $savedPlace->id]);
});

test('saved places validation enforces gta bounds', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/saved-places', [
            'name' => 'Outside GTA',
            'lat' => 45.0000,
            'long' => -79.3871,
            'radius' => 500,
            'type' => 'address',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lat']);

    $this->actingAs($user)
        ->postJson('/api/saved-places', [
            'name' => 'Outside GTA West',
            'lat' => 43.7000,
            'long' => -81.0000,
            'radius' => 500,
            'type' => 'address',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['long']);
});

test('saved places validation enforces radius boundaries', function () {
    $user = User::factory()->create();

    $basePayload = [
        'name' => 'Radius Validation',
        'lat' => 43.7000,
        'long' => -79.3871,
        'type' => 'address',
    ];

    $this->actingAs($user)
        ->postJson('/api/saved-places', [
            ...$basePayload,
            'radius' => 0,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['radius']);

    $this->actingAs($user)
        ->postJson('/api/saved-places', [
            ...$basePayload,
            'radius' => -50,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['radius']);

    $this->actingAs($user)
        ->postJson('/api/saved-places', [
            ...$basePayload,
            'radius' => 100001,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['radius']);
});

test('saved places validation enforces maximum name length', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/saved-places', [
            'name' => str_repeat('A', 121),
            'lat' => 43.6426,
            'long' => -79.3871,
            'radius' => 750,
            'type' => 'poi',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name']);
});

test('saved places allow duplicate names for the same user', function () {
    $user = User::factory()->create();

    $payload = [
        'name' => 'Home',
        'lat' => 43.6426,
        'long' => -79.3871,
        'radius' => 750,
        'type' => 'poi',
    ];

    $this->actingAs($user)
        ->postJson('/api/saved-places', $payload)
        ->assertCreated();

    $this->actingAs($user)
        ->postJson('/api/saved-places', [
            ...$payload,
            'lat' => 43.6500,
            'long' => -79.3800,
        ])
        ->assertCreated();

    expect(SavedPlace::query()->where('user_id', $user->id)->where('name', 'Home')->count())->toBe(2);
});

test('saved places update and delete are scoped to the owner', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $savedPlace = SavedPlace::factory()->create([
        'user_id' => $owner->id,
    ]);

    $this->actingAs($otherUser)
        ->patchJson("/api/saved-places/{$savedPlace->id}", [
            'name' => 'Unauthorized update',
        ])
        ->assertNotFound();

    $this->actingAs($otherUser)
        ->deleteJson("/api/saved-places/{$savedPlace->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('saved_places', ['id' => $savedPlace->id]);
});
