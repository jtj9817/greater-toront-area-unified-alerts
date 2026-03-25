<?php

use App\Models\GtaPostalCode;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

// --- Normalization (no DB needed) ---

test('normalize extracts first 3 chars uppercase from a full postal code', function () {
    expect(GtaPostalCode::normalize('M5V 1A1'))->toBe('M5V');
});

test('normalize handles lowercase input', function () {
    expect(GtaPostalCode::normalize('m5v'))->toBe('M5V');
});

test('normalize handles already-normalized input', function () {
    expect(GtaPostalCode::normalize('M5V'))->toBe('M5V');
});

test('normalize strips internal spaces before extracting', function () {
    expect(GtaPostalCode::normalize('m 5 v'))->toBe('M5V');
});

test('normalize handles mixed case postal code with space', function () {
    expect(GtaPostalCode::normalize('l4c 2B3'))->toBe('L4C');
});

// --- Model attributes ---

test('model has expected fillable attributes', function () {
    expect((new GtaPostalCode)->getFillable())->toBe([
        'fsa', 'municipality', 'neighbourhood', 'lat', 'lng',
    ]);
});

test('model has no timestamps', function () {
    expect((new GtaPostalCode)->timestamps)->toBeFalse();
});

test('model casts lat and lng to float', function () {
    $record = new GtaPostalCode(['lat' => '43.6426', 'lng' => '-79.3871']);
    expect($record->lat)->toBe(43.6426);
    expect($record->lng)->toBe(-79.3871);
});

// --- Search (uses migration-seeded data) ---

test('search returns record matching exact FSA', function () {
    $results = GtaPostalCode::search('M5V')->get();

    expect($results->first())->not->toBeNull();
    expect($results->first()->fsa)->toBe('M5V');
});

test('search normalizes FSA query before matching', function () {
    // "m5v 1a1" normalizes to "M5V"
    $results = GtaPostalCode::search('m5v 1a1')->get();

    expect($results)->not->toBeEmpty();
    expect($results->first()->fsa)->toBe('M5V');
});

test('search returns multiple records matching municipality substring', function () {
    $results = GtaPostalCode::search('Mississauga')->get();

    expect($results->count())->toBeGreaterThan(5);
    expect($results->pluck('municipality')->unique()->values()->all())->toBe(['Mississauga']);
});

test('search returns records matching neighbourhood substring', function () {
    $results = GtaPostalCode::search('Scarborough')->get();

    // Several M1x neighbourhoods contain "Scarborough" in their name
    expect($results->count())->toBeGreaterThan(0);
    foreach ($results as $record) {
        expect(
            stripos($record->fsa, 'M1') === 0
            || stripos($record->neighbourhood ?? '', 'Scarborough') !== false
            || stripos($record->municipality ?? '', 'Scarborough') !== false
        )->toBeTrue();
    }
});

test('search returns no results for unknown query', function () {
    $results = GtaPostalCode::search('Zzznotarealplace')->get();

    expect($results)->toBeEmpty();
});

test('search prioritises FSA exact match at top of results', function () {
    // M5W's neighbourhood contains "M5V Area" in test data but we use seeded data;
    // simply confirm that searching "M5V" returns M5V as the first result.
    $results = GtaPostalCode::search('M5V')->get();

    expect($results->first()->fsa)->toBe('M5V');
});

// --- Nearest FSA (uses migration-seeded data) ---

test('nearestFsa returns M5V when given coordinates near its centroid', function () {
    // M5V centroid: 43.6406, -79.3961
    $result = GtaPostalCode::nearestFsa(43.641, -79.396);

    expect($result)->not->toBeNull();
    expect($result->fsa)->toBe('M5V');
});

test('nearestFsa returns M1B when given coordinates near scarborough village', function () {
    // M1B centroid: 43.8113, -79.1949
    $result = GtaPostalCode::nearestFsa(43.812, -79.195);

    expect($result)->not->toBeNull();
    expect($result->fsa)->toBe('M1B');
});

test('nearestFsa returns null when table is empty', function () {
    GtaPostalCode::query()->delete();

    $result = GtaPostalCode::nearestFsa(43.641, -79.396);

    expect($result)->toBeNull();
});
