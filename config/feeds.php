<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Feed Resilience
    |--------------------------------------------------------------------------
    |
    | When false, ingestion will throw on "empty feed" responses to prevent
    | mass-deactivation if an upstream API returns an empty payload during an
    | outage or partial failure.
    |
    */
    'allow_empty_feeds' => env('ALLOW_EMPTY_FEEDS', false),

    'circuit_breaker' => [
        'enabled' => true,
        'threshold' => 5,
        'ttl_seconds' => 300,
    ],

    'police' => [
        'max_records' => 100000,
        // If the feed's max OBJECTID divided by the DB's historic max falls below this
        // ratio, an ArcGIS layer rebuild / sequence reset is assumed and stale rows are
        // cleared before re-seeding.  Keep the value low (e.g. 0.1) to avoid false
        // positives on legitimately quiet days.
        'reset_detection_threshold' => 0.1,
    ],

    'sanity' => [
        // Warn when timestamps that should be "near now" drift too far into the future.
        'future_timestamp_grace_seconds' => 15 * 60,

        // GTA bounding box (approximate) for sanity-checking police feed coordinates.
        'gta_bounds' => [
            'min_lat' => 43.0,
            'max_lat' => 44.5,
            'min_lng' => -80.5,
            'max_lng' => -78.0,
        ],
    ],
];
