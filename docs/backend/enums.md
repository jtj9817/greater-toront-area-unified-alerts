# Alert Enums

## AlertSource

- File: `app/Enums/AlertSource.php`

```php
enum AlertSource: string
{
    case Fire = 'fire';
    case Police = 'police';
    case Transit = 'transit';
    case GoTransit = 'go_transit';
}
```

### Methods

- `values(): array<int, string>`
  - Returns `['fire', 'police', 'transit', 'go_transit']`
- `isValid(?string $value): bool`
  - Validates a non-empty string against enum cases.

### Usage

- Provider source identifiers
- `AlertId` validation
- Frontend transport typing (`UnifiedAlertResource.source`)

## AlertStatus

- File: `app/Enums/AlertStatus.php`

```php
enum AlertStatus: string
{
    case All = 'all';
    case Active = 'active';
    case Cleared = 'cleared';
}
```

### Methods

- `values(): array<int, string>` -> `['all', 'active', 'cleared']`
- `normalize(?string $value, self $default = self::All): string`

Used by `GtaAlertsController` and `UnifiedAlertsCriteria` to enforce valid status filters.

## Frontend Equivalents

- File: `resources/js/features/gta-alerts/types.ts`

```typescript
type AlertSource = 'fire' | 'police' | 'transit' | 'go_transit';
type AlertStatus = 'all' | 'active' | 'cleared';
```
