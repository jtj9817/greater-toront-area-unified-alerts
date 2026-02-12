<?php

use App\Models\TorontoAddress;
use App\Models\TorontoPointOfInterest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('toronto geospatial import command ingests addresses and pois from local files', function () {
    $addressesPath = sys_get_temp_dir().'/addr_'.uniqid('', true).'.csv';
    $poisPath = sys_get_temp_dir().'/poi_'.uniqid('', true).'.json';

    $addressesCsv = implode("\n", [
        'street_num,street_name,lat,long,zip',
        '100,Queen St W,43.6531,-79.3840,M5H 2N2',
        '200,King St W,43.6487,-79.3854,M5V 1J5',
    ]);

    $poisJson = json_encode([
        ['name' => 'CN Tower', 'category' => 'Landmark', 'lat' => 43.6426, 'long' => -79.3871],
        ['name' => 'Union Station', 'category' => 'Transit', 'lat' => 43.6452, 'long' => -79.3806],
    ], JSON_THROW_ON_ERROR);

    file_put_contents($addressesPath, $addressesCsv);
    file_put_contents($poisPath, $poisJson);

    try {
        $this->artisan('data:import-toronto-geospatial', [
            '--addresses' => $addressesPath,
            '--pois' => $poisPath,
            '--truncate' => true,
        ])->assertExitCode(0);

        expect(TorontoAddress::query()->count())->toBe(2);
        expect(TorontoPointOfInterest::query()->count())->toBe(2);
    } finally {
        @unlink($addressesPath);
        @unlink($poisPath);
    }
});
