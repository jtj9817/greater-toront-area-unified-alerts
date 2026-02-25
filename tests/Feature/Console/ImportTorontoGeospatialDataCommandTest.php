<?php

use App\Models\TorontoAddress;
use App\Models\TorontoPointOfInterest;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('toronto geospatial import command truncates and ingests addresses and pois from local files', function () {
    TorontoAddress::factory()->create([
        'street_num' => '9',
        'street_name' => 'Old Address',
        'lat' => 43.7000,
        'long' => -79.3000,
        'zip' => 'M1A 1A1',
    ]);

    TorontoPointOfInterest::factory()->create([
        'name' => 'Old POI',
        'category' => 'Legacy',
        'lat' => 43.7000,
        'long' => -79.3000,
    ]);

    $addressesPath = createAddressCsvFixture([
        'street_num,street_name,lat,long,zip',
        '100,Queen St W,43.6531,-79.3840,M5H 2N2',
        '200,King St W,43.6487,-79.3854,M5V 1J5',
    ]);
    $poisPath = createPoiJsonFixture([
        ['name' => 'CN Tower', 'category' => 'Landmark', 'lat' => 43.6426, 'long' => -79.3871],
        ['name' => 'Union Station', 'category' => 'Transit', 'lat' => 43.6452, 'long' => -79.3806],
    ]);

    try {
        $this->artisan('data:import-toronto-geospatial', [
            '--addresses' => $addressesPath,
            '--pois' => $poisPath,
            '--truncate' => true,
        ])->assertExitCode(0);

        expect(TorontoAddress::query()->count())->toBe(2);
        expect(TorontoPointOfInterest::query()->count())->toBe(2);

        $this->assertDatabaseMissing('toronto_addresses', ['street_name' => 'Old Address']);
        $this->assertDatabaseMissing('toronto_pois', ['name' => 'Old POI']);
    } finally {
        cleanupFixtureFiles($addressesPath, $poisPath);
    }
});

test('toronto geospatial import command fails when dataset file is missing', function () {
    $missingPath = sys_get_temp_dir().'/missing_'.uniqid('', true).'.csv';

    $this->artisan('data:import-toronto-geospatial', [
        '--addresses' => $missingPath,
    ])
        ->expectsOutputToContain('Dataset file not found')
        ->assertExitCode(1);
});

test('toronto geospatial import command fails for unreadable dataset files', function () {
    $unreadablePath = createAddressCsvFixture([
        'street_num,street_name,lat,long,zip',
        '100,Queen St W,43.6531,-79.3840,M5H 2N2',
    ]);

    $originalPermissions = fileperms($unreadablePath) & 0777;
    @chmod($unreadablePath, 0000);

    try {
        if (is_readable($unreadablePath)) {
            $this->markTestSkipped('Unable to make fixture unreadable in this environment.');
        }

        $this->artisan('data:import-toronto-geospatial', [
            '--addresses' => $unreadablePath,
        ])
            ->expectsOutputToContain('Dataset file is not readable')
            ->assertExitCode(1);
    } finally {
        @chmod($unreadablePath, $originalPermissions);
        cleanupFixtureFiles($unreadablePath);
    }
});

test('toronto geospatial import command fails for unsupported dataset file paths', function () {
    $unsupportedPath = sys_get_temp_dir().'/addr_'.uniqid('', true).'.txt';
    file_put_contents($unsupportedPath, 'street_num,street_name,lat,long,zip');

    try {
        $this->artisan('data:import-toronto-geospatial', [
            '--addresses' => $unsupportedPath,
        ])
            ->expectsOutputToContain('Unsupported file type')
            ->assertExitCode(1);
    } finally {
        cleanupFixtureFiles($unsupportedPath);
    }
});

test('toronto geospatial import command fails when address csv is missing required columns', function () {
    $addressesPath = createAddressCsvFixture([
        'street_num,street_name,zip',
        '100,Queen St W,M5H 2N2',
    ]);

    try {
        $this->artisan('data:import-toronto-geospatial', [
            '--addresses' => $addressesPath,
        ])
            ->expectsOutputToContain('CSV file is missing required columns')
            ->assertExitCode(1);
    } finally {
        cleanupFixtureFiles($addressesPath);
    }
});

