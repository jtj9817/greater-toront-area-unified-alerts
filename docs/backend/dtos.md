# Alert DTOs

This document describes the Data Transfer Objects (DTOs) used in the unified alerts system.

## UnifiedAlert

**File:** `app/Services/Alerts/DTOs/UnifiedAlert.php`

The primary transport DTO for all alert types. This is a UI-agnostic, readonly value object that carries data from the backend to the frontend via Inertia.js props.

```php
readonly class UnifiedAlert
{
    public function __construct(
        public string $id,                   // Composite: "fire:12345"
        public string $source,               // "fire", "police", "transit"
        public string $externalId,           // Source-specific ID
        public bool $isActive,               // Active vs cleared status
        public CarbonImmutable $timestamp,   // When it occurred
        public string $title,                // Primary label
        public ?AlertLocation $location,     // Optional location data
        public array $meta = []              // Source-specific fields
    ) {}
}
```

### Field Specifications

| Field | Type | Description |
|-------|------|-------------|
| `id` | `string` | Composite ID in format `{source}:{externalId}` (from `AlertId`) |
| `source` | `string` | Alert source identifier (must be valid `AlertSource` value) |
| `externalId` | `string` | Original ID from the source system |
| `isActive` | `bool` | `true` if currently active, `false` if cleared/historical |
| `timestamp` | `CarbonImmutable` | When the alert occurred (dispatch_time, occurrence_time, etc.) |
| `title` | `string` | Primary human-readable label (event type, call type, etc.) |
| `location` | `?AlertLocation` | Location data if available (may be null for text-only locations) |
| `meta` | `array` | Source-specific metadata (alarm levels, units, divisions, etc.) |

### Meta Field Contents

The `meta` array contains source-specific fields that don't fit the unified schema:

**Fire Incidents:**
```php
[
    'alarm_level' => 3,
    'units_dispatched' => 'P331, P332',
    'beat' => 'C31',
    'event_num' => 'F12345',
]
```

**Police Calls:**
```php
[
    'division' => 'D11',
    'call_type_code' => 'C1',
    'object_id' => 'abc123',
]
```

**Transit Alerts:**
```php
[
    'route_type' => 'Subway',
    'route' => '1',
    'severity' => 'Critical',
    'effect' => 'REDUCED_SERVICE',
    'source_feed' => 'live-api',
]
```

### Design Rationale

- **Readonly:** Prevents mutation, ensures data integrity
- **UI-agnostic:** No presentation logic (colors, icons, formatting)
- **Transport shape:** Optimized for serialization, not for direct rendering
- **Nested location:** Location is nullable because some alerts lack structured location data

### Frontend Mapping

The frontend does not consume `UnifiedAlert` directly. Instead, `AlertService.mapUnifiedAlertToAlertItem()` transforms it into `AlertItem`, the UI view-model:

```typescript
interface AlertItem {
    id: string;
    title: string;
    location: string;
    timeAgo: string;
    timestamp: string;
    description: string;
    type: 'fire' | 'police' | 'transit' | 'hazard' | 'medical';
    severity: 'high' | 'medium' | 'low';
    iconName: string;
    accentColor: string;
    iconColor: string;
    metadata?: { ... };
}
```

This separation allows the backend to evolve independently of the frontend's presentation needs.

---

## AlertLocation

**File:** `app/Services/Alerts/DTOs/AlertLocation.php`

Nested DTO representing location information.

```php
readonly class AlertLocation
{
    public function __construct(
        public ?string $name,           // Free-text location
        public ?float $lat = null,      // Latitude
        public ?float $lng = null,      // Longitude
        public ?string $postalCode = null // Postal code (future)
    ) {}
}
```

### Null Semantics

An alert may have:
- No location at all (`UnifiedAlert.location === null`)
- Text-only location (`name = "Yonge St / Dundas St"`, `lat = null`)
- Full coordinates (`name = "...", lat = 43.6532, lng = -79.3832`)

### Usage

- **Fire:** `name` constructed from `prime_street` and `cross_streets`
- **Police:** `name` from `cross_streets`, `lat/lng` from `latitude/longitude`
- **Transit:** Route-based, not point-based (typically null coordinates)

---

## UnifiedAlertsCriteria

**File:** `app/Services/Alerts/DTOs/UnifiedAlertsCriteria.php`

Query criteria DTO for unified alerts pagination. Encapsulates and validates all query parameters.

```php
readonly class UnifiedAlertsCriteria
{
    public const int DEFAULT_PER_PAGE = 50;
    public const int MIN_PER_PAGE = 1;
    public const int MAX_PER_PAGE = 200;

    public string $status;    // 'all', 'active', 'cleared'
    public int $perPage;      // 1-200
    public ?int $page;        // null = use current page from paginator
}
```

### Validation

The constructor validates all inputs:

**Status:**
- Uses `AlertStatus::normalize()` for validation
- Defaults to `AlertStatus::All->value` (`'all'`)

**PerPage:**
- Must be between `MIN_PER_PAGE` (1) and `MAX_PER_PAGE` (200)
- Throws `InvalidArgumentException` if out of range
- Defaults to `DEFAULT_PER_PAGE` (50)

**Page:**
- If provided, must be >= 1
- `null` means use the paginator's resolved current page
- Throws `InvalidArgumentException` if < 1

### Usage Example

```php
use App\Enums\AlertStatus;
use App\Services\Alerts\DTOs\UnifiedAlertsCriteria;

// From request parameters
$criteria = new UnifiedAlertsCriteria(
    status: $request->input('status', AlertStatus::All->value),
    perPage: $request->input('per_page', 50),
    page: $request->input('page'),
);

// Pass to query
$paginator = $alerts->paginate($criteria);
```

### Controller Integration

`GtaAlertsController` creates the criteria from validated request input:

```php
public function __invoke(Request $request, UnifiedAlertsQuery $alerts): Response
{
    $validated = $request->validate([
        'status' => ['nullable', Rule::enum(AlertStatus::class)],
    ]);

    $status = AlertStatus::normalize($validated['status'] ?? null);
    $page = $request->integer('page');

    $criteria = new UnifiedAlertsCriteria(
        status: $status,
        perPage: UnifiedAlertsCriteria::DEFAULT_PER_PAGE,
        page: $page > 0 ? $page : null,
    );

    $paginator = $alerts->paginate($criteria);
    // ...
}
```

---

## Related Documentation

- **[enums.md](enums.md)** - AlertSource, AlertStatus enums used by these DTOs
- **[mappers.md](mappers.md)** - UnifiedAlertMapper for creating UnifiedAlert from database rows
- **[unified-alerts-system.md](unified-alerts-system.md)** - Overall unified alerts architecture
