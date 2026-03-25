<?php

namespace App\Services\Weather;

use App\Services\Weather\Contracts\WeatherProvider;
use App\Services\Weather\DTOs\WeatherData;
use App\Services\Weather\Exceptions\WeatherFetchException;
use Illuminate\Support\Facades\Log;

class WeatherFetchService
{
    /**
     * @param WeatherProvider[] $providers Ordered list; first that succeeds wins.
     */
    public function __construct(
        private readonly array $providers,
    ) {}

    /**
     * Fetch current weather for the given FSA, trying providers in order.
     *
     * @throws WeatherFetchException When every provider fails.
     */
    public function fetch(string $fsa): WeatherData
    {
        $lastException = null;

        foreach ($this->providers as $provider) {
            try {
                return $provider->fetch($fsa);
            } catch (WeatherFetchException $e) {
                $lastException = $e;

                Log::warning("Weather provider '{$provider->name()}' failed for FSA '{$fsa}'", [
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        throw new WeatherFetchException(
            fsa: $fsa,
            provider: 'none',
            reason: 'All weather providers failed',
            previous: $lastException,
        );
    }

    /**
     * @return WeatherProvider[]
     */
    public function getProviders(): array
    {
        return $this->providers;
    }
}
