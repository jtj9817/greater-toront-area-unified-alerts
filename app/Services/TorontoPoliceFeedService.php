<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TorontoPoliceFeedService
{
    protected const API_URL = 'https://services.arcgis.com/S9th0jAJ7bqgIRjw/arcgis/rest/services/C4S_Public_NoGO/FeatureServer/0/query';

    /**
     * Fetch active police calls from the Toronto Police ArcGIS REST API.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetch(): array
    {
        $allFeatures = [];
        $resultOffset = 0;
        $resultRecordCount = 1000;

        do {
            $response = Http::timeout(15)
                ->retry(2, 100)
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
                throw new RuntimeException("Failed to fetch police calls: " . $response->status());
            }

            $data = $response->json();

            if (!isset($data['features'])) {
                throw new RuntimeException("Unexpected API response format: 'features' key missing.");
            }

            foreach ($data['features'] as $feature) {
                $allFeatures[] = $this->parseFeature($feature['attributes']);
            }

            $exceededTransferLimit = $data['exceededTransferLimit'] ?? false;
            $resultOffset += $resultRecordCount;

        } while ($exceededTransferLimit);

        return $allFeatures;
    }

    /**
     * Parse a single feature attribute array into a normalized format.
     *
     * @param array<string, mixed> $attributes
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
