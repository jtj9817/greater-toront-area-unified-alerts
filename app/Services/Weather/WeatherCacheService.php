<?php

namespace App\Services\Weather;

use App\Models\WeatherCache;
use App\Services\Weather\DTOs\WeatherData;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;

class WeatherCacheService
{
    private const string FAST_CACHE_KEY_PREFIX = 'weather.current.';

    public function __construct(
        private readonly WeatherFetchService $fetchService,
    ) {}

    /**
     * Resolve current weather for an FSA using a two-layer cache strategy:
     *
     *  1. Fast cache  (Laravel Cache, default driver)
     *  2. Durable cache (WeatherCache DB table)
     *  3. Upstream     (WeatherFetchService)
     *
     * @throws \App\Services\Weather\Exceptions\WeatherFetchException When all upstream providers fail.
     */
    public function get(string $fsa): WeatherData
    {
        $fastKey = self::FAST_CACHE_KEY_PREFIX.$fsa;

        // --- Layer 1: fast cache ---
        $cached = Cache::get($fastKey);

        if ($cached instanceof WeatherData) {
            return $cached;
        }

        // --- Layer 2: durable DB cache ---
        foreach ($this->fetchService->getProviders() as $provider) {
            $dbEntry = WeatherCache::findValid($fsa, $provider->name());

            if ($dbEntry !== null) {
                $data = $this->hydrateFromDbCache($dbEntry);
                Cache::put($fastKey, $data, now()->addMinutes(WeatherCache::TTL_MINUTES));

                return $data;
            }
        }

        // --- Layer 3: upstream fetch ---
        $data = $this->fetchService->fetch($fsa);

        WeatherCache::create([
            'fsa' => $fsa,
            'provider' => $data->provider,
            'payload' => $this->toPayload($data),
            'fetched_at' => $data->fetchedAt,
        ]);

        Cache::put($fastKey, $data, now()->addMinutes(WeatherCache::TTL_MINUTES));

        return $data;
    }

    private function hydrateFromDbCache(WeatherCache $entry): WeatherData
    {
        $p = $entry->payload;

        return new WeatherData(
            fsa: $entry->fsa,
            provider: $entry->provider,
            temperature: isset($p['temperature']) ? (float) $p['temperature'] : null,
            humidity: isset($p['humidity']) ? (float) $p['humidity'] : null,
            windSpeed: $p['wind_speed'] ?? null,
            windDirection: $p['wind_direction'] ?? null,
            condition: $p['condition'] ?? null,
            alertLevel: $p['alert_level'] ?? null,
            alertText: $p['alert_text'] ?? null,
            fetchedAt: new DateTimeImmutable($p['fetched_at']),
        );
    }

    private function toPayload(WeatherData $data): array
    {
        return [
            'temperature' => $data->temperature,
            'humidity' => $data->humidity,
            'wind_speed' => $data->windSpeed,
            'wind_direction' => $data->windDirection,
            'condition' => $data->condition,
            'alert_level' => $data->alertLevel,
            'alert_text' => $data->alertText,
            'fetched_at' => $data->fetchedAt->format(DATE_ATOM),
        ];
    }
}
