<?php

use App\Models\WeatherCache;
use App\Services\Weather\Contracts\WeatherProvider;
use App\Services\Weather\DTOs\WeatherData;
use App\Services\Weather\Exceptions\WeatherFetchException;
use App\Services\Weather\Providers\EnvironmentCanadaWeatherProvider;
use App\Services\Weather\WeatherCacheService;
use App\Services\Weather\WeatherFetchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

// Helper: build a WeatherData stub
function cacheStubWeatherData(
    string $fsa = 'M5V',
    string $provider = EnvironmentCanadaWeatherProvider::NAME,
    float $temperature = 22.0,
): WeatherData {
    return new WeatherData(
        fsa: $fsa,
        provider: $provider,
        temperature: $temperature,
        humidity: 65.0,
        windSpeed: '20 km/h',
        windDirection: 'NW',
        condition: 'Mostly Cloudy',
        alertLevel: null,
        alertText: null,
        fetchedAt: new DateTimeImmutable,
    );
}

// Helper: build a WeatherCacheService with a mock WeatherFetchService
function makeCacheService(?callable $fetchBehavior = null): WeatherCacheService
{
    $fetchServiceMock = Mockery::mock(WeatherFetchService::class);

    // getProviders() is called to enumerate DB cache lookup keys
    $providerMock = Mockery::mock(WeatherProvider::class);
    $providerMock->allows('name')->andReturn(EnvironmentCanadaWeatherProvider::NAME);
    $fetchServiceMock->allows('getProviders')->andReturn([$providerMock]);

    if ($fetchBehavior !== null) {
        $fetchBehavior($fetchServiceMock);
    } else {
        $fetchServiceMock->allows('fetch')->andReturn(cacheStubWeatherData());
    }

    return new WeatherCacheService($fetchServiceMock);
}

beforeEach(function () {
    Cache::flush();
});

// --- Fast cache (Layer 1) ---

test('returns WeatherData from fast cache without touching DB or upstream', function () {
    $data = cacheStubWeatherData();
    Cache::put('weather.current.M5V', $data, now()->addMinutes(30));

    $fetchService = Mockery::mock(WeatherFetchService::class);
    $fetchService->expects('getProviders')->never();
    $fetchService->expects('fetch')->never();

    $service = new WeatherCacheService($fetchService);

    expect($service->get('M5V'))->toBe($data);
});

// --- Durable DB cache (Layer 2) ---

test('returns hydrated WeatherData from DB cache on fast cache miss', function () {
    Carbon::setTestNow(now());

    WeatherCache::create([
        'fsa' => 'M5V',
        'provider' => EnvironmentCanadaWeatherProvider::NAME,
        'payload' => [
            'temperature' => 18.5,
            'humidity' => 70.0,
            'wind_speed' => '10 km/h',
            'wind_direction' => 'SW',
            'condition' => 'Sunny',
            'alert_level' => 'yellow',
            'alert_text' => 'SPECIAL WEATHER STATEMENT IN EFFECT',
            'fetched_at' => now()->subMinutes(5)->toAtomString(),
        ],
        'fetched_at' => now()->subMinutes(5),
    ]);

    $service = makeCacheService(fn ($m) => $m->expects('fetch')->never());

    $data = $service->get('M5V');

    expect($data->fsa)->toBe('M5V');
    expect($data->temperature)->toBe(18.5);
    expect($data->humidity)->toBe(70.0);
    expect($data->condition)->toBe('Sunny');
    expect($data->alertLevel)->toBe('yellow');
    expect($data->alertText)->toBe('SPECIAL WEATHER STATEMENT IN EFFECT');

    Carbon::setTestNow();
});

test('repopulates fast cache from DB cache entry', function () {
    Carbon::setTestNow(now());

    WeatherCache::create([
        'fsa' => 'M5V',
        'provider' => EnvironmentCanadaWeatherProvider::NAME,
        'payload' => [
            'temperature' => 18.5,
            'humidity' => 70.0,
            'wind_speed' => '10 km/h',
            'wind_direction' => 'SW',
            'condition' => 'Sunny',
            'alert_level' => null,
            'alert_text' => null,
            'fetched_at' => now()->subMinutes(5)->toAtomString(),
        ],
        'fetched_at' => now()->subMinutes(5),
    ]);

    $service = makeCacheService(fn ($m) => $m->expects('fetch')->never());
    $service->get('M5V');

    expect(Cache::has('weather.current.M5V'))->toBeTrue();

    Carbon::setTestNow();
});

