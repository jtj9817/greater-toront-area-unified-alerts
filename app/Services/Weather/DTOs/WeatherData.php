<?php

namespace App\Services\Weather\DTOs;

use DateTimeImmutable;

readonly class WeatherData
{
    public function __construct(
        public string $fsa,
        public string $provider,
        public ?float $temperature,
        public ?float $humidity,
        public ?string $windSpeed,
        public ?string $windDirection,
        public ?string $condition,
        /** Parsed alert severity: null, 'yellow', 'orange', or 'red'. */
        public ?string $alertLevel,
        public ?string $alertText,
        public DateTimeImmutable $fetchedAt,
        /** Apparent temperature in °C using Wind Chill or Humidex formula. */
        public ?float $feelsLike = null,
        /** Dewpoint temperature in °C. */
        public ?float $dewpoint = null,
        /** Atmospheric pressure in kPa. */
        public ?float $pressure = null,
        /** Visibility in km. */
        public ?float $visibility = null,
        /** Wind gust speed formatted as "N km/h". */
        public ?string $windGust = null,
        /** Pressure tendency, e.g. "falling" or "rising". */
        public ?string $tendency = null,
        /** Name of the observation station. */
        public ?string $stationName = null,
    ) {}
}
