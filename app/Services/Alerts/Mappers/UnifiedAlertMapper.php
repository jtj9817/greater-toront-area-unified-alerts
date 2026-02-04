<?php

namespace App\Services\Alerts\Mappers;

use App\Services\Alerts\DTOs\AlertLocation;
use App\Services\Alerts\DTOs\UnifiedAlert;
use Carbon\CarbonImmutable;

class UnifiedAlertMapper
{
    public function fromRow(object $row): UnifiedAlert
    {
        $id = $this->requireNonEmptyString($row, 'id');
        $source = $this->requireNonEmptyString($row, 'source');
        $externalId = $this->requireNonEmptyString($row, 'external_id');
        $title = $this->requireNonEmptyString($row, 'title');

        $rawTimestamp = $this->read($row, 'timestamp');
        if ($rawTimestamp === null || $rawTimestamp === '') {
            throw new \InvalidArgumentException('Unified alert timestamp is required.');
        }

        try {
            $timestamp = CarbonImmutable::parse($rawTimestamp);
        } catch (\Throwable $exception) {
            throw new \InvalidArgumentException('Unified alert timestamp is not parseable.', previous: $exception);
        }

        $location = $this->mapLocation(
            name: $this->read($row, 'location_name'),
            lat: $this->read($row, 'lat'),
            lng: $this->read($row, 'lng'),
        );

        return new UnifiedAlert(
            id: $id,
            source: $source,
            externalId: $externalId,
            isActive: (bool) $this->read($row, 'is_active'),
            timestamp: $timestamp,
            title: $title,
            location: $location,
            meta: self::decodeMeta($this->read($row, 'meta')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function decodeMeta(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            try {
                $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                return [];
            }

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function mapLocation(mixed $name, mixed $lat, mixed $lng): ?AlertLocation
    {
        if ($name === null && $lat === null && $lng === null) {
            return null;
        }

        return new AlertLocation(
            name: $name !== null ? (string) $name : null,
            lat: $lat !== null ? (float) $lat : null,
            lng: $lng !== null ? (float) $lng : null,
        );
    }

    private function requireNonEmptyString(object $row, string $property): string
    {
        $value = $this->read($row, $property);

        $stringValue = (string) $value;
        if ($stringValue === '') {
            throw new \InvalidArgumentException("Unified alert {$property} is required.");
        }

        return $stringValue;
    }

    private function read(object $row, string $property): mixed
    {
        if (! property_exists($row, $property)) {
            return null;
        }

        return $row->{$property};
    }
}
