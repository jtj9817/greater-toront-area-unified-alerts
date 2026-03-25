<?php

namespace App\Services\Weather\Providers;

use App\Services\Weather\Contracts\WeatherProvider;
use App\Services\Weather\DTOs\WeatherData;
use App\Services\Weather\Exceptions\WeatherFetchException;
use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
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
        $stationId = $this->resolveStation($fsa);
        $baseUrl = rtrim((string) config('weather.environment_canada.base_url', 'https://weather.gc.ca'), '/');
        $url = "{$baseUrl}/city/pages/{$stationId}_metric_e.html";
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

        $html = $response->body();

        if (trim($html) === '') {
            throw new WeatherFetchException($fsa, self::NAME, 'Provider returned an empty response body');
        }

        return $this->parseHtml($fsa, $html);
    }

    private function resolveStation(string $fsa): string
    {
        $prefix = strtoupper(substr($fsa, 0, 2));
        /** @var array<string,string> $map */
        $map = config('weather.environment_canada.station_map', []);

        return $map[$prefix] ?? (string) config('weather.environment_canada.default_station', 'on-143');
    }

    private function parseHtml(string $fsa, string $html): WeatherData
    {
        $doc = new DOMDocument;

        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($doc);

        [$alertLevel, $alertText] = $this->parseAlert($xpath);

        return new WeatherData(
            fsa: $fsa,
            provider: self::NAME,
            temperature: $this->parseTemperature($xpath),
            humidity: $this->parseHumidity($xpath),
            windSpeed: $this->parseWindSpeed($xpath),
            windDirection: $this->parseWindDirection($xpath),
            condition: $this->parseCondition($xpath),
            alertLevel: $alertLevel,
            alertText: $alertText,
            fetchedAt: new DateTimeImmutable,
        );
    }

    private function parseCondition(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query("//div[@id='currentconditions']/p[@id='cond']");

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $text = trim($nodes->item(0)->textContent);

        return $text !== '' ? $text : null;
    }

    private function parseTemperature(DOMXPath $xpath): ?float
    {
        $nodes = $xpath->query("//div[@id='currentconditions']//abbr[@class='temperature']");

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $title = trim($nodes->item(0)->getAttribute('title'));

        if ($title === '') {
            return null;
        }

        $value = filter_var($title, FILTER_VALIDATE_FLOAT);

        return $value !== false ? $value : null;
    }

    private function parseHumidity(DOMXPath $xpath): ?float
    {
        $nodes = $xpath->query(
            "//div[@id='currentconditions']//dt[contains(text(), 'Humidity:')]/following-sibling::dd[1]"
        );

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $text = preg_replace('/[^0-9.]/', '', $nodes->item(0)->textContent) ?? '';

        if ($text === '') {
            return null;
        }

        $value = filter_var($text, FILTER_VALIDATE_FLOAT);

        return $value !== false ? $value : null;
    }

    /**
     * Parse the raw wind text (e.g. "NW 20 km/h") and return the speed portion.
     * Returns "0 km/h" when the value is "Calm".
     */
    private function parseWindSpeed(DOMXPath $xpath): ?string
    {
        $text = $this->rawWindText($xpath);

        if ($text === null) {
            return null;
        }

        if (strtolower(trim($text)) === 'calm') {
            return '0 km/h';
        }

        // Expected format: "DIRECTION SPEED_WITH_UNIT" e.g. "NW 20 km/h"
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        if (preg_match('/^[A-Z]+\s+(.+)$/i', trim($text), $m)) {
            return trim($m[1]);
        }

        return $text;
    }

    /**
     * Parse the raw wind text and return the direction portion.
     * Returns "Calm" when the value is "Calm".
     */
    private function parseWindDirection(DOMXPath $xpath): ?string
    {
        $text = $this->rawWindText($xpath);

        if ($text === null) {
            return null;
        }

        if (strtolower(trim($text)) === 'calm') {
            return 'Calm';
        }

        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        if (preg_match('/^([A-Z]+)\s+/i', trim($text), $m)) {
            return trim($m[1]);
        }

        return null;
    }

    private function rawWindText(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query(
            "//div[@id='currentconditions']//dt[contains(text(), 'Wind:')]/following-sibling::dd[1]"
        );

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $text = trim($nodes->item(0)->textContent);

        return $text !== '' ? $text : null;
    }

    /**
     * Return [alertLevel, alertText] where level is 'red', 'orange', 'yellow', or null.
     * Checks in descending severity order so the highest active level wins.
     *
     * @return array{?string, ?string}
     */
    private function parseAlert(DOMXPath $xpath): array
    {
        foreach (['red', 'orange', 'yellow'] as $level) {
            $nodes = $xpath->query(
                "//div[@id='warnings']//*[contains(@class, 'alert-{$level}')]"
            );

            if ($nodes !== false && $nodes->length > 0) {
                $text = trim($nodes->item(0)->textContent);

                return [$level, $text !== '' ? $text : null];
            }
        }

        return [null, null];
    }
}
