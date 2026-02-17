<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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

        $this->lastFetchWasPartial = false;
        $allFeatures = [];
        $resultOffset = 0;
        $resultRecordCount = 1000;

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
                    return [];
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
                    $allFeatures[] = $this->parseFeature($feature['attributes']);
                } catch (\Throwable $exception) {
                    Log::warning('Skipping police feed feature due to parse failure', [
                        'exception' => $exception,
                        'result_offset' => $resultOffset,
                        'object_id' => $feature['attributes']['OBJECTID'] ?? null,
                    ]);
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

        if ($allFeatures === [] && ! $allowEmptyFeeds) {
            throw new RuntimeException('Toronto Police feed returned zero records');
        }

        return $allFeatures;
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
        return [
            'object_id' => $attributes['OBJECTID'],
            'call_type_code' => trim($attributes['CALL_TYPE_CODE']),
            'call_type' => trim($attributes['CALL_TYPE']),
            'division' => trim($attributes['DIVISION']) ?: null,
            'cross_streets' => trim($attributes['CROSS_STREETS']) ?: null,
            'latitude' => $attributes['LATITUDE'] ?: null,
            'longitude' => $attributes['LONGITUDE'] ?: null,
            'occurrence_time' => Carbon::createFromTimestampMs($attributes['OCCURRENCE_TIME']),
        ];
    }
}
