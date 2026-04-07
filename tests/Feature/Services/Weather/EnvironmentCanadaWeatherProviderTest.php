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

test('parses list-root payload by using first item', function () {
    $payload = json_encode([json_decode(ecJsonFixture('no-alert'), true)]);
    Http::fake(['*' => Http::response($payload, 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->temperature)->toBe(22.456);
    expect($data->humidity)->toBe(65.0);
    expect($data->windSpeed)->toBe('20 km/h');
    expect($data->windDirection)->toBe('NW');
    expect($data->condition)->toBe('Mostly Cloudy');
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

// ---------------------------------------------------------------------------
// New extended fields
// ---------------------------------------------------------------------------

test('parses dewpoint, pressure, visibility, wind gust, tendency, and observed-at from no-alert payload', function () {
    Http::fake(['*' => Http::response(ecJsonFixture('no-alert'), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->dewpoint)->toBe(15.0);
    expect($data->pressure)->toBe(101.3);
    expect($data->visibility)->toBe(24.0);
    expect($data->windGust)->toBe('32 km/h');
    expect($data->tendency)->toBe('falling');
    expect($data->stationName)->toBe("Toronto Pearson Int'l Airport");
});

test('computes humidex feels-like for warm temperature with dewpoint', function () {
    Http::fake(['*' => Http::response(ecJsonFixture('no-alert'), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    // T=22.456°C ≥ 20 and Td=15.0°C → Humidex applies
    expect($data->feelsLike)->toBeFloat()->toBeBetween(25.0, 28.0);
});

test('computes wind chill feels-like for cold temperature with wind', function () {
    $payload = json_decode(ecJsonFixture('no-alert'), true);
    $payload['observation']['temperature']['metricUnrounded'] = -5.0;
    $payload['observation']['temperature']['metric'] = -5;
    $payload['observation']['windSpeed']['metric'] = 30;

    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    // T=-5°C ≤ 10 and W=30 km/h > 4.8 → Wind Chill applies, result colder than actual
    expect($data->feelsLike)->toBeFloat()->toBeLessThan(-5.0);
});

test('feels-like returns actual temperature in neutral range when no formula applies', function () {
    $payload = json_decode(ecJsonFixture('no-alert'), true);
    $payload['observation']['temperature']['metricUnrounded'] = 15.0;
    $payload['observation']['temperature']['metric'] = 15;

    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->feelsLike)->toBe(15.0);
});

test('new fields are null when not present in observation payload', function () {
    $payload = ['observation' => ['temperature' => ['metricUnrounded' => 5.0], 'humidity' => 50], 'alert' => []];

    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->dewpoint)->toBeNull();
    expect($data->pressure)->toBeNull();
    expect($data->visibility)->toBeNull();
    expect($data->windGust)->toBeNull();
    expect($data->tendency)->toBeNull();
    expect($data->stationName)->toBeNull();
});

// ============================================================================
// Phase 5: Failure Modes + Edge Parsing
// ============================================================================

// --- Task 1: Empty response body ---

test('empty response body throws WeatherFetchException', function () {
    Http::fake(['*' => Http::response('', 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;

    expect(fn () => $provider->fetch('M5V'))
        ->toThrow(WeatherFetchException::class, 'empty response body');
});

// --- Task 2: Generic Throwable wrapped as WeatherFetchException ---

test('generic throwable during HTTP request is wrapped as WeatherFetchException', function () {
    Http::fake(['*' => function () {
        throw new RuntimeException('underlying transport failure');
    }]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;

    expect(fn () => $provider->fetch('M5V'))
        ->toThrow(WeatherFetchException::class, 'HTTP request error');
});

// --- Task 3: Non-2xx failure includes status code ---

test('non-2xx failure message includes status code', function () {
    Http::fake(['*' => Http::response('Service Unavailable', 503)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;

    expect(fn () => $provider->fetch('M5V'))
        ->toThrow(WeatherFetchException::class, 'HTTP 503');
});

// --- Task 4: Alert parsing edge cases ---

test('alert with missing mostSevere key returns null alert level', function () {
    $payload = json_decode(ecJsonFixture('yellow-alert'), true);
    unset($payload['alert']['mostSevere']);
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->alertLevel)->toBeNull();
    expect($data->alertText)->toBeNull();
});

test('alert with non-string mostSevere returns null alert level', function () {
    $payload = json_decode(ecJsonFixture('yellow-alert'), true);
    $payload['alert']['mostSevere'] = 42;
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->alertLevel)->toBeNull();
    expect($data->alertText)->toBeNull();
});

test('alert with empty alerts array returns null alert text', function () {
    $payload = json_decode(ecJsonFixture('yellow-alert'), true);
    $payload['alert']['alerts'] = [];
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->alertLevel)->toBe('yellow');
    expect($data->alertText)->toBeNull();
});

test('alert with non-array first alerts entry returns null alert text', function () {
    $payload = json_decode(ecJsonFixture('yellow-alert'), true);
    $payload['alert']['alerts'] = ['not an array'];
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->alertLevel)->toBe('yellow');
    expect($data->alertText)->toBeNull();
});

test('alert with empty bannerText and alertHeaderText returns null alert text', function () {
    $payload = json_decode(ecJsonFixture('yellow-alert'), true);
    $payload['alert']['alerts'][0]['bannerText'] = '';
    $payload['alert']['alerts'][0]['alertHeaderText'] = '';
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->alertLevel)->toBe('yellow');
    expect($data->alertText)->toBeNull();
});

/*
 * Phase 8: EnvironmentCanadaWeatherProvider Remaining Branch Coverage
 *
 * Gap lines targeted: 87, 112, 120-121, 156, 230-234, 249, 264, 279.
 */

// Line 87: JSON decodes to a non-array scalar (e.g. a bare number)
test('throws WeatherFetchException when JSON decodes to a scalar value', function () {
    Http::fake(['*' => Http::response('42', 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;

    expect(fn () => $provider->fetch('M5V'))
        ->toThrow(WeatherFetchException::class, 'Expected JSON object, got integer');
});

// Line 112: List payload with empty first element
test('throws WeatherFetchException when list payload has empty first element', function () {
    Http::fake(['*' => Http::response('[{}]', 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;

    expect(fn () => $provider->fetch('M5V'))
        ->toThrow(WeatherFetchException::class, 'API returned empty object');
});

// Lines 120-121: Error field is an array (not a string)
test('throws WeatherFetchException when API error field is an array with message', function () {
    $payload = ['error' => ['message' => 'Service temporarily unavailable', 'code' => 503]];
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;

    expect(fn () => $provider->fetch('M5V'))
        ->toThrow(WeatherFetchException::class, 'API error: Service temporarily unavailable');
});

// Line 156: Missing temperature key in observation
test('returns null temperature when observation lacks temperature key', function () {
    $payload = ['observation' => ['humidity' => 50], 'alert' => []];
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->temperature)->toBeNull();
    expect($data->feelsLike)->toBeNull();
});

// Lines 230-234: Dewpoint uses metric fallback when metricUnrounded is absent
test('uses metric fallback for dewpoint when metricUnrounded is absent', function () {
    $payload = json_decode(ecJsonFixture('no-alert'), true);
    unset($payload['observation']['dewpoint']['metricUnrounded']);
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->dewpoint)->toBe(15.0);
});

// Line 249: Pressure returns null when metric key is absent
test('returns null pressure when metric key is absent', function () {
    $payload = json_decode(ecJsonFixture('no-alert'), true);
    unset($payload['observation']['pressure']['metric']);
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->pressure)->toBeNull();
});

// Line 264: Visibility returns null when metric key is absent
test('returns null visibility when metric key is absent', function () {
    $payload = json_decode(ecJsonFixture('no-alert'), true);
    unset($payload['observation']['visibility']['metric']);
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->visibility)->toBeNull();
});

// Line 279: Wind gust returns null when metric key is absent
test('returns null wind gust when metric key is absent', function () {
    $payload = json_decode(ecJsonFixture('no-alert'), true);
    unset($payload['observation']['windGust']['metric']);
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->windGust)->toBeNull();
});

// Line 234: Dewpoint returns null when both metric fields are absent
test('returns null dewpoint when both metricUnrounded and metric fields are absent', function () {
    $payload = json_decode(ecJsonFixture('no-alert'), true);
    unset($payload['observation']['dewpoint']['metricUnrounded']);
    unset($payload['observation']['dewpoint']['metric']);
    Http::fake(['*' => Http::response(json_encode($payload), 200)]);

    GtaPostalCode::firstOrCreate(['fsa' => 'M5V'], ['municipality' => 'Toronto', 'neighbourhood' => 'Liberty Village', 'lat' => 43.6406, 'lng' => -79.3961]);

    $provider = new EnvironmentCanadaWeatherProvider;
    $data = $provider->fetch('M5V');

    expect($data->dewpoint)->toBeNull();
});