// --- Full miss → upstream (Layer 3) ---

test('calls upstream when both caches miss and stores result', function () {
    Carbon::setTestNow(now());

    $upstream = cacheStubWeatherData('M5V', EnvironmentCanadaWeatherProvider::NAME, 25.0);

    $service = makeCacheService(fn ($m) => $m->expects('fetch')->once()->andReturn($upstream));

    $data = $service->get('M5V');

    expect($data->temperature)->toBe(25.0);

    // Should now be in DB cache
    $dbEntry = WeatherCache::where('fsa', 'M5V')->first();
    expect($dbEntry)->not->toBeNull();
    expect((float) $dbEntry->payload['temperature'])->toBe(25.0);

    // Should now be in fast cache
    expect(Cache::has('weather.current.M5V'))->toBeTrue();

    Carbon::setTestNow();
});

test('upstream is not called again after a successful full miss (fast cache populated)', function () {
    $upstream = cacheStubWeatherData();

    $fetchService = Mockery::mock(WeatherFetchService::class);
    $providerMock = Mockery::mock(WeatherProvider::class);
    $providerMock->allows('name')->andReturn(EnvironmentCanadaWeatherProvider::NAME);
    $fetchService->allows('getProviders')->andReturn([$providerMock]);
    $fetchService->expects('fetch')->once()->andReturn($upstream);

    $service = new WeatherCacheService($fetchService);

    $service->get('M5V'); // populates caches
    $service->get('M5V'); // should hit fast cache, no second fetch
});

// --- Expired DB cache is ignored ---

test('ignores expired DB cache entry and fetches upstream', function () {
    Carbon::setTestNow(now());

    WeatherCache::create([
        'fsa' => 'M5V',
        'provider' => EnvironmentCanadaWeatherProvider::NAME,
        'payload' => ['temperature' => 10.0],
        'fetched_at' => now()->subMinutes(WeatherCache::TTL_MINUTES + 5),
    ]);

    $upstream = cacheStubWeatherData('M5V', EnvironmentCanadaWeatherProvider::NAME, 25.0);
    $service = makeCacheService(fn ($m) => $m->expects('fetch')->once()->andReturn($upstream));

    $data = $service->get('M5V');

    expect($data->temperature)->toBe(25.0);

    Carbon::setTestNow();
});

// --- Upstream failure propagation ---

test('propagates WeatherFetchException when all caches miss and upstream fails', function () {
    $service = makeCacheService(fn ($m) => $m->expects('fetch')->andThrow(
        new WeatherFetchException('M5V', 'none', 'All providers failed')
    ));

    expect(fn () => $service->get('M5V'))
        ->toThrow(WeatherFetchException::class);
});

// --- Payload hydration edge cases ---

test('hydrates WeatherData with null fields from DB cache payload correctly', function () {
    Carbon::setTestNow(now());

    WeatherCache::create([
        'fsa' => 'M5V',
        'provider' => EnvironmentCanadaWeatherProvider::NAME,
        'payload' => [
            'temperature' => null,
            'humidity' => null,
            'wind_speed' => null,
            'wind_direction' => null,
            'condition' => null,
            'alert_level' => null,
            'alert_text' => null,
            'fetched_at' => now()->subMinutes(5)->toAtomString(),
        ],
        'fetched_at' => now()->subMinutes(5),
    ]);

    $service = makeCacheService(fn ($m) => $m->expects('fetch')->never());

    $data = $service->get('M5V');

    expect($data->temperature)->toBeNull();
    expect($data->humidity)->toBeNull();
    expect($data->condition)->toBeNull();
    expect($data->alertLevel)->toBeNull();

    Carbon::setTestNow();
});