test('toronto geospatial import command fails for invalid poi json payloads', function () {
    $poisPath = sys_get_temp_dir().'/poi_'.uniqid('', true).'.json';
    file_put_contents($poisPath, '{"name":"CN Tower"');

    try {
        $this->artisan('data:import-toronto-geospatial', [
            '--pois' => $poisPath,
        ])
            ->expectsOutputToContain('Invalid JSON payload')
            ->assertExitCode(1);
    } finally {
        cleanupFixtureFiles($poisPath);
    }
});

test('toronto geospatial import command appends duplicate records when truncate is omitted', function () {
    $addressesPath = createAddressCsvFixture([
        'street_num,street_name,lat,long,zip',
        '100,Queen St W,43.6531,-79.3840,M5H 2N2',
    ]);
    $poisPath = createPoiJsonFixture([
        ['name' => 'CN Tower', 'category' => 'Landmark', 'lat' => 43.6426, 'long' => -79.3871],
    ]);

    try {
        $this->artisan('data:import-toronto-geospatial', [
            '--addresses' => $addressesPath,
            '--pois' => $poisPath,
        ])->assertExitCode(0);

        expect(TorontoAddress::query()->count())->toBe(1);
        expect(TorontoPointOfInterest::query()->count())->toBe(1);

        $this->artisan('data:import-toronto-geospatial', [
            '--addresses' => $addressesPath,
            '--pois' => $poisPath,
        ])->assertExitCode(0);

        expect(TorontoAddress::query()->count())->toBe(2);
        expect(TorontoPointOfInterest::query()->count())->toBe(2);
    } finally {
        cleanupFixtureFiles($addressesPath, $poisPath);
    }
});

test('toronto geospatial import command requires at least one dataset option', function () {
    $this->artisan('data:import-toronto-geospatial')
        ->expectsOutputToContain('Provide at least one dataset path using --addresses or --pois.')
        ->assertExitCode(1);
});

test('toronto geospatial import command fails when csv headers are missing', function () {
    $addressesPath = createAddressCsvFixture([]);

    try {
        $this->artisan('data:import-toronto-geospatial', [
            '--addresses' => $addressesPath,
        ])
            ->expectsOutputToContain('CSV file is missing headers')
            ->assertExitCode(1);
    } finally {
        cleanupFixtureFiles($addressesPath);
    }
});

test('toronto geospatial import command skips csv rows with mismatched column counts', function () {
    $addressesPath = createAddressCsvFixture([
        'street_name,lat,long',
        'Queen St W,43.6531',
    ]);

    try {
        $this->artisan('data:import-toronto-geospatial', [
            '--addresses' => $addressesPath,
        ])->assertExitCode(0);

        expect(TorontoAddress::query()->count())->toBe(0);
    } finally {
        cleanupFixtureFiles($addressesPath);
    }
});

test('toronto geospatial import command rejects json files larger than 50mb', function () {
    $poisPath = sys_get_temp_dir().'/poi-large-'.uniqid('', true).'.json';

    $handle = fopen($poisPath, 'wb');
    expect($handle)->not->toBeFalse();

    try {
        ftruncate($handle, (50 * 1024 * 1024) + 1);
    } finally {
        fclose($handle);
    }

    try {
        $this->artisan('data:import-toronto-geospatial', [
            '--pois' => $poisPath,
        ])
            ->expectsOutputToContain('JSON file too large (>50MB). Please split the file or convert to CSV.')
            ->assertExitCode(1);
    } finally {
        cleanupFixtureFiles($poisPath);
    }
});

