<?php

use App\Services\Alerts\DTOs\UnifiedAlert;
use App\Services\Alerts\Mappers\UnifiedAlertMapper;

test('unified alert mapper decodes meta arrays as-is', function () {
    $value = ['a' => 1, 'b' => ['c' => 2]];
    expect(UnifiedAlertMapper::decodeMeta($value))->toBe($value);
});

test('unified alert mapper decodes meta json objects and never leaks json exceptions', function (mixed $meta, array $expected) {
    expect(UnifiedAlertMapper::decodeMeta($meta))->toBe($expected);
})->with([
    'null' => [null, []],
    'empty string' => ['', []],
    'invalid json' => ['{"invalid', []],
    'valid json object' => ['{"k":1}', ['k' => 1]],
    'valid json array' => ['[1,2]', [1, 2]],
    'valid json scalar string' => ['"k"', []],
    'valid json scalar int' => ['123', []],
    'valid json null' => ['null', []],
]);

test('unified alert mapper maps a row to a dto with location and decoded meta', function () {
    $row = (object) [
        'id' => 'fire:F123',
        'source' => 'fire',
        'external_id' => 'F123',
        'is_active' => 1,
        'timestamp' => '2026-02-02 12:34:00',
        'title' => 'STRUCTURE FIRE',
        'location_name' => 'Yonge St / Dundas St',
        'lat' => '43.6561',
        'lng' => '-79.3802',
        'meta' => '{"alarm_level":2}',
    ];

    $alert = (new UnifiedAlertMapper)->fromRow($row);

    expect($alert)->toBeInstanceOf(UnifiedAlert::class);
    expect($alert->id)->toBe('fire:F123');
    expect($alert->source)->toBe('fire');
    expect($alert->externalId)->toBe('F123');
    expect($alert->isActive)->toBeTrue();
    expect($alert->timestamp->toDateTimeString())->toBe('2026-02-02 12:34:00');
    expect($alert->title)->toBe('STRUCTURE FIRE');

    expect($alert->location)->not->toBeNull();
    expect($alert->location?->name)->toBe('Yonge St / Dundas St');
    expect($alert->location?->lat)->toBeFloat()->toBe(43.6561);
    expect($alert->location?->lng)->toBeFloat()->toBe(-79.3802);

    expect($alert->meta)->toBe(['alarm_level' => 2]);
});

test('unified alert mapper returns null location when all location fields are null', function () {
    $row = (object) [
        'id' => 'police:1',
        'source' => 'police',
        'external_id' => '1',
        'is_active' => '0',
        'timestamp' => '2026-02-02 12:00:00',
        'title' => 'THEFT',
        'location_name' => null,
        'lat' => null,
        'lng' => null,
        'meta' => null,
    ];

    $alert = (new UnifiedAlertMapper)->fromRow($row);
    expect($alert->location)->toBeNull();
    expect($alert->isActive)->toBeFalse();
});

test('unified alert mapper builds a coords-only location and preserves zero coords', function () {
    $mapper = new UnifiedAlertMapper;

    $coordsOnly = $mapper->fromRow((object) [
        'id' => 'police:coords',
        'source' => 'police',
        'external_id' => 'coords',
        'is_active' => 1,
        'timestamp' => '2026-02-02 12:00:00',
        'title' => 'THEFT',
        'location_name' => null,
        'lat' => 43.65,
        'lng' => -79.38,
        'meta' => null,
    ]);

    expect($coordsOnly->location)->not->toBeNull();
    expect($coordsOnly->location?->name)->toBeNull();
    expect($coordsOnly->location?->lat)->toBeFloat()->toBe(43.65);
    expect($coordsOnly->location?->lng)->toBeFloat()->toBe(-79.38);

    $zeroCoords = $mapper->fromRow((object) [
        'id' => 'police:zero',
        'source' => 'police',
        'external_id' => 'zero',
        'is_active' => 1,
        'timestamp' => '2026-02-02 12:00:00',
        'title' => 'THEFT',
        'location_name' => null,
        'lat' => 0.0,
        'lng' => 0.0,
        'meta' => null,
    ]);

    expect($zeroCoords->location)->not->toBeNull();
    expect($zeroCoords->location?->lat)->toBeFloat()->toBe(0.0);
    expect($zeroCoords->location?->lng)->toBeFloat()->toBe(0.0);
});

test('unified alert mapper throws when timestamp is missing or not parseable', function (mixed $timestamp) {
    $row = (object) [
        'id' => 'ts:1',
        'source' => 'fire',
        'external_id' => '1',
        'is_active' => 1,
        'timestamp' => $timestamp,
        'title' => 'TEST',
        'location_name' => null,
        'lat' => null,
        'lng' => null,
        'meta' => null,
    ];

    expect(fn () => (new UnifiedAlertMapper)->fromRow($row))
        ->toThrow(\InvalidArgumentException::class);
})->with([
    'null' => [null],
    'empty' => [''],
    'not parseable' => ['not-a-timestamp'],
]);

test('unified alert mapper throws when required string fields are missing or empty', function (string $property, mixed $value) {
    $row = (object) [
        'id' => 'fire:1',
        'source' => 'fire',
        'external_id' => '1',
        'is_active' => 1,
        'timestamp' => '2026-02-02 12:00:00',
        'title' => 'TEST',
        'location_name' => null,
        'lat' => null,
        'lng' => null,
        'meta' => null,
    ];

    $row->{$property} = $value;

    expect(fn () => (new UnifiedAlertMapper)->fromRow($row))
        ->toThrow(\InvalidArgumentException::class);
})->with([
    'id null' => ['id', null],
    'id empty' => ['id', ''],
    'source null' => ['source', null],
    'external_id empty' => ['external_id', ''],
    'title empty' => ['title', ''],
]);

