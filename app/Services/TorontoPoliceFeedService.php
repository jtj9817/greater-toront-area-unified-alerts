<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class TorontoPoliceFeedService
{
    protected const API_URL = 'https://services.arcgis.com/S9th0jAJ7bqgIRjw/arcgis/rest/services/C4S_Public_NoGO/FeatureServer/0/query';

    private bool $lastFetchWasPartial = false;

    /**
     * Fetch active police calls from the Toronto Police ArcGIS REST API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch(): array
    {
        $allowEmptyFeeds = (bool) config('feeds.allow_empty_feeds');
        $circuitBreaker = app(FeedCircuitBreaker::class);
        $circuitBreaker->throwIfOpen('toronto_police');

        try {
            $this->lastFetchWasPartial = false;
            $allFeatures = [];
            $resultOffset = 0;
            $resultRecordCount = 1000;
            $shouldReturnEmpty = false;
            $maxRecords = max(1, (int) config('feeds.police.max_records', 100000));

            do {
                $response = Http::timeout(15)
                    ->retry(2, 100, throw: false)
                    ->acceptJson()
                    ->get(self::API_URL, [
                        'f' => 'json',
                        'where' => '1=1',
                        'outFields' => 'OBJECTID,CALL_TYPE_CODE,CALL_TYPE,DIVISION,CROSS_STREETS,LATITUDE,LONGITUDE,OCCURRENCE_TIME',
                        'returnGeometry' => 'false',
                        'orderByFields' => 'OCCURRENCE_TIME DESC',
                        'resultOffset' => $resultOffset,
                        'resultRecordCount' => $resultRecordCount,
                    ]);

                if ($response->failed()) {
                    if ($resultOffset === 0 || $allFeatures === []) {
                        throw new RuntimeException('Failed to fetch police calls: '.$response->status());
                    }

                    $this->lastFetchWasPartial = true;

                    Log::warning('Toronto Police feed pagination failed mid-stream; returning partial results', [
                        'status' => $response->status(),
                        'result_offset' => $resultOffset,
                        'result_record_count' => $resultRecordCount,
                        'records_returned' => count($allFeatures),
                    ]);

                    break;
                }

                $data = $response->json();

                if (! isset($data['features'])) {
                    throw new RuntimeException("Unexpected API response format: 'features' key missing.");
                }

                $features = $data['features'];

                if (! is_array($features)) {
                    throw new RuntimeException("Unexpected API response format: 'features' is not an array.");
                }

                if ($resultOffset === 0 && $features === []) {
                    if ($allowEmptyFeeds) {
                        $shouldReturnEmpty = true;
                        break;
                    }

                    throw new RuntimeException('Toronto Police feed returned an empty features array on the first page');
                }

                foreach ($features as $feature) {
                    if (! is_array($feature) || ! isset($feature['attributes']) || ! is_array($feature['attributes'])) {
                        Log::warning('Skipping police feed feature with missing attributes', [
                            'result_offset' => $resultOffset,
                        ]);

                        continue;
                    }

                    try {
                        $parsed = $this->parseFeature($feature['attributes']);
                    } catch (\Throwable $exception) {
                        Log::warning('Skipping police feed feature due to parse failure', [
                            'exception' => $exception,
                            'result_offset' => $resultOffset,
                            'object_id' => $feature['attributes']['OBJECTID'] ?? null,
                        ]);

                        continue;
                    }

                    $allFeatures[] = $parsed;

                    if (count($allFeatures) > $maxRecords) {
                        throw new RuntimeException("Toronto Police feed exceeded safety limit of {$maxRecords} records");
                    }
                }

                $exceededTransferLimit = $data['exceededTransferLimit'] ?? false;

                if ($exceededTransferLimit && $features === []) {
                    $this->lastFetchWasPartial = true;

                    Log::warning('Toronto Police feed pagination returned an empty page; returning partial results', [
                        'result_offset' => $resultOffset,
                        'result_record_count' => $resultRecordCount,
                        'records_returned' => count($allFeatures),
                    ]);

                    break;
                }

                $resultOffset += $resultRecordCount;

            } while ($exceededTransferLimit);

            if ($shouldReturnEmpty) {
                $circuitBreaker->recordSuccess('toronto_police');

                return [];
            }

            if ($allFeatures === [] && ! $allowEmptyFeeds) {
                throw new RuntimeException('Toronto Police feed returned zero records');
            }

            $circuitBreaker->recordSuccess('toronto_police');

            return $allFeatures;
        } catch (Throwable $exception) {
            $circuitBreaker->recordFailure('toronto_police', $exception);
            throw $exception;
        }
    }

    public function lastFetchWasPartial(): bool
    {
        return $this->lastFetchWasPartial;
    }

    /**
     * Parse a single feature attribute array into a normalized format.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function parseFeature(array $attributes): array
    {
        $occurrenceTime = Carbon::createFromTimestampMs($attributes['OCCURRENCE_TIME']);
        app(FeedDataSanity::class)->warnIfFutureTimestamp(
            timestamp: $occurrenceTime,
            source: 'toronto_police',
            field: 'occurrence_time',
            context: ['object_id' => $attributes['OBJECTID'] ?? null],
        );

        $latitude = is_numeric($attributes['LATITUDE'] ?? null) ? (float) $attributes['LATITUDE'] : null;
        $longitude = is_numeric($attributes['LONGITUDE'] ?? null) ? (float) $attributes['LONGITUDE'] : null;

        app(FeedDataSanity::class)->warnIfCoordinatesOutsideGta(
            lat: $latitude,
            lng: $longitude,
            source: 'toronto_police',
            context: ['object_id' => $attributes['OBJECTID'] ?? null],
        );

        return [
            'object_id' => $attributes['OBJECTID'],
            'call_type_code' => trim($attributes['CALL_TYPE_CODE']),
            'call_type' => trim($attributes['CALL_TYPE']),
            'division' => trim($attributes['DIVISION']) ?: null,
            'cross_streets' => trim($attributes['CROSS_STREETS']) ?: null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'occurrence_time' => $occurrenceTime,
        ];
    }
}
