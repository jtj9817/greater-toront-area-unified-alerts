<?php

use App\Services\Weather\Exceptions\WeatherFetchException;
use App\Services\Weather\Providers\EnvironmentCanadaWeatherProvider;
use Illuminate\Support\Facades\Http;

// Helpers to load HTML fixture content
function ecFixture(string $name): string
{
    return file_get_contents(__DIR__.'/../../../fixtures/weather/'.$name.'.html');
}

// --- name() ---

test('provider name returns environment_canada', function () {
    $provider = new EnvironmentCanadaWeatherProvider;

    expect($provider->name())->toBe('environment_canada');
});

// --- FSA station resolution ---

test('M5V FSA resolves to Toronto station on-143', function () {
    Http::fake(['*' => Http::response(ecFixture('ec_full_conditions'), 200)]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $provider->fetch('M5V');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'on-143'));
});

test('L5A FSA resolves to Mississauga station on-113', function () {
    Http::fake(['*' => Http::response(ecFixture('ec_full_conditions'), 200)]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $provider->fetch('L5A');

    Http::assertSent(fn ($request) => str_contains($request->url(), 'on-113'));
});

test('unknown FSA prefix falls back to default station', function () {
    Http::fake(['*' => Http::response(ecFixture('ec_full_conditions'), 200)]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $provider->fetch('X9Z');

    Http::assertSent(fn ($request) => str_contains($request->url(), config('weather.environment_canada.default_station', 'on-143')));
});

// --- Successful full parse ---

test('parses condition temperature humidity and wind from full conditions fixture', function () {
    Http::fake(['*' => Http::response(ecFixture('ec_full_conditions'), 200)]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->fsa)->toBe('M5V');
    expect($data->provider)->toBe('environment_canada');
    expect($data->condition)->toBe('Mostly Cloudy');
    expect($data->temperature)->toBe(22.0);
    expect($data->humidity)->toBe(65.0);
    expect($data->windSpeed)->toBe('20 km/h');
    expect($data->windDirection)->toBe('NW');
    expect($data->alertLevel)->toBeNull();
    expect($data->alertText)->toBeNull();
    expect($data->fetchedAt)->toBeInstanceOf(DateTimeImmutable::class);
});

// --- Alert level parsing ---

test('parses yellow alert level and text', function () {
    Http::fake(['*' => Http::response(ecFixture('ec_yellow_alert'), 200)]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->alertLevel)->toBe('yellow');
    expect($data->alertText)->toBe('SPECIAL WEATHER STATEMENT IN EFFECT');
});

test('parses orange alert level and text', function () {
    Http::fake(['*' => Http::response(ecFixture('ec_orange_alert'), 200)]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->alertLevel)->toBe('orange');
    expect($data->alertText)->toBe('WIND WARNING IN EFFECT');
});

test('parses red alert level and text', function () {
    Http::fake(['*' => Http::response(ecFixture('ec_red_alert'), 200)]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->alertLevel)->toBe('red');
    expect($data->alertText)->toBe('TORNADO WARNING IN EFFECT');
});

// --- Missing / graceful-null cases ---

test('returns null temperature when temperature element is absent', function () {
    Http::fake(['*' => Http::response(ecFixture('ec_missing_temperature'), 200)]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->temperature)->toBeNull();
    expect($data->condition)->toBe('Fog');
    expect($data->humidity)->toBe(95.0);
});

test('returns null wind fields when wind value is Calm', function () {
    Http::fake(['*' => Http::response(ecFixture('ec_missing_temperature'), 200)]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    // "Calm" wind → speed = '0 km/h', direction = 'Calm'
    expect($data->windSpeed)->toBe('0 km/h');
    expect($data->windDirection)->toBe('Calm');
});

test('returns all nulls when currentconditions element is absent', function () {
    Http::fake(['*' => Http::response(ecFixture('ec_empty_page'), 200)]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->temperature)->toBeNull();
    expect($data->humidity)->toBeNull();
    expect($data->condition)->toBeNull();
    expect($data->windSpeed)->toBeNull();
    expect($data->windDirection)->toBeNull();
    expect($data->alertLevel)->toBeNull();
});

// --- HTTP failure cases ---

test('throws WeatherFetchException on non-200 response', function () {
    Http::fake(['*' => Http::response('Not Found', 404)]);

    $provider = new EnvironmentCanadaWeatherProvider;

    expect(fn () => $provider->fetch('M5V'))
        ->toThrow(WeatherFetchException::class);
});

test('thrown exception carries fsa and provider name', function () {
    Http::fake(['*' => Http::response('Server Error', 500)]);

    $provider = new EnvironmentCanadaWeatherProvider;

    try {
        $provider->fetch('M5V');
        $this->fail('Expected WeatherFetchException');
    } catch (WeatherFetchException $e) {
        expect($e->fsa)->toBe('M5V');
        expect($e->provider)->toBe('environment_canada');
    }
});

test('throws WeatherFetchException on connection failure', function () {
    Http::fake(['*' => function () {
        throw new \Illuminate\Http\Client\ConnectionException('Connection refused');
    }]);

    $provider = new EnvironmentCanadaWeatherProvider;

    expect(fn () => $provider->fetch('M5V'))
        ->toThrow(WeatherFetchException::class);
});
