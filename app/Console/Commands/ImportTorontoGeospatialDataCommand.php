<?php

namespace App\Console\Commands;

use App\Models\TorontoAddress;
use App\Models\TorontoPointOfInterest;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use RuntimeException;

class ImportTorontoGeospatialDataCommand extends Command
{
    private const ADDRESS_REQUIRED_CSV_HEADER_GROUPS = [
        ['streetname', 'street', 'roadname', 'streetline1', 'fulladdress', 'address'],
        ['lat', 'latitude', 'y'],
        ['long', 'longitude', 'lon', 'lng', 'x'],
    ];

    private const POI_REQUIRED_CSV_HEADER_GROUPS = [
        ['name', 'placename', 'title', 'poi'],
        ['lat', 'latitude', 'y'],
        ['long', 'longitude', 'lon', 'lng', 'x'],
    ];

    protected $signature = 'data:import-toronto-geospatial
        {--addresses= : Path to Toronto Address Points CSV/JSON}
        {--pois= : Path to Toronto Places of Interest CSV/JSON}
        {--truncate : Truncate the destination tables before import}';

    protected $description = 'Import Toronto Open Data address points and places of interest into local geospatial tables';

    public function handle(): int
    {
        $addressesPath = $this->normalizePath($this->option('addresses'));
        $poisPath = $this->normalizePath($this->option('pois'));

        if ($addressesPath === null && $poisPath === null) {
            $this->error('Provide at least one dataset path using --addresses or --pois.');

            return self::FAILURE;
        }

        if ($this->option('truncate')) {
            if ($addressesPath !== null) {
                TorontoAddress::query()->delete();
            }

            if ($poisPath !== null) {
                TorontoPointOfInterest::query()->delete();
            }
        }

        try {
            $addressesImported = $addressesPath !== null
                ? $this->importAddresses($addressesPath)
                : 0;
            $poisImported = $poisPath !== null
                ? $this->importPois($poisPath)
                : 0;
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Imported {$addressesImported} address rows and {$poisImported} POI rows.");

        return self::SUCCESS;
    }

    private function normalizePath(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $path = trim($value);

        return $path === '' ? null : $path;
    }

    private function importAddresses(string $path): int
    {
        $inserted = 0;
        $buffer = [];

        foreach ($this->rowsFromDataset($path, self::ADDRESS_REQUIRED_CSV_HEADER_GROUPS) as $row) {
            $mapped = $this->mapAddressRow($row);

            if ($mapped === null) {
                continue;
            }

            $buffer[] = $mapped;

            if (count($buffer) >= 1000) {
                TorontoAddress::query()->insert($buffer);
                $inserted += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            TorontoAddress::query()->insert($buffer);
            $inserted += count($buffer);
        }

        return $inserted;
    }

    private function importPois(string $path): int
    {
        $inserted = 0;
        $buffer = [];

        foreach ($this->rowsFromDataset($path, self::POI_REQUIRED_CSV_HEADER_GROUPS) as $row) {
            $mapped = $this->mapPoiRow($row);

            if ($mapped === null) {
                continue;
            }

            $buffer[] = $mapped;

            if (count($buffer) >= 1000) {
                TorontoPointOfInterest::query()->insert($buffer);
                $inserted += count($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            TorontoPointOfInterest::query()->insert($buffer);
            $inserted += count($buffer);
        }

        return $inserted;
    }

    /**
     * @param  array<int, array<int, string>>  $requiredCsvHeaderGroups
     * @return \Generator<int, array<string, mixed>>
     */
    private function rowsFromDataset(string $path, array $requiredCsvHeaderGroups = []): \Generator
    {
        if (! is_file($path)) {
            throw new RuntimeException("Dataset file not found: {$path}");
        }

        if (! is_readable($path)) {
            throw new RuntimeException("Dataset file is not readable: {$path}");
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'csv') {
            yield from $this->rowsFromCsv($path, $requiredCsvHeaderGroups);

            return;
        }

        if ($extension === 'json' || $extension === 'geojson') {
            yield from $this->rowsFromJson($path);

            return;
        }

        throw new RuntimeException("Unsupported file type for {$path}. Use CSV, JSON, or GeoJSON.");
    }

    /**
     * @param  array<int, array<int, string>>  $requiredCsvHeaderGroups
     * @return \Generator<int, array<string, mixed>>
     */
    private function rowsFromCsv(string $path, array $requiredCsvHeaderGroups = []): \Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open CSV file: {$path}");
        }

        $headers = fgetcsv($handle);

        if (! is_array($headers)) {
            fclose($handle);
            throw new RuntimeException("CSV file is missing headers: {$path}");
        }

        $normalizedHeaders = array_map(
            fn (mixed $header): string => $this->normalizeKey((string) $header),
            $headers,
        );

        $this->validateCsvHeaders($normalizedHeaders, $requiredCsvHeaderGroups, $path);

        try {
            while (($row = fgetcsv($handle)) !== false) {
                if (! is_array($row)) {
                    continue;
                }

                $combined = array_combine($normalizedHeaders, $row);

                if ($combined === false) {
                    continue;
                }

                yield $combined;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  array<int, string>  $normalizedHeaders
     * @param  array<int, array<int, string>>  $requiredHeaderGroups
     */
    private function validateCsvHeaders(array $normalizedHeaders, array $requiredHeaderGroups, string $path): void
    {
        if ($requiredHeaderGroups === []) {
            return;
        }

        foreach ($requiredHeaderGroups as $group) {
            if (array_intersect($group, $normalizedHeaders) !== []) {
                continue;
            }

            $groupList = implode(', ', $group);
            throw new RuntimeException("CSV file is missing required columns ({$groupList}): {$path}");
        }
    }

    /**
     * @return \Generator<int, array<string, mixed>>
     */
    private function rowsFromJson(string $path): \Generator
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid JSON payload: {$path}");
        }

        if (isset($decoded['features']) && is_array($decoded['features'])) {
            foreach ($decoded['features'] as $feature) {
                if (! is_array($feature)) {
                    continue;
                }

                $properties = is_array($feature['properties'] ?? null)
                    ? $feature['properties']
                    : [];

                $coordinates = Arr::get($feature, 'geometry.coordinates');
                if (is_array($coordinates) && count($coordinates) >= 2) {
                    $properties['long'] = $properties['long'] ?? $coordinates[0];
                    $properties['lat'] = $properties['lat'] ?? $coordinates[1];
                }

                yield $this->normalizeRow($properties);
            }

            return;
        }

        if (array_is_list($decoded)) {
            foreach ($decoded as $item) {
                if (! is_array($item)) {
                    continue;
                }

                yield $this->normalizeRow($item);
            }

            return;
        }

        yield $this->normalizeRow($decoded);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        $normalized = [];

        foreach ($row as $key => $value) {
            $normalized[$this->normalizeKey((string) $key)] = $value;
        }

        return $normalized;
    }

    private function normalizeKey(string $value): string
    {
        return strtolower((string) preg_replace('/[^a-z0-9]+/', '', $value));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function mapAddressRow(array $row): ?array
    {
        $streetName = $this->extractText($row, [
            'streetname',
            'street',
            'roadname',
            'streetline1',
            'fulladdress',
            'address',
        ]);
        $streetNum = $this->extractText($row, [
            'streetnum',
            'streetnumber',
            'streetno',
            'housenumber',
            'addrnum',
            'number',
            'municipalnumber',
        ]);
        $zip = $this->extractText($row, ['postalcode', 'postcode', 'zip']);

        $lat = $this->extractFloat($row, ['lat', 'latitude', 'y']);
        $long = $this->extractFloat($row, ['long', 'longitude', 'lon', 'lng', 'x']);

        if ($streetName === null || $lat === null || $long === null) {
            return null;
        }

        return [
            'street_num' => $streetNum,
            'street_name' => $streetName,
            'lat' => $lat,
            'long' => $long,
            'zip' => $zip,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function mapPoiRow(array $row): ?array
    {
        $name = $this->extractText($row, ['name', 'placename', 'title', 'poi']);
        $category = $this->extractText($row, ['category', 'type', 'class']);
        $lat = $this->extractFloat($row, ['lat', 'latitude', 'y']);
        $long = $this->extractFloat($row, ['long', 'longitude', 'lon', 'lng', 'x']);

        if ($name === null || $lat === null || $long === null) {
            return null;
        }

        return [
            'name' => $name,
            'category' => $category,
            'lat' => $lat,
            'long' => $long,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    private function extractText(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;

            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $normalized = trim((string) $value);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $keys
     */
    private function extractFloat(array $row, array $keys): ?float
    {
        foreach ($keys as $key) {
            $value = $row[$key] ?? null;

            if (! is_numeric($value)) {
                continue;
            }

            return (float) $value;
        }

        return null;
    }
}
