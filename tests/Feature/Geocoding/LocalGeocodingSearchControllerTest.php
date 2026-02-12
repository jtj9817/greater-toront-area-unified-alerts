<?php

use App\Models\TorontoAddress;
use App\Models\TorontoPointOfInterest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('geocoding search endpoint requires authentication', function () {
    $this->getJson('/api/geocoding/search?q=queen')->assertUnauthorized();
});

test('geocoding search validates required query string', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->getJson('/api/geocoding/search')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['q']);
});

test('geocoding search returns matching local addresses and pois', function () {
    $user = User::factory()->create();

    TorontoAddress::factory()->create([
        'street_num' => '100',
        'street_name' => 'Queen St W',
        'lat' => 43.6531,
        'long' => -79.3840,
        'zip' => 'M5H 2N2',
    ]);

    TorontoAddress::factory()->create([
        'street_num' => '25',
        'street_name' => 'King St E',
        'lat' => 43.6501,
        'long' => -79.3720,
        'zip' => 'M5C 1E9',
    ]);

    TorontoPointOfInterest::factory()->create([
        'name' => 'Queen Station',
        'category' => 'Transit',
        'lat' => 43.6524,
        'long' => -79.3799,
    ]);

    TorontoPointOfInterest::factory()->create([
        'name' => 'CN Tower',
        'category' => 'Landmark',
        'lat' => 43.6426,
        'long' => -79.3871,
    ]);

    $response = $this->actingAs($user)->getJson('/api/geocoding/search?q=queen');

    $response->assertOk();
    $results = collect($response->json('data'));

    expect($results->pluck('name')->all())->toContain('100 Queen St W');
    expect($results->pluck('name')->all())->toContain('Queen Station');
    expect($results->pluck('name')->all())->not->toContain('CN Tower');
});
