<?php

use App\Models\WeatherCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(Tests\TestCase::class, RefreshDatabase::class);

// --- Model attributes ---

test('model has expected fillable attributes', function () {
    expect((new WeatherCache)->getFillable())->toBe([
        'fsa', 'provider', 'payload', 'fetched_at',
    ]);
});

test('model casts payload array to json and back, and fetched_at to datetime', function () {
    $cache = new WeatherCache([
        'payload' => ['temperature' => 22.5],
        'fetched_at' => '2026-03-25 12:00:00',
    ]);

    expect($cache->payload)->toBe(['temperature' => 22.5]);
    expect($cache->fetched_at)->toBeInstanceOf(DateTimeInterface::class);
});

// --- isFresh() ---

test('isFresh returns true for record fetched within default ttl', function () {
    $cache = new WeatherCache([
        'fetched_at' => now()->subMinutes(10),
    ]);

    expect($cache->isFresh())->toBeTrue();
});

test('isFresh returns false for record fetched beyond default ttl', function () {
    $cache = new WeatherCache([
        'fetched_at' => now()->subMinutes(31),
    ]);

    expect($cache->isFresh())->toBeFalse();
});

test('isFresh respects custom ttl argument', function () {
    $cache = new WeatherCache([
        'fetched_at' => now()->subMinutes(10),
    ]);

    expect($cache->isFresh(5))->toBeFalse();
    expect($cache->isFresh(15))->toBeTrue();
});

test('isFresh returns false exactly at ttl boundary', function () {
    $cache = new WeatherCache([
        'fetched_at' => now()->subMinutes(WeatherCache::TTL_MINUTES),
    ]);

    expect($cache->isFresh())->toBeFalse();
});

// --- findValid() ---

test('findValid returns most recent valid cache entry for fsa and provider', function () {
    Carbon::setTestNow(now());

    WeatherCache::create([
        'fsa' => 'M5V',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 22.5],
        'fetched_at' => now()->subMinutes(10),
    ]);

    $result = WeatherCache::findValid('M5V', 'environment_canada');

    expect($result)->not->toBeNull();
    expect($result->fsa)->toBe('M5V');
    expect($result->provider)->toBe('environment_canada');

    Carbon::setTestNow();
});

test('findValid returns null for expired cache entry', function () {
    Carbon::setTestNow(now());

    WeatherCache::create([
        'fsa' => 'M5V',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 18.0],
        'fetched_at' => now()->subMinutes(WeatherCache::TTL_MINUTES + 5),
    ]);

    $result = WeatherCache::findValid('M5V', 'environment_canada');

    expect($result)->toBeNull();

    Carbon::setTestNow();
});

test('findValid returns null exactly at ttl boundary', function () {
    Carbon::setTestNow(now());

    WeatherCache::create([
        'fsa' => 'M5V',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 18.0],
        'fetched_at' => now()->subMinutes(WeatherCache::TTL_MINUTES),
    ]);

    $result = WeatherCache::findValid('M5V', 'environment_canada');

    expect($result)->toBeNull();

    Carbon::setTestNow();
});

test('findValid returns null when no entry exists for fsa', function () {
    $result = WeatherCache::findValid('M5V', 'environment_canada');

    expect($result)->toBeNull();
});

test('findValid returns null for different provider', function () {
    Carbon::setTestNow(now());

    WeatherCache::create([
        'fsa' => 'M5V',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 20.0],
        'fetched_at' => now()->subMinutes(5),
    ]);

    $result = WeatherCache::findValid('M5V', 'other_provider');

    expect($result)->toBeNull();

    Carbon::setTestNow();
});

test('findValid returns null for different fsa', function () {
    Carbon::setTestNow(now());

    WeatherCache::create([
        'fsa' => 'M5V',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 20.0],
        'fetched_at' => now()->subMinutes(5),
    ]);

    $result = WeatherCache::findValid('M1B', 'environment_canada');

    expect($result)->toBeNull();

    Carbon::setTestNow();
});

test('findValid returns the most recently fetched entry when multiple valid entries exist', function () {
    Carbon::setTestNow(now());

    WeatherCache::create([
        'fsa' => 'M5V',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 18.0],
        'fetched_at' => now()->subMinutes(20),
    ]);

    WeatherCache::create([
        'fsa' => 'M5V',
        'provider' => 'environment_canada',
        'payload' => ['temperature' => 22.5],
        'fetched_at' => now()->subMinutes(5),
    ]);

    $result = WeatherCache::findValid('M5V', 'environment_canada');

    expect($result->payload['temperature'])->toBe(22.5);

    Carbon::setTestNow();
});
