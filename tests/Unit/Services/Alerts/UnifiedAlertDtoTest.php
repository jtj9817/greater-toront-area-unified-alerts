<?php

use App\Services\Alerts\DTOs\AlertLocation;
use App\Services\Alerts\DTOs\UnifiedAlert;
use Carbon\CarbonImmutable;

test('alert location dto stores values', function () {
    $location = new AlertLocation(
        name: 'Yonge St / Dundas St',
        lat: 43.6561,
        lng: -79.3802,
        postalCode: 'M5B',
    );

    expect($location->name)->toBe('Yonge St / Dundas St');
    expect($location->lat)->toBe(43.6561);
    expect($location->lng)->toBe(-79.3802);
    expect($location->postalCode)->toBe('M5B');
});

test('unified alert dto stores values and defaults', function () {
    $timestamp = CarbonImmutable::parse('2026-02-02T12:00:00Z');
    $location = new AlertLocation(name: 'Somewhere');

    $alert = new UnifiedAlert(
        id: 'fire:E123',
        source: 'fire',
        externalId: 'E123',
        isActive: true,
        timestamp: $timestamp,
        title: 'STRUCTURE FIRE',
        location: $location,
        meta: ['alarm_level' => 2],
    );

    expect($alert->id)->toBe('fire:E123');
    expect($alert->source)->toBe('fire');
    expect($alert->externalId)->toBe('E123');
    expect($alert->isActive)->toBeTrue();
    expect($alert->timestamp)->toBeInstanceOf(CarbonImmutable::class);
    expect($alert->title)->toBe('STRUCTURE FIRE');
    expect($alert->location)->toBe($location);
    expect($alert->meta)->toBe(['alarm_level' => 2]);
});

test('unified alert dto allows null location and empty meta', function () {
    $timestamp = CarbonImmutable::parse('2026-02-02T12:00:00Z');

    $alert = new UnifiedAlert(
        id: 'police:123',
        source: 'police',
        externalId: '123',
        isActive: false,
        timestamp: $timestamp,
        title: 'THEFT',
        location: null,
    );

    expect($alert->location)->toBeNull();
    expect($alert->meta)->toBeArray()->toBeEmpty();
});
