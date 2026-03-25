<?php

namespace App\Services\Weather\Providers;

use App\Models\GtaPostalCode;
use App\Services\Weather\Contracts\WeatherProvider;
use App\Services\Weather\DTOs\WeatherData;
use App\Services\Weather\Exceptions\WeatherFetchException;
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

        if (array_is_list($data)) {
            throw new WeatherFetchException($fsa, self::NAME, 'Expected JSON object, got array');
        }

        if (isset($data['error']) && is_string($data['error'])) {
            throw new WeatherFetchException($fsa, self::NAME, "API error: {$data['error']}");
        }

        if (isset($data['error']) && is_array($data['error'])) {
            $errorMsg = $data['error']['message'] ?? json_encode($data['error']);
            throw new WeatherFetchException($fsa, self::NAME, "API error: {$errorMsg}");
        }

        $observation = $data['observation'] ?? [];
        $alert = $data['alert'] ?? [];
        $alertLevel = $this->parseAlertLevel($alert);

        return new WeatherData(
            fsa: $fsa,
            provider: self::NAME,
            temperature: $this->parseTemperature($observation),
            humidity: $this->parseHumidity($observation),
            windSpeed: $this->parseWindSpeed($observation),
            windDirection: $this->parseWindDirection($observation),
            condition: $this->parseCondition($observation),
            alertLevel: $alertLevel,
            alertText: $this->parseAlertText($alert, $alertLevel),
            fetchedAt: new DateTimeImmutable,
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

    private function parseWindSpeed(array $observation): ?string
    {
        if (! isset($observation['windSpeed']) || ! is_array($observation['windSpeed'])) {
            return null;
        }

        $speed = $observation['windSpeed'];

        if (isset($speed['metric']) && is_numeric($speed['metric'])) {
            return $speed['metric'].' km/h';
        }

        return null;
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
