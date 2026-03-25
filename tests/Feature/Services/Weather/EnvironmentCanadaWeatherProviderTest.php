<?php

use App\Models\GtaPostalCode;
use App\Services\Weather\Exceptions\WeatherFetchException;
use App\Services\Weather\Providers\EnvironmentCanadaWeatherProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function ecJsonFixture(string $name): string
{
    return file_get_contents(__DIR__.'/../../../fixtures/weather/'.$name.'.json');
}

test('provider name returns environment_canada', function () {
    $provider = new EnvironmentCanadaWeatherProvider;

    expect($provider->name())->toBe('environment_canada');
});

test('uses coordinate-based API URL', function () {
    Http::fake(['*' => Http::response(ecJsonFixture('no-alert'), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $provider->fetch('M5V');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/app/v3/en/Location/43.6406,-79.3961'));
});

test('resolves FSA to coordinates from gta_postal_codes table', function () {
    Http::fake(['*' => Http::response(ecJsonFixture('no-alert'), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $provider->fetch('M5V');

    Http::assertSent(fn ($request) => str_contains($request->url(), '43.6406'));
});

test('unknown FSA falls back to default coordinates', function () {
    Http::fake(['*' => Http::response(ecJsonFixture('no-alert'), 200)]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $provider->fetch('X9Z');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/app/v3/en/Location/'.config('weather.environment_canada.default_coords.lat')));
});

test('normalizes FSA before lookup', function () {
    Http::fake(['*' => Http::response(ecJsonFixture('no-alert'), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $provider->fetch('m5v');

    Http::assertSent(fn ($request) => str_contains($request->url(), '43.6406'));
});

test('parses full weather data from no-alert payload', function () {
    Http::fake(['*' => Http::response(ecJsonFixture('no-alert'), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->fsa)->toBe('M5V');
    expect($data->provider)->toBe('environment_canada');
    expect($data->temperature)->toBe(22.456);
    expect($data->humidity)->toBe(65.0);
    expect($data->windSpeed)->toBe('20 km/h');
    expect($data->windDirection)->toBe('NW');
    expect($data->condition)->toBe('Mostly Cloudy');
    expect($data->alertLevel)->toBeNull();
    expect($data->alertText)->toBeNull();
    expect($data->fetchedAt)->toBeInstanceOf(DateTimeImmutable::class);
});

test('uses metric fallback when metricUnrounded is not available', function () {
    $payload = json_decode(ecJsonFixture('no-alert'), true);
    unset($payload['observation']['temperature']['metricUnrounded']);
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->temperature)->toBe(22.0);
});

test('parses yellow alert level and text', function () {
    Http::fake(['*' => Http::response(ecJsonFixture('yellow-alert'), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->alertLevel)->toBe('yellow');
    expect($data->alertText)->toBe('SPECIAL WEATHER STATEMENT IN EFFECT');
});

test('parses orange alert level and text', function () {
    Http::fake(['*' => Http::response(ecJsonFixture('orange-alert'), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->alertLevel)->toBe('orange');
    expect($data->alertText)->toBe('WIND WARNING IN EFFECT');
});

test('parses red alert level and text', function () {
    Http::fake(['*' => Http::response(ecJsonFixture('red-alert'), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->alertLevel)->toBe('red');
    expect($data->alertText)->toBe('TORNADO WARNING IN EFFECT');
});

test('returns null alert level for unknown severity', function () {
    $payload = json_decode(ecJsonFixture('yellow-alert'), true);
    $payload['alert']['mostSevere'] = 'green';
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->alertLevel)->toBeNull();
    expect($data->alertText)->toBeNull();
});

test('uses alertHeaderText when bannerText is absent', function () {
    $payload = json_decode(ecJsonFixture('yellow-alert'), true);
    unset($payload['alert']['alerts'][0]['bannerText']);
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->alertText)->toBe('A special weather statement has been issued for the GTA area.');
});

test('returns nulls when observation fields are empty strings', function () {
    Http::fake(['*' => Http::response(ecJsonFixture('malformed-payload'), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->temperature)->toBeNull();
    expect($data->humidity)->toBeNull();
    expect($data->windSpeed)->toBeNull();
    expect($data->windDirection)->toBeNull();
    expect($data->condition)->toBeNull();
    expect($data->alertLevel)->toBeNull();
    expect($data->alertText)->toBeNull();
});

test('throws WeatherFetchException when API returns error object', function () {
    Http::fake(['*' => Http::response(ecJsonFixture('api-error'), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;

    expect(fn () => $provider->fetch('M5V'))
        ->toThrow(WeatherFetchException::class, 'API error: OUT_OF_SERVICE_BOUNDARY');
});

test('throws WeatherFetchException when response is empty object', function () {
    Http::fake(['*' => Http::response('{}', 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;

    expect(fn () => $provider->fetch('M5V'))
        ->toThrow(WeatherFetchException::class, 'API returned empty object');
});

test('throws WeatherFetchException on non-200 response', function () {
    Http::fake(['*' => Http::response('Not Found', 404)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;

    expect(fn () => $provider->fetch('M5V'))
        ->toThrow(WeatherFetchException::class);
});

test('thrown exception carries fsa and provider name', function () {
    Http::fake(['*' => Http::response('Server Error', 500)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

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

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;

    expect(fn () => $provider->fetch('M5V'))
        ->toThrow(WeatherFetchException::class);
});

test('throws WeatherFetchException on invalid JSON', function () {
    Http::fake(['*' => Http::response('not valid json{{', 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;

    expect(fn () => $provider->fetch('M5V'))
        ->toThrow(WeatherFetchException::class, 'Invalid JSON');
});

test('throws WeatherFetchException when JSON is not an object', function () {
    Http::fake(['*' => Http::response('["array", "not", "object"]', 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;

    expect(fn () => $provider->fetch('M5V'))
        ->toThrow(WeatherFetchException::class, 'Expected JSON object');
});
