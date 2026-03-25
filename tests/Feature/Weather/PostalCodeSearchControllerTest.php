<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('returns 422 when q is missing', function () {
    $this->getJson('/api/postal-codes')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['q']);
});

test('returns 422 when q exceeds max length', function () {
    $q = str_repeat('a', 121);

    $this->getJson("/api/postal-codes?q={$q}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['q']);
});

test('returns empty data for query shorter than 2 characters', function () {
    $this->getJson('/api/postal-codes?q=M')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('returns postal codes matching FSA query', function () {
    $response = $this->getJson('/api/postal-codes?q=M5V');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['fsa', 'municipality', 'neighbourhood', 'lat', 'lng'],
            ],
        ]);

    $fsas = collect($response->json('data'))->pluck('fsa');
    expect($fsas->contains('M5V'))->toBeTrue();
});

test('returns postal codes matching municipality query', function () {
    $response = $this->getJson('/api/postal-codes?q=Mississauga');

    $response->assertOk();

    $municipalities = collect($response->json('data'))->pluck('municipality')->unique()->values();
    expect($municipalities->toArray())->toContain('Mississauga');
});

test('FSA exact match is ranked first', function () {
    $response = $this->getJson('/api/postal-codes?q=M5V');

    $response->assertOk();
    expect($response->json('data.0.fsa'))->toBe('M5V');
});

test('returns empty data when no results match', function () {
    $this->getJson('/api/postal-codes?q=NoSuchPlaceXYZ')
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

test('respects optional limit parameter', function () {
    $response = $this->getJson('/api/postal-codes?q=Toronto&limit=3');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

test('returns 422 when limit is out of range', function () {
    $this->getJson('/api/postal-codes?q=Toronto&limit=51')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['limit']);
});