test('toronto geospatial import command maps geojson coordinates into poi lat long fields', function () {
    $poisPath = sys_get_temp_dir().'/poi-geojson-'.uniqid('', true).'.geojson';
    file_put_contents($poisPath, (string) json_encode([
        'type' => 'FeatureCollection',
        'features' => [
            [
                'type' => 'Feature',
                'properties' => [
                    'name' => 'GeoJSON POI',
                    'category' => 'Landmark',
                ],
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [-79.3871, 43.6426],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR));

    try {
        $this->artisan('data:import-toronto-geospatial', [
            '--pois' => $poisPath,
        ])->assertExitCode(0);

        $poi = TorontoPointOfInterest::query()->sole();
        expect((float) $poi->lat)->toBe(43.6426);
        expect((float) $poi->long)->toBe(-79.3871);
    } finally {
        cleanupFixtureFiles($poisPath);
    }
});

test('toronto geospatial import command supports non-list json objects for poi imports', function () {
    $poisPath = sys_get_temp_dir().'/poi-object-'.uniqid('', true).'.json';
    file_put_contents($poisPath, (string) json_encode([
        'name' => 'Single Object POI',
        'category' => 'Transit',
        'lat' => 43.6452,
        'long' => -79.3806,
    ], JSON_THROW_ON_ERROR));

    try {
        $this->artisan('data:import-toronto-geospatial', [
            '--pois' => $poisPath,
        ])->assertExitCode(0);

        expect(TorontoPointOfInterest::query()->count())->toBe(1);
        $this->assertDatabaseHas('toronto_pois', ['name' => 'Single Object POI']);
    } finally {
        cleanupFixtureFiles($poisPath);
    }
});

test('toronto geospatial import command skips invalid mapped address and poi rows', function () {
    $addressesPath = createAddressCsvFixture([
        'street_num,street_name,lat,long,zip',
        '100,Queen St W,43.6531,-79.3840,M5H 2N2',
        '101,,43.6532,-79.3841,M5H 2N3',
        '102,King St W,abc,-79.3842,M5H 2N4',
    ]);

    $poisPath = createPoiJsonFixture([
        ['name' => 'Valid POI', 'category' => 'Landmark', 'lat' => 43.6426, 'long' => -79.3871],
        ['name' => '', 'category' => 'Landmark', 'lat' => 43.6427, 'long' => -79.3872],
        ['name' => 'Bad Coords', 'category' => 'Landmark', 'lat' => 43.6428, 'long' => 'oops'],
    ]);

    try {
        $this->artisan('data:import-toronto-geospatial', [
            '--addresses' => $addressesPath,
            '--pois' => $poisPath,
        ])->assertExitCode(0);

        expect(TorontoAddress::query()->count())->toBe(1);
        expect(TorontoPointOfInterest::query()->count())->toBe(1);
    } finally {
        cleanupFixtureFiles($addressesPath, $poisPath);
    }
});

test('toronto geospatial import command flushes inserts in 1000-row chunks for addresses and pois', function () {
    $addressLines = ['street_num,street_name,lat,long,zip'];
    for ($index = 1; $index <= 1000; $index++) {
        $addressLines[] = "{$index},Street {$index},43.6{$index},-79.3{$index},M5V {$index}";
    }

    $poiRows = [];
    for ($index = 1; $index <= 1000; $index++) {
        $poiRows[] = [
            'name' => "POI {$index}",
            'category' => 'Test',
            'lat' => 43.5 + ($index / 10000),
            'long' => -79.4 - ($index / 10000),
        ];
    }

    $addressesPath = createAddressCsvFixture($addressLines);
    $poisPath = createPoiJsonFixture($poiRows);

    try {
        $this->artisan('data:import-toronto-geospatial', [
            '--addresses' => $addressesPath,
            '--pois' => $poisPath,
            '--truncate' => true,
        ])->assertExitCode(0);

        expect(TorontoAddress::query()->count())->toBe(1000);
        expect(TorontoPointOfInterest::query()->count())->toBe(1000);
    } finally {
        cleanupFixtureFiles($addressesPath, $poisPath);
    }
});

/**
 * @param  array<int, string>  $lines
 */
function createAddressCsvFixture(array $lines): string
{
    $path = sys_get_temp_dir().'/addr_'.uniqid('', true).'.csv';
    file_put_contents($path, implode("\n", $lines));

    return $path;
}

/**
 * @param  array<int, array<string, mixed>>  $rows
 */
function createPoiJsonFixture(array $rows): string
{
    $path = sys_get_temp_dir().'/poi_'.uniqid('', true).'.json';
    file_put_contents($path, (string) json_encode($rows, JSON_THROW_ON_ERROR));

    return $path;
}

function cleanupFixtureFiles(string ...$paths): void
{
    foreach ($paths as $path) {
        @unlink($path);
    }
}
