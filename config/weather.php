<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Weather Providers
    |--------------------------------------------------------------------------
    |
    | Ordered list of fully-qualified provider class names. The WeatherFetchService
    | tries each in sequence, falling back to the next on failure.
    |
    */
    'providers' => [
        App\Services\Weather\Providers\EnvironmentCanadaWeatherProvider::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP Timeout
    |--------------------------------------------------------------------------
    */
    'timeout_seconds' => env('WEATHER_TIMEOUT_SECONDS', 10),

    /*
    |--------------------------------------------------------------------------
    | Environment Canada Provider
    |--------------------------------------------------------------------------
    */
    'environment_canada' => [
        'base_url' => env('WEATHER_EC_BASE_URL', 'https://weather.gc.ca'),

        /*
         * API endpoint path (relative to base_url) for the location-based weather JSON API.
         */
        'api_path' => '/api/app/v3/en/Location',

        /*
         * Default coordinates (lat, lng) used when an FSA is not found in the
         * gta_postal_codes table. Falls back to Toronto core coordinates.
         */
        'default_coords' => [
            'lat' => env('WEATHER_EC_DEFAULT_LAT', 43.6532),
            'lng' => env('WEATHER_EC_DEFAULT_LNG', -79.3832),
        ],

        /*
         * DEPRECATED: Station-based URLs are no longer used.
         * Kept for backward compatibility during transition.
         */
        'station_map' => [],
        'default_station' => null,
    ],
];
