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
    ) {}
}
