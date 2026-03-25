<?php

namespace App\Services\Weather\Contracts;

use App\Services\Weather\DTOs\WeatherData;
use App\Services\Weather\Exceptions\WeatherFetchException;

interface WeatherProvider
{
    /**
     * Fetch current weather conditions for the given FSA.
     *
     * @throws WeatherFetchException When the provider cannot fulfil the request.
     */
    public function fetch(string $fsa): WeatherData;

    /**
     * Return the stable machine-readable provider name (e.g. 'environment_canada').
     */
    public function name(): string;
}
