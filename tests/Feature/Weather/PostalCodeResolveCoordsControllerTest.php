<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns 422 when lat is missing', function () {
    $this->postJson('/api/postal-codes/resolve-coords', ['lng' => -79.3961])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lat']);
});

test('returns 422 when lng is missing', function () {
    $this->postJson('/api/postal-codes/resolve-coords', ['lat' => 43.6406])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lng']);
});

test('returns 422 when lat is non-numeric', function () {
    $this->postJson('/api/postal-codes/resolve-coords', ['lat' => 'abc', 'lng' => -79.3961])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['lat']);
});

test('returns 422 when coordinates are outside GTA bounding box', function () {
    // London, UK — lat out of range
    $this->postJson('/api/postal-codes/resolve-coords', ['lat' => 51.5, 'lng' => -0.1])
        ->assertUnprocessable();

    // New York — lat out of range
    $this->postJson('/api/postal-codes/resolve-coords', ['lat' => 40.7, 'lng' => -74.0])
        ->assertUnprocessable();

    // East of GTA — lng out of range
    $this->postJson('/api/postal-codes/resolve-coords', ['lat' => 43.7, 'lng' => -78.0])
        ->assertUnprocessable();

    // West of GTA — lng out of range
    $this->postJson('/api/postal-codes/resolve-coords', ['lat' => 43.7, 'lng' => -81.0])
        ->assertUnprocessable();
});

test('resolves nearest FSA for valid GTA coordinates', function () {
    // Exact centroid of M5V (Waterfront Communities, Toronto)
    $response = $this->postJson('/api/postal-codes/resolve-coords', [
        'lat' => 43.6406,
        'lng' => -79.3961,
    ]);

    $response->assertOk()
        ->assertJsonStructure([
            'data' => ['fsa', 'municipality', 'neighbourhood', 'lat', 'lng'],
        ]);

    expect($response->json('data.fsa'))->toBe('M5V');
});
