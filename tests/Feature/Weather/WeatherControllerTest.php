<?php

declare(strict_types=1);

use App\Services\Weather\DTOs\WeatherData;
use App\Services\Weather\Exceptions\WeatherFetchException;
use App\Services\Weather\Providers\EnvironmentCanadaWeatherProvider;
use App\Services\Weather\WeatherCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function weatherControllerStub(string $fsa = 'M5V'): WeatherData
{
    return new WeatherData(
        fsa: $fsa,
        provider: EnvironmentCanadaWeatherProvider::NAME,
        temperature: 15.5,
        humidity: 65.0,
        windSpeed: '20 km/h',
        windDirection: 'NW',
        condition: 'Mostly Cloudy',
        alertLevel: null,
        alertText: null,
        fetchedAt: new DateTimeImmutable('2026-03-25T12:00:00+00:00'),
    );
}

test('returns 422 when fsa is missing', function () {
    $this->getJson('/api/weather')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fsa']);
});

test('returns 422 when fsa is not a valid GTA postal code', function () {
    $this->getJson('/api/weather?fsa=ZZZ')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fsa']);
});

test('returns 422 when fsa has malformed postal format before normalization', function () {
    $this->mock(WeatherCacheService::class)
        ->shouldNotReceive('get');

    $this->getJson('/api/weather?fsa=M5VXYZ')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fsa']);
});

test('returns 200 with weather resource for valid fsa', function () {
    $this->mock(WeatherCacheService::class)
        ->shouldReceive('get')
        ->with('M5V')
        ->andReturn(weatherControllerStub('M5V'));

    $response = $this->getJson('/api/weather?fsa=M5V');

    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                'fsa',
                'provider',
                'temperature',
                'humidity',
                'wind_speed',
                'wind_direction',
                'condition',
                'alert_level',
                'alert_text',
                'fetched_at',
            ],
        ]);

    expect($response->json('data.fsa'))->toBe('M5V');
    expect($response->json('data.temperature'))->toBe(15.5);
    expect($response->json('data.wind_speed'))->toBe('20 km/h');
    expect($response->json('data.alert_level'))->toBeNull();
});

test('normalizes fsa before lookup', function () {
    $this->mock(WeatherCacheService::class)
        ->shouldReceive('get')
        ->with('M5V')
        ->andReturn(weatherControllerStub('M5V'));

    // Lowercase with full postal code — normalizes to M5V
    $response = $this->getJson('/api/weather?fsa=m5v+1a1');

    $response->assertOk();
    expect($response->json('data.fsa'))->toBe('M5V');
});

test('returns 503 when weather provider fails', function () {
    $this->mock(WeatherCacheService::class)
        ->shouldReceive('get')
        ->with('M5V')
        ->andThrow(new WeatherFetchException('M5V', 'environment_canada', 'connection timeout'));

    $this->getJson('/api/weather?fsa=M5V')
        ->assertStatus(503)
        ->assertJsonFragment(['message' => 'Weather data is temporarily unavailable.']);
});

test('weather resource includes alert fields when present', function () {
    $weatherData = new WeatherData(
        fsa: 'M5V',
        provider: EnvironmentCanadaWeatherProvider::NAME,
        temperature: 5.0,
        humidity: 80.0,
        windSpeed: '40 km/h',
        windDirection: 'S',
        condition: 'Freezing Rain',
        alertLevel: 'orange',
        alertText: 'Freezing rain warning in effect.',
        fetchedAt: new DateTimeImmutable('2026-03-25T12:00:00+00:00'),
    );

    $this->mock(WeatherCacheService::class)
        ->shouldReceive('get')
        ->with('M5V')
        ->andReturn($weatherData);

    $response = $this->getJson('/api/weather?fsa=M5V');

    $response->assertOk();
    expect($response->json('data.alert_level'))->toBe('orange');
    expect($response->json('data.alert_text'))->toBe('Freezing rain warning in effect.');
});
