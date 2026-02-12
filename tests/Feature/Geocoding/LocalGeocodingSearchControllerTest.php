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

    $response->assertOk()->assertJsonStructure([
        'data' => [
            '*' => ['id', 'type', 'name', 'secondary', 'zip', 'lat', 'long'],
        ],
    ]);

    $results = collect($response->json('data'));

    expect($results->pluck('name')->all())->toContain('100 Queen St W');
    expect($results->pluck('name')->all())->toContain('Queen Station');
    expect($results->pluck('name')->all())->not->toContain('CN Tower');

    $addressResult = $results->firstWhere('type', 'address');
    $poiResult = $results->firstWhere('type', 'poi');

    expect($addressResult)->not->toBeNull();
    expect($addressResult['id'])->toStartWith('address:');
    expect($addressResult['zip'])->toBe('M5H 2N2');
    expect($addressResult['lat'])->toBe(43.6531);
    expect($addressResult['long'])->toBe(-79.384);

    expect($poiResult)->not->toBeNull();
    expect($poiResult['id'])->toStartWith('poi:');
    expect($poiResult['zip'])->toBeNull();
    expect($poiResult['lat'])->toBe(43.6524);
    expect($poiResult['long'])->toBe(-79.3799);
});

test('geocoding search returns an empty dataset for short queries', function () {
    $user = User::factory()->create();

    TorontoAddress::factory()->create([
        'street_num' => '100',
        'street_name' => 'Queen St W',
        'lat' => 43.6531,
        'long' => -79.3840,
        'zip' => 'M5H 2N2',
    ]);

    $this->actingAs($user)
        ->getJson('/api/geocoding/search?q=q')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('geocoding search returns an empty dataset when there are no matches', function () {
    $user = User::factory()->create();

    TorontoAddress::factory()->create([
        'street_num' => '100',
        'street_name' => 'Queen St W',
        'lat' => 43.6531,
        'long' => -79.3840,
        'zip' => 'M5H 2N2',
    ]);

    $this->actingAs($user)
        ->getJson('/api/geocoding/search?q=zzzz-no-results-12345')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('geocoding search enforces default result limit', function () {
    $user = User::factory()->create();

    foreach (range(1, 25) as $index) {
        TorontoAddress::factory()->create([
            'street_num' => (string) $index,
            'street_name' => "Market Street {$index}",
            'lat' => 43.6000 + ($index / 10000),
            'long' => -79.3000 - ($index / 10000),
            'zip' => "M5X {$index}",
        ]);

        TorontoPointOfInterest::factory()->create([
            'name' => "Market POI {$index}",
            'category' => 'Retail',
            'lat' => 43.6200 + ($index / 10000),
            'long' => -79.3200 - ($index / 10000),
        ]);
    }

    $response = $this->actingAs($user)->getJson('/api/geocoding/search?q=market');
    $results = collect($response->json('data'));

    $response->assertOk();
    expect($results)->toHaveCount(10);
});

test('geocoding search handles special character queries without broad wildcard matches', function () {
    $user = User::factory()->create();

    TorontoAddress::factory()->create([
        'street_num' => '100',
        'street_name' => 'Queen St W',
        'lat' => 43.6531,
        'long' => -79.3840,
        'zip' => 'M5H 2N2',
    ]);

    TorontoPointOfInterest::factory()->create([
        'name' => 'Queen Station',
        'category' => 'Transit',
        'lat' => 43.6524,
        'long' => -79.3799,
    ]);

    $queries = ['%_', "queen%'", "o'hare", '<script>'];

    foreach ($queries as $query) {
        $this->actingAs($user)
            ->getJson('/api/geocoding/search?q='.urlencode($query))
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }
});
