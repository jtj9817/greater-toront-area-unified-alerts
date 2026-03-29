<?php

namespace App\Services\Weather\Providers;

use App\Models\GtaPostalCode;
use App\Services\Weather\Contracts\WeatherProvider;
use App\Services\Weather\DTOs\WeatherData;
use App\Services\Weather\Exceptions\WeatherFetchException;
use App\Services\Weather\FeelsLikeCalculator;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

class EnvironmentCanadaWeatherProvider implements WeatherProvider
{
    public const string NAME = 'environment_canada';

    public function name(): string
    {
        return self::NAME;
    }

    public function fetch(string $fsa): WeatherData
    {
        $coords = $this->resolveCoordinates($fsa);
        $url = $this->buildApiUrl($coords['lat'], $coords['lng']);
        $timeoutSeconds = (int) config('weather.timeout_seconds', 10);

        try {
            $response = Http::timeout($timeoutSeconds)->get($url);
        } catch (ConnectionException $e) {
            throw new WeatherFetchException($fsa, self::NAME, 'HTTP connection failed: '.$e->getMessage(), $e);
        } catch (Throwable $e) {
            throw new WeatherFetchException($fsa, self::NAME, 'HTTP request error: '.$e->getMessage(), $e);
        }

        if ($response->failed()) {
            throw new WeatherFetchException(
                $fsa,
                self::NAME,
                "Provider returned HTTP {$response->status()}",
            );
        }

        $body = $response->body();

        if (trim($body) === '') {
            throw new WeatherFetchException($fsa, self::NAME, 'Provider returned an empty response body');
        }

        return $this->parseJson($fsa, $body);
    }

    private function resolveCoordinates(string $fsa): array
    {
        $normalizedFsa = GtaPostalCode::normalize($fsa);
        $postalCode = GtaPostalCode::where('fsa', $normalizedFsa)->first();

        if ($postalCode) {
            return ['lat' => $postalCode->lat, 'lng' => $postalCode->lng];
        }

        return [
            'lat' => (float) config('weather.environment_canada.default_coords.lat', 43.6532),
            'lng' => (float) config('weather.environment_canada.default_coords.lng', -79.3832),
        ];
    }

    private function buildApiUrl(float $lat, float $lng): string
    {
        $baseUrl = rtrim((string) config('weather.environment_canada.base_url', 'https://weather.gc.ca'), '/');
        $apiPath = config('weather.environment_canada.api_path', '/api/app/v3/en/Location');

        return "{$baseUrl}{$apiPath}/{$lat},{$lng}?type=city";
    }

