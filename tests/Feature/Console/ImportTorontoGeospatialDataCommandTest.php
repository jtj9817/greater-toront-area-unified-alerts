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
