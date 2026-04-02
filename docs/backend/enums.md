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
    case Miway = 'miway';
    case Yrt = 'yrt';
}
```

### Methods

- `values(): array<int, string>`
  - Returns `['fire', 'police', 'transit', 'go_transit', 'miway', 'yrt']`
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

## IncidentUpdateType

- File: `app/Enums/IncidentUpdateType.php`

```php
enum IncidentUpdateType: string
{
    case Milestone = 'milestone';
    case ResourceStatus = 'resource_status';
    case AlarmChange = 'alarm_change';
    case PhaseChange = 'phase_change';
    case ManualNote = 'manual_note';
}
```

Used to classify entries in the `incident_updates` table (Scene Intel system). Values are stored as strings in the database.

### Usage

- `IncidentUpdate` model cast
- `SceneIntelProcessor` when generating synthetic intel entries
- `SceneIntelController` resource serialization

## Frontend Equivalents

- File: `resources/js/features/gta-alerts/types.ts`

```typescript
type AlertSource = 'fire' | 'police' | 'transit' | 'go_transit' | 'miway' | 'yrt';
type AlertStatus = 'all' | 'active' | 'cleared';
```

Scene Intel update types are defined in:
- File: `resources/js/features/gta-alerts/domain/alerts/fire/scene-intel.ts`

```typescript
type UpdateType = 'milestone' | 'resource_status' | 'alarm_change' | 'phase_change' | 'manual_note';
```
