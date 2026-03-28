<?php

use App\Services\Weather\FeelsLikeCalculator;

// ---------------------------------------------------------------------------
// Wind Chill
// ---------------------------------------------------------------------------

test('computes wind chill when temperature is at or below 10 and wind exceeds 4.8 km/h', function () {
    // T=0°C, W=20 km/h → expected ≈ -5.5
    $result = FeelsLikeCalculator::compute(0.0, 20.0, null);

    expect($result)->toBeFloat()->toBeBetween(-7.0, -4.0);
});

test('computes wind chill at temperature boundary of 10 degrees', function () {
    $result = FeelsLikeCalculator::compute(10.0, 20.0, null);

    expect($result)->toBeFloat()->toBeLessThan(10.0);
});

test('wind chill is colder than actual temperature', function () {
    $result = FeelsLikeCalculator::compute(-5.0, 30.0, null);

    expect($result)->not->toBeNull()->toBeLessThan(-5.0);
});

test('returns null when wind speed is at or below 4.8 km/h for cold temperature', function () {
    expect(FeelsLikeCalculator::compute(5.0, 4.8, null))->toBeNull();
    expect(FeelsLikeCalculator::compute(5.0, 0.0, null))->toBeNull();
});

test('returns null when wind speed is null for cold temperature', function () {
    expect(FeelsLikeCalculator::compute(5.0, null, null))->toBeNull();
});

test('wind chill result is rounded to one decimal place', function () {
    $result = FeelsLikeCalculator::compute(-3.0, 25.0, null);

    expect($result)->toBeFloat();
    // Verify one decimal precision: multiply by 10, floor, divide by 10 should equal original
    expect(round($result * 10) / 10)->toBe($result);
});

// ---------------------------------------------------------------------------
// Humidex
// ---------------------------------------------------------------------------

test('computes humidex when temperature is at or above 20 and dewpoint is available', function () {
    // T=22.456°C, Td=15.0°C → expected ≈ 26.4
    $result = FeelsLikeCalculator::compute(22.456, null, 15.0);

    expect($result)->toBeFloat()->toBeBetween(25.0, 28.0);
});

test('computes humidex at temperature boundary of 20 degrees', function () {
    $result = FeelsLikeCalculator::compute(20.0, null, 10.0);

    expect($result)->toBeFloat()->toBeGreaterThanOrEqual(20.0);
});

test('humidex is warmer than actual temperature when dewpoint is high', function () {
    $result = FeelsLikeCalculator::compute(30.0, null, 25.0);

    expect($result)->not->toBeNull()->toBeGreaterThan(30.0);
});

test('returns null when dewpoint is null for warm temperature', function () {
    expect(FeelsLikeCalculator::compute(25.0, null, null))->toBeNull();
});

test('humidex result is rounded to one decimal place', function () {
    $result = FeelsLikeCalculator::compute(28.0, null, 20.0);

    expect($result)->toBeFloat();
    expect(round($result * 10) / 10)->toBe($result);
});

// ---------------------------------------------------------------------------
// Neutral range (no formula applies)
// ---------------------------------------------------------------------------

test('returns null in neutral range between 10 and 20 degrees', function () {
    expect(FeelsLikeCalculator::compute(15.0, 30.0, 10.0))->toBeNull();
    expect(FeelsLikeCalculator::compute(11.0, 50.0, 15.0))->toBeNull();
    expect(FeelsLikeCalculator::compute(19.9, 20.0, 18.0))->toBeNull();
});

// ---------------------------------------------------------------------------
// Null inputs
// ---------------------------------------------------------------------------

test('returns null when temperature is null', function () {
    expect(FeelsLikeCalculator::compute(null, 20.0, 15.0))->toBeNull();
});

// ---------------------------------------------------------------------------
// Wind Chill takes precedence over Humidex when temperature is ambiguous
// (temperatures ≤ 10 always use wind chill if wind is present)
// ---------------------------------------------------------------------------

test('wind chill formula is applied not humidex when temperature is 10 or below', function () {
    // Even with dewpoint available, wind chill should win at T=10
    $result = FeelsLikeCalculator::compute(10.0, 20.0, 15.0);

    expect($result)->toBeFloat()->toBeLessThan(10.0);
});
