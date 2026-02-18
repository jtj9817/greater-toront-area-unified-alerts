<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class FeedDataSanity
{
    /**
     * Warn (but do not fail) when a timestamp that should be "near now" is
     * unexpectedly far in the future. This catches clock skew and upstream
     * data corruption without blocking ingestion.
     *
     * @param  array<string, mixed>  $context
     */
    public function warnIfFutureTimestamp(CarbonInterface $timestamp, string $source, string $field, array $context = []): void
    {
        $graceSeconds = max(0, (int) config('feeds.sanity.future_timestamp_grace_seconds', 900));
        $now = Carbon::now('UTC');
        $futureThreshold = $now->copy()->addSeconds($graceSeconds);

        if ($timestamp->greaterThan($futureThreshold)) {
            Log::warning('Feed record timestamp is unexpectedly in the future', array_merge($context, [
                'source' => $source,
                'field' => $field,
                'timestamp' => $timestamp->toIso8601String(),
                'now' => $now->toIso8601String(),
                'grace_seconds' => $graceSeconds,
            ]));
        }
    }

    /**
     * Warn (but do not fail) when coordinates fall outside a GTA bounding box.
     *
     * @param  array<string, mixed>  $context
     */
    public function warnIfCoordinatesOutsideGta(?float $lat, ?float $lng, string $source, array $context = []): void
    {
        if ($lat === null || $lng === null) {
            return;
        }

        $bounds = config('feeds.sanity.gta_bounds', []);
        if (! is_array($bounds)) {
            $bounds = [];
        }
        $minLat = is_numeric($bounds['min_lat'] ?? null) ? (float) $bounds['min_lat'] : 43.0;
        $maxLat = is_numeric($bounds['max_lat'] ?? null) ? (float) $bounds['max_lat'] : 44.5;
        $minLng = is_numeric($bounds['min_lng'] ?? null) ? (float) $bounds['min_lng'] : -80.5;
        $maxLng = is_numeric($bounds['max_lng'] ?? null) ? (float) $bounds['max_lng'] : -78.0;

        if ($lat < $minLat || $lat > $maxLat || $lng < $minLng || $lng > $maxLng) {
            Log::warning('Feed record coordinates fall outside GTA bounds', array_merge($context, [
                'source' => $source,
                'latitude' => $lat,
                'longitude' => $lng,
                'bounds' => [
                    'min_lat' => $minLat,
                    'max_lat' => $maxLat,
                    'min_lng' => $minLng,
                    'max_lng' => $maxLng,
                ],
            ]));
        }
    }
}
