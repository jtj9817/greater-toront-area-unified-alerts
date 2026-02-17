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
];

