<?php

use App\Services\FeedDataSanity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-02-17 00:00:00', 'UTC'));
});

afterEach(function () {
    Carbon::setTestNow();
});

test('it warns when a timestamp is beyond the configured grace window', function () {
    Log::spy();
    config(['feeds.sanity.future_timestamp_grace_seconds' => 900]);

    $sanity = app(FeedDataSanity::class);
    $sanity->warnIfFutureTimestamp(
        timestamp: Carbon::now('UTC')->addSeconds(901),
        source: 'test_source',
        field: 'updated_at',
        context: ['id' => 123],
    );

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Feed record timestamp is unexpectedly in the future'
                && ($context['source'] ?? null) === 'test_source'
                && ($context['field'] ?? null) === 'updated_at'
                && ($context['grace_seconds'] ?? null) === 900
                && ($context['id'] ?? null) === 123;
        })
        ->once();
});

test('it does not warn when a timestamp is within the grace window or exactly at the boundary', function () {
    Log::spy();
    config(['feeds.sanity.future_timestamp_grace_seconds' => 900]);

    $sanity = app(FeedDataSanity::class);

    $sanity->warnIfFutureTimestamp(
        timestamp: Carbon::now('UTC')->addSeconds(900),
        source: 'test_source',
        field: 'updated_at',
    );

    $sanity->warnIfFutureTimestamp(
        timestamp: Carbon::now('UTC')->addSeconds(899),
        source: 'test_source',
        field: 'updated_at',
    );

    Log::shouldNotHaveReceived('warning');
});

test('it clamps a negative grace window to zero', function () {
    Log::spy();
    config(['feeds.sanity.future_timestamp_grace_seconds' => -5]);

    $sanity = app(FeedDataSanity::class);

    $sanity->warnIfFutureTimestamp(
        timestamp: Carbon::now('UTC'),
        source: 'test_source',
        field: 'updated_at',
    );

    $sanity->warnIfFutureTimestamp(
        timestamp: Carbon::now('UTC')->addSecond(),
        source: 'test_source',
        field: 'updated_at',
    );

    Log::shouldHaveReceived('warning')->once();
});

test('it warns when coordinates fall outside GTA bounds and no-ops on null coordinates', function () {
    Log::spy();
    config([
        'feeds.sanity.gta_bounds' => [
            'min_lat' => 43.0,
            'max_lat' => 44.5,
            'min_lng' => -80.5,
            'max_lng' => -78.0,
        ],
    ]);

    $sanity = app(FeedDataSanity::class);

    $sanity->warnIfCoordinatesOutsideGta(
        lat: null,
        lng: -79.0,
        source: 'test_source',
    );

    $sanity->warnIfCoordinatesOutsideGta(
        lat: 43.7,
        lng: -79.4,
        source: 'test_source',
        context: ['id' => 123],
    );

    $sanity->warnIfCoordinatesOutsideGta(
        lat: 45.0,
        lng: -81.0,
        source: 'test_source',
        context: ['id' => 999],
    );

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            return $message === 'Feed record coordinates fall outside GTA bounds'
                && ($context['source'] ?? null) === 'test_source'
                && ($context['latitude'] ?? null) === 45.0
                && ($context['longitude'] ?? null) === -81.0
                && ($context['id'] ?? null) === 999
                && is_array($context['bounds'] ?? null);
        })
        ->once();
});

test('it falls back to default GTA bounds when config is missing or invalid', function () {
    Log::spy();
    config(['feeds.sanity.gta_bounds' => 'not-an-array']);

    $sanity = app(FeedDataSanity::class);
    $sanity->warnIfCoordinatesOutsideGta(
        lat: 45.0,
        lng: -81.0,
        source: 'test_source',
    );

    Log::shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $context): bool {
            $bounds = $context['bounds'] ?? null;

            return $message === 'Feed record coordinates fall outside GTA bounds'
                && ($context['source'] ?? null) === 'test_source'
                && is_array($bounds)
                && ($bounds['min_lat'] ?? null) === 43.0
                && ($bounds['max_lat'] ?? null) === 44.5
                && ($bounds['min_lng'] ?? null) === -80.5
                && ($bounds['max_lng'] ?? null) === -78.0;
        })
        ->once();
});
