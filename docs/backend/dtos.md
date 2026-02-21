# Alert DTOs

## UnifiedAlert

- File: `app/Services/Alerts/DTOs/UnifiedAlert.php`
- Purpose: backend transport object emitted by the unified query layer.

```php
readonly class UnifiedAlert
{
    public function __construct(
        public string $id,
        public string $source,
        public string $externalId,
        public bool $isActive,
        public CarbonImmutable $timestamp,
        public string $title,
        public ?AlertLocation $location,
        public array $meta = [],
    ) {}
}
```

`source` currently supports: `fire`, `police`, `transit`, `go_transit`.

## AlertLocation

- File: `app/Services/Alerts/DTOs/AlertLocation.php`

```php
readonly class AlertLocation
{
    public function __construct(
        public ?string $name,
        public ?float $lat = null,
        public ?float $lng = null,
        public ?string $postalCode = null,
    ) {}
}
```

## UnifiedAlertsCriteria

- File: `app/Services/Alerts/DTOs/UnifiedAlertsCriteria.php`
- Purpose: validated query input for server-authoritative filtering and cursor pagination.

Key constraints:
- `status`: normalized via `AlertStatus::normalize()`
- `perPage`: min 1, max 200, default 50
- `page`: nullable, must be >=1 when provided
- `source`: nullable enum-backed source (`fire`, `police`, `transit`, `go_transit`)
- `query` (`q`): trimmed; empty/whitespace-only becomes `null`
- `since`: nullable preset (`30m`, `1h`, `3h`, `6h`, `12h`)
- `sinceCutoff`: computed `CarbonImmutable` cutoff based on `since`
- `cursor`: nullable decoded `UnifiedAlertsCursor` value object

## AlertId

- File: `app/Services/Alerts/DTOs/AlertId.php`
- Purpose: validates and builds composite IDs in `{source}:{externalId}` format.

## UnifiedAlertsCursor

- File: `app/Services/Alerts/DTOs/UnifiedAlertsCursor.php`
- Purpose: encodes and decodes opaque cursor payloads used by infinite scroll.

Payload shape before encoding:

```json
{
    "ts": "2026-02-21T00:00:00+00:00",
    "id": "fire:F12345"
}
```

- Cursor string is base64url encoded JSON.
- Decode fails closed for malformed base64, invalid JSON, missing fields, blank IDs, or invalid timestamps.
