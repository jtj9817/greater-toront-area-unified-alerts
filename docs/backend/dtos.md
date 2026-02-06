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
- Purpose: validated query input for unified pagination.

Key constraints:
- `status`: normalized via `AlertStatus::normalize()`
- `perPage`: min 1, max 200, default 50
- `page`: nullable, must be >=1 when provided

## AlertId

- File: `app/Services/Alerts/DTOs/AlertId.php`
- Purpose: validates and builds composite IDs in `{source}:{externalId}` format.
