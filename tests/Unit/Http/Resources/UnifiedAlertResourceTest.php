<?php

use App\Http\Resources\UnifiedAlertResource;
use App\Services\Alerts\DTOs\AlertLocation;
use App\Services\Alerts\DTOs\UnifiedAlert;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

test('unified alert resource maps dto to transport shape', function () {
    $timestamp = CarbonImmutable::parse('2026-02-02T12:00:00Z');

    $dto = new UnifiedAlert(
        id: 'fire:F123',
        source: 'fire',
        externalId: 'F123',
        isActive: true,
        timestamp: $timestamp,
        title: 'STRUCTURE FIRE',
        location: new AlertLocation(
            name: 'Yonge St / Dundas St',
            lat: 43.6561,
            lng: -79.3802,
            postalCode: 'M5B',
        ),
        meta: [
            'alarm_level' => 2,
            'event_num' => 'F123',
        ],
    );

    $data = (new UnifiedAlertResource($dto))->toArray(Request::create('/', 'GET'));

    expect($data)->toBe([
        'id' => 'fire:F123',
        'source' => 'fire',
        'external_id' => 'F123',
        'is_active' => true,
        'timestamp' => $timestamp->toIso8601String(),
        'title' => 'STRUCTURE FIRE',
        'location' => [
            'name' => 'Yonge St / Dundas St',
            'lat' => 43.6561,
            'lng' => -79.3802,
        ],
        'meta' => [
            'alarm_level' => 2,
            'event_num' => 'F123',
        ],
    ]);
});

test('unified alert resource returns null location and empty meta when missing', function () {
    $timestamp = CarbonImmutable::parse('2026-02-02T12:00:00Z');

    $dto = new UnifiedAlert(
        id: 'police:123',
        source: 'police',
        externalId: '123',
        isActive: false,
        timestamp: $timestamp,
        title: 'THEFT',
        location: null,
        meta: [],
    );

    $data = (new UnifiedAlertResource($dto))->toArray(Request::create('/', 'GET'));

    expect($data['location'])->toBeNull();
    expect($data['meta'])->toBe([]);
});
