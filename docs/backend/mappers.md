# Alert Mappers

This document describes the mapper components in the unified alerts system.

## UnifiedAlertMapper

**File:** `app/Services/Alerts/Mappers/UnifiedAlertMapper.php`

Responsible for transforming database rows (from the UNION query) into strongly-typed `UnifiedAlert` DTOs.

### Primary Method

**`fromRow(object $row): UnifiedAlert`**

Maps a raw database row object to a `UnifiedAlert` DTO.

```php
public function fromRow(object $row): UnifiedAlert
{
    $source = $this->requireNonEmptyString($row, 'source');
    $externalId = $this->requireNonEmptyString($row, 'external_id');
    $title = $this->requireNonEmptyString($row, 'title');
    $id = (string) AlertId::fromParts($source, $externalId);

    $rawTimestamp = $this->read($row, 'timestamp');
    // ... timestamp parsing ...

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
```

### Expected Row Shape

The mapper expects rows with these columns (from `AlertSelectProvider` implementations):

| Column | Type | Required | Description |
|--------|------|----------|-------------|
| `id` | string | Yes | Composite ID (may be ignored, recomputed from source+external_id) |
| `source` | string | Yes | Alert source identifier |
| `external_id` | string | Yes | Source-specific identifier |
| `is_active` | bool/int | Yes | Active status (1/0 or true/false) |
| `timestamp` | string | Yes | ISO 8601 datetime string |
| `title` | string | Yes | Alert title/type |
| `location_name` | string/null | No | Text location description |
| `lat` | float/null | No | Latitude |
| `lng` | float/null | No | Longitude |
| `meta` | string/array | No | JSON string or pre-decoded array |

### Validation Behavior

**Required Fields:**
- `source`, `external_id`, `title`, `timestamp` must be non-empty strings
- Throws `InvalidArgumentException` if missing or empty

**Timestamp Parsing:**
- Uses `CarbonImmutable::parse()`
- Throws `InvalidArgumentException` if unparseable

**Type Safety:**
- Boolean coercion for `is_active`
- Float casting for `lat`/`lng` when present

### Static Methods

**`decodeMeta(mixed $value): array`**

Decodes meta field from JSON string to array.

```php
// Handles multiple input types
decodeMeta(['key' => 'value']);        // Returns as-is
decodeMeta('{"key":"value"}');         // JSON decodes
decodeMeta('invalid json');            // Returns []
decodeMeta(null);                      // Returns []
decodeMeta('');                        // Returns []
```

**Error Handling:**
- Silently falls back to empty array on JSON decode errors
- This prevents single malformed meta from breaking the entire feed

### Helper Methods

**`requireNonEmptyString(object $row, string $property): string`**
Reads a property and validates it's a non-empty string.

**`mapLocation(mixed $name, mixed $lat, mixed $lng): ?AlertLocation`**
Creates `AlertLocation` DTO if any location data exists, returns `null` otherwise.

**`read(object $row, string $property): mixed`**
Safely reads a property from an object, returns `null` if missing.

### Usage in UnifiedAlertsQuery

The mapper is injected into `UnifiedAlertsQuery` and used to transform paginator items:

```php
class UnifiedAlertsQuery
{
    public function __construct(
        #[Tag('alerts.select-providers')]
        private readonly iterable $providers,
        private readonly UnifiedAlertMapper $mapper,
    ) {}

    public function paginate(UnifiedAlertsCriteria $criteria): LengthAwarePaginator
    {
        // ... build UNION query ...

        return $query
            ->orderByDesc('timestamp')
            ->paginate($criteria->perPage)
            ->through(fn (object $row) => $this->mapper->fromRow($row));
            //       ^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^^
            //       Transform each row to UnifiedAlert DTO
    }
}
```

### Database Driver Compatibility

The mapper works with database rows from any driver (MySQL, SQLite, PostgreSQL) because:

1. Uses generic `object` type (not `stdClass`)
2. Accesses properties via `property_exists()` checks
3. Relies on provider layer for driver-specific SQL expressions
4. Independent of query builder implementation

### Testing

See `tests/Unit/Services/Alerts/Mappers/UnifiedAlertMapperTest.php` for test coverage:

```php
it('maps fire incident row to unified alert', function () {
    $row = (object) [
        'source' => 'fire',
        'external_id' => 'F12345',
        'is_active' => true,
        'timestamp' => '2026-02-05T12:00:00Z',
        'title' => 'Fire - Residential',
        'location_name' => 'Yonge St / Dundas St',
        'lat' => null,
        'lng' => null,
        'meta' => json_encode(['alarm_level' => 3]),
    ];

    $alert = $mapper->fromRow($row);

    expect($alert->source)->toBe('fire');
    expect($alert->externalId)->toBe('F12345');
    expect($alert->meta)->toBe(['alarm_level' => 3]);
});
```

---

## Related Documentation

- **[dtos.md](dtos.md)** - UnifiedAlert DTO definition
- **[unified-alerts-system.md](unified-alerts-system.md)** - Overall unified alerts architecture
- **[providers.md](providers.md)** - AlertSelectProvider implementations that produce the rows
