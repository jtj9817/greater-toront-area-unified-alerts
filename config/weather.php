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
         * Maps the first two characters of an FSA to an EC Ontario city-page station ID.
         * FSAs not in this table fall back to 'default_station'.
         */
        'station_map' => [
            // Toronto (M-prefix)
            'M1' => 'on-143', 'M2' => 'on-143', 'M3' => 'on-143',
            'M4' => 'on-143', 'M5' => 'on-143', 'M6' => 'on-143',
            'M7' => 'on-143', 'M8' => 'on-143', 'M9' => 'on-143',
            // GTA surroundings (L-prefix)
            'L3' => 'on-143',
            'L4' => 'on-143',
            'L5' => 'on-113', // Mississauga
            'L6' => 'on-27',  // Brampton / Burlington
            'L7' => 'on-143',
        ],

        'default_station' => env('WEATHER_EC_DEFAULT_STATION', 'on-143'),
    ],
];
