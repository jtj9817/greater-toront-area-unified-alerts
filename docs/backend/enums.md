# Alert Enums

This document describes the enumeration types used throughout the GTA Alerts unified alerts system.

## AlertSource

**File:** `app/Enums/AlertSource.php`

Type-safe enumeration of all supported alert data sources.

```php
enum AlertSource: string
{
    case Fire = 'fire';
    case Police = 'police';
    case Transit = 'transit';
}
```

### Methods

**`values(): array<int, string>`**
Returns an array of all enum string values: `['fire', 'police', 'transit']`

**`isValid(?string $value): bool`**
Validates if a string is a valid alert source.

```php
AlertSource::isValid('fire');   // true
AlertSource::isValid('invalid'); // false
AlertSource::isValid(null);     // false
```

### Usage

Used throughout the system for type-safe source identification:

- **Provider tagging:** In `AppServiceProvider` when registering alert providers
- **Query validation:** In select providers to ensure consistent source values
- **AlertId validation:** Composite IDs must use valid source values
- **Frontend types:** TypeScript enum equivalent in `types.ts`

### Adding New Sources

To add a new alert source:

1. Add case to `AlertSource` enum:
   ```php
   case Hazard = 'hazard';
   ```

2. Create source model and migration
3. Create `*AlertSelectProvider` implementing `AlertSelectProvider`
4. Register provider in `AppServiceProvider` with tag `alerts.select-providers`
5. Update `latestFeedUpdatedAt()` in `GtaAlertsController`

---

## AlertStatus

**File:** `app/Enums/AlertStatus.php`

Type-safe enumeration for alert status filtering.

```php
enum AlertStatus: string
{
    case All = 'all';
    case Active = 'active';
    case Cleared = 'cleared';
}
```

### Methods

**`values(): array<int, string>`**
Returns all enum values: `['all', 'active', 'cleared']`

**`normalize(?string $value, self $default = self::All): string`**
Normalizes and validates status input. Throws `InvalidArgumentException` for invalid values.

```php
AlertStatus::normalize('active');  // 'active'
AlertStatus::normalize(null);      // 'all' (default)
AlertStatus::normalize('invalid'); // throws InvalidArgumentException
```

### Usage

Primary use is in query filtering:

- **Request validation:** In `GtaAlertsController` for `status` query param
- **Query criteria:** In `UnifiedAlertsCriteria` constructor
- **Database queries:** `WHERE is_active = true/false` conditions

### Status Mapping

| Enum Value | Database Condition | Meaning |
|------------|-------------------|---------|
| `All` | (no filter) | Include both active and cleared alerts |
| `Active` | `is_active = true` | Currently active incidents |
| `Cleared` | `is_active = false` | Historical/cleared incidents |

### Default Behavior

The `UnifiedAlertsCriteria` defaults to `All` status, meaning the unified feed includes both active and cleared alerts by default. This enables true pagination over alert history.

---

## Related Value Objects

### AlertId (DTO)

**File:** `app/Services/Alerts/DTOs/AlertId.php`

While not an enum, `AlertId` is a value object that works closely with `AlertSource`.

Composite ID format: `{source}:{externalId}`

```php
readonly class AlertId
{
    public function __construct(
        public string $source,      // Must be valid AlertSource value
        public string $externalId,  // Source-specific identifier
    ) {
        $this->assertValid();      // Validates source and non-empty values
    }
}
```

#### Factory Methods

**`fromParts(string $source, string $externalId): self`**
Create from separate source and external ID components.

**`fromString(string $value): self`**
Parse from composite string format: `"fire:12345"`

```php
AlertId::fromParts('fire', '12345');     // Creates AlertId
AlertId::fromString('fire:12345');       // Parses and validates
AlertId::fromString('invalid:123');      // throws InvalidArgumentException
```

#### Methods

**`value(): string`** - Returns composite ID: `"fire:12345"`

**`__toString(): string`** - String conversion alias

#### Validation Rules

- Source must be a valid `AlertSource` value
- Source and externalId cannot be empty strings
- String input must contain exactly one colon separator

#### Usage

Used in:

- **UnifiedAlert DTO:** As the primary `id` field
- **Unique identification:** Cross-source deduplication
- **URL routing:** Alert detail pages (future)
- **API responses:** Consistent ID format across all sources

---

## TypeScript Equivalents

The frontend has corresponding TypeScript definitions in `resources/js/features/gta-alerts/types.ts`:

```typescript
// AlertSource equivalent
type AlertSource = 'fire' | 'police' | 'transit';

// AlertStatus equivalent
type AlertStatus = 'all' | 'active' | 'cleared';

// UnifiedAlertResource interface includes these types
interface UnifiedAlertResource {
    source: AlertSource;
    is_active: boolean;
    // ... other fields
}
```
