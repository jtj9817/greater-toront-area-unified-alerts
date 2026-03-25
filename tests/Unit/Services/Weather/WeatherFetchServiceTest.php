<?php

use App\Services\Weather\Contracts\WeatherProvider;
use App\Services\Weather\DTOs\WeatherData;
use App\Services\Weather\Exceptions\WeatherFetchException;
use App\Services\Weather\WeatherFetchService;

uses(Tests\TestCase::class);

// Helper: build a stub WeatherData
function stubWeatherData(string $fsa = 'M5V', string $provider = 'test_provider'): WeatherData
{
    return new WeatherData(
        fsa: $fsa,
        provider: $provider,
        temperature: 20.0,
        humidity: 60.0,
        windSpeed: '15 km/h',
        windDirection: 'N',
        condition: 'Sunny',
        alertLevel: null,
        alertText: null,
        fetchedAt: new DateTimeImmutable,
    );
}

// Helper: build a provider mock that always succeeds
function successProvider(string $name, WeatherData $data): WeatherProvider
{
    $mock = Mockery::mock(WeatherProvider::class);
    $mock->allows('name')->andReturn($name);
    $mock->allows('fetch')->andReturn($data);

    return $mock;
}

// Helper: build a provider mock that always throws
function failingProvider(string $name, string $fsa = 'M5V'): WeatherProvider
{
    $mock = Mockery::mock(WeatherProvider::class);
    $mock->allows('name')->andReturn($name);
    $mock->allows('fetch')->andThrow(new WeatherFetchException($fsa, $name, 'provider unavailable'));

    return $mock;
}

// --- Constructor / empty providers ---

test('throws WeatherFetchException immediately when provider list is empty', function () {
    $service = new WeatherFetchService([]);

    expect(fn () => $service->fetch('M5V'))
        ->toThrow(WeatherFetchException::class);
});

// --- Single provider ---

test('returns WeatherData from single succeeding provider', function () {
    $data = stubWeatherData();
    $service = new WeatherFetchService([successProvider('p1', $data)]);

    expect($service->fetch('M5V'))->toBe($data);
});

test('throws WeatherFetchException when single provider fails', function () {
    $service = new WeatherFetchService([failingProvider('p1')]);

    expect(fn () => $service->fetch('M5V'))
        ->toThrow(WeatherFetchException::class);
});

// --- Provider ordering ---

test('returns result from first provider without calling the second', function () {
    $data = stubWeatherData();

    $first = successProvider('p1', $data);
    $second = Mockery::mock(WeatherProvider::class);
    $second->allows('name')->andReturn('p2');
    $second->expects('fetch')->never();

    $service = new WeatherFetchService([$first, $second]);

    expect($service->fetch('M5V'))->toBe($data);
});

test('falls back to second provider when first fails', function () {
    $data = stubWeatherData();

    $service = new WeatherFetchService([
        failingProvider('p1'),
        successProvider('p2', $data),
    ]);

    expect($service->fetch('M5V'))->toBe($data);
});

test('returns result from third provider when first two fail', function () {
    $data = stubWeatherData();

    $service = new WeatherFetchService([
        failingProvider('p1'),
        failingProvider('p2'),
        successProvider('p3', $data),
    ]);

    expect($service->fetch('M5V'))->toBe($data);
});

test('throws WeatherFetchException when all providers fail', function () {
    $service = new WeatherFetchService([
        failingProvider('p1'),
        failingProvider('p2'),
    ]);

    expect(fn () => $service->fetch('M5V'))
        ->toThrow(WeatherFetchException::class);
});

// --- Exception wrapping ---

test('WeatherFetchException thrown when all fail carries fsa', function () {
    $service = new WeatherFetchService([failingProvider('p1', 'M5V')]);

    try {
        $service->fetch('M5V');
        $this->fail('Expected WeatherFetchException');
    } catch (WeatherFetchException $e) {
        expect($e->fsa)->toBe('M5V');
    }
});

test('thrown exception wraps the last provider exception as previous', function () {
    $inner = new WeatherFetchException('M5V', 'p1', 'connection refused');

    $mock = Mockery::mock(WeatherProvider::class);
    $mock->allows('name')->andReturn('p1');
    $mock->allows('fetch')->andThrow($inner);

    $service = new WeatherFetchService([$mock]);

    try {
        $service->fetch('M5V');
        $this->fail('Expected WeatherFetchException');
    } catch (WeatherFetchException $e) {
        expect($e->getPrevious())->toBe($inner);
    }
});