    private function parseJson(string $fsa, string $json): WeatherData
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new WeatherFetchException($fsa, self::NAME, 'Invalid JSON response: '.json_last_error_msg());
        }

        if (! is_array($data)) {
            throw new WeatherFetchException($fsa, self::NAME, 'Expected JSON object, got '.gettype($data));
        }

        if (empty($data)) {
            throw new WeatherFetchException($fsa, self::NAME, 'API returned empty object');
        }

        $payload = $data;

        // Environment Canada currently returns a top-level list with one payload object.
        if (array_is_list($data)) {
            $first = $data[0] ?? null;

            if (! is_array($first)) {
                throw new WeatherFetchException(
                    $fsa,
                    self::NAME,
                    'Expected JSON object at array index 0, got '.gettype($first),
                );
            }

            $payload = $first;
        }

        if (empty($payload)) {
            throw new WeatherFetchException($fsa, self::NAME, 'API returned empty object');
        }

        if (isset($payload['error']) && is_string($payload['error'])) {
            throw new WeatherFetchException($fsa, self::NAME, "API error: {$payload['error']}");
        }

        if (isset($payload['error']) && is_array($payload['error'])) {
            $errorMsg = $payload['error']['message'] ?? json_encode($payload['error']);
            throw new WeatherFetchException($fsa, self::NAME, "API error: {$errorMsg}");
        }

        $observation = $payload['observation'] ?? [];
        $alert = $payload['alert'] ?? [];
        $alertLevel = $this->parseAlertLevel($alert);

        $temperature = $this->parseTemperature($observation);
        $dewpoint = $this->parseDewpoint($observation);
        $windKph = $this->parseWindSpeedKph($observation);

        return new WeatherData(
            fsa: $fsa,
            provider: self::NAME,
            temperature: $temperature,
            humidity: $this->parseHumidity($observation),
            windSpeed: $this->formatWindSpeed($windKph),
            windDirection: $this->parseWindDirection($observation),
            condition: $this->parseCondition($observation),
            alertLevel: $alertLevel,
            alertText: $this->parseAlertText($alert, $alertLevel),
            fetchedAt: new DateTimeImmutable,
            feelsLike: FeelsLikeCalculator::compute($temperature, $windKph, $dewpoint),
            dewpoint: $dewpoint,
            pressure: $this->parsePressure($observation),
            visibility: $this->parseVisibility($observation),
            windGust: $this->parseWindGust($observation),
            tendency: $this->parseTendency($observation),
            stationName: $this->parseObservedAt($observation),
        );
    }

    private function parseTemperature(array $observation): ?float
    {
        if (! isset($observation['temperature'])) {
            return null;
        }

        $temp = $observation['temperature'];

        if (isset($temp['metricUnrounded']) && is_numeric($temp['metricUnrounded'])) {
            return (float) $temp['metricUnrounded'];
        }

        if (isset($temp['metric']) && is_numeric($temp['metric'])) {
            return (float) $temp['metric'];
        }

        return null;
    }

    private function parseHumidity(array $observation): ?float
    {
        if (! isset($observation['humidity']) || ! is_numeric($observation['humidity'])) {
            return null;
        }

        return (float) $observation['humidity'];
    }

    private function parseWindDirection(array $observation): ?string
    {
        $direction = $observation['windDirection'] ?? null;

        if ($direction === null || $direction === '') {
            return null;
        }

        return (string) $direction;
    }

    /** Returns the raw numeric wind speed in km/h, or null if unavailable. */
    private function parseWindSpeedKph(array $observation): ?float
    {
        if (! isset($observation['windSpeed']) || ! is_array($observation['windSpeed'])) {
            return null;
        }

        $speed = $observation['windSpeed'];

        if (isset($speed['metric']) && is_numeric($speed['metric'])) {
            return (float) $speed['metric'];
        }

        return null;
    }

    /** Formats a raw km/h value as "N km/h", or returns null when unavailable. */
    private function formatWindSpeed(?float $kph): ?string
    {
        if ($kph === null) {
            return null;
        }

        return $kph.' km/h';
    }

    private function parseDewpoint(array $observation): ?float
    {
        if (! isset($observation['dewpoint'])) {
            return null;
        }

        $dew = $observation['dewpoint'];

        if (isset($dew['metricUnrounded']) && is_numeric($dew['metricUnrounded'])) {
            return (float) $dew['metricUnrounded'];
        }

        if (isset($dew['metric']) && is_numeric($dew['metric'])) {
            return (float) $dew['metric'];
        }

        return null;
    }

    private function parsePressure(array $observation): ?float
    {
        if (! isset($observation['pressure'])) {
            return null;
        }

        $pressure = $observation['pressure'];

        if (isset($pressure['metric']) && is_numeric($pressure['metric'])) {
            return (float) $pressure['metric'];
        }

        return null;
    }

    private function parseVisibility(array $observation): ?float
    {
        if (! isset($observation['visibility'])) {
            return null;
        }

        $vis = $observation['visibility'];

        if (isset($vis['metric']) && is_numeric($vis['metric'])) {
            return (float) $vis['metric'];
        }

        return null;
    }

    private function parseWindGust(array $observation): ?string
    {
        if (! isset($observation['windGust']) || ! is_array($observation['windGust'])) {
            return null;
        }

        $gust = $observation['windGust'];

        if (isset($gust['metric']) && is_numeric($gust['metric'])) {
            return $gust['metric'].' km/h';
        }

        return null;
    }

    private function parseTendency(array $observation): ?string
    {
        $tendency = $observation['tendency'] ?? null;

        if (! is_string($tendency) || trim($tendency) === '') {
            return null;
        }

        return trim($tendency);
    }

    private function parseObservedAt(array $observation): ?string
    {
        $station = $observation['observedAt'] ?? null;

        if (! is_string($station) || trim($station) === '') {
            return null;
        }

        return trim($station);
    }

    private function parseCondition(array $observation): ?string
    {
        $condition = $observation['condition'] ?? '';

        if (! is_string($condition) || trim($condition) === '') {
            return null;
        }

        return trim($condition);
    }

    private function parseAlertLevel(array $alert): ?string
    {
        if (empty($alert)) {
            return null;
        }

        $mostSevere = $alert['mostSevere'] ?? null;

        if (! is_string($mostSevere)) {
            return null;
        }

        $normalized = strtolower($mostSevere);

        if (in_array($normalized, ['yellow', 'orange', 'red'], true)) {
            return $normalized;
        }

        return null;
    }

    private function parseAlertText(array $alert, ?string $alertLevel): ?string
    {
        if ($alertLevel === null) {
            return null;
        }

        if (empty($alert)) {
            return null;
        }

        $alerts = $alert['alerts'] ?? [];

        if (! is_array($alerts) || count($alerts) === 0) {
            return null;
        }

        $first = $alerts[0];

        if (! is_array($first)) {
            return null;
        }

        if (isset($first['bannerText']) && is_string($first['bannerText']) && trim($first['bannerText']) !== '') {
            return trim($first['bannerText']);
        }

        if (isset($first['alertHeaderText']) && is_string($first['alertHeaderText']) && trim($first['alertHeaderText']) !== '') {
            return trim($first['alertHeaderText']);
        }

        return null;
    }
}
