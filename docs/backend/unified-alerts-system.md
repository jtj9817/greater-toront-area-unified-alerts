# Unified Alerts System

**Status:** IMPLEMENTED (February 2, 2026)

This document describes the implemented unified alerts system that aggregates data from Toronto Fire and Toronto Police sources into a single, paginated feed.

## Quick Overview

The unified alerts system uses a **Provider & Adapter** pattern with a database-level UNION query to present a single feed of alerts across all data sources.

```
FireIncident Model → FireAlertSelectProvider ↘
                                            UNION → UnifiedAlertsQuery → UnifiedAlert DTO → Frontend
PoliceCall Model → PoliceAlertSelectProvider ↗
```

### Current Status

| Component | Status | Notes |
|-----------|--------|-------|
| UnifiedAlertsQuery | ✅ Implemented | UNION ALL with tagged provider injection |
| FireAlertSelectProvider | ✅ Implemented | Toronto Fire incidents adapter |
| PoliceAlertSelectProvider | ✅ Implemented | Toronto Police calls adapter |
| TransitAlertSelectProvider | ⚠️ Stub | Returns no results (future work) |
| UnifiedAlert DTO | ✅ Implemented | Transport DTO with all fields |
| UnifiedAlertsCriteria | ✅ Implemented | Query criteria with validation |
| UnifiedAlertMapper | ✅ Implemented | Maps DB rows to DTOs |
| AlertId Value Object | ✅ Implemented | Composite ID with validation |
| AlertSource Enum | ✅ Implemented | Fire, Police, Transit |
| AlertStatus Enum | ✅ Implemented | All, Active, Cleared |
| Frontend Integration | ✅ Implemented | Inertia props with AlertItem mapping |

---

## Architecture

### Data Flow

```
┌─────────────────────────────────────────────────────────────────┐
│ 1. HTTP Request                                                 │
│    GET /?status=active&page=2                                   │
└─────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 2. GtaAlertsController                                          │
│    - Validates status (AlertStatus enum)                        │
│    - Creates UnifiedAlertsCriteria (with validation)            │
│    - Calls $alerts->paginate($criteria)                         │
└─────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 3. UnifiedAlertsQuery                                           │
│    - Fetches tagged providers via #[Tag()]                      │
│    - Builds UNION ALL from all providers                        │
│    - Applies status filter (WHERE is_active = ?)                │
│    - Orders by timestamp DESC + tie-breakers                    │
│    - Paginates at DB level (LengthAwarePaginator)               │
│    - Maps rows through UnifiedAlertMapper                       │
└─────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 4. UnifiedAlertResource Collection                              │
│    - Wraps paginator items in JsonResource                      │
│    - Serializes for Inertia props                               │
└─────────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────────┐
│ 5. Frontend (Inertia)                                           │
│    - Receives alerts prop (UnifiedAlertResource[])              │
│    - AlertService maps to AlertItem view-model                  │
│    - Renders FeedView with client-side search/filter            │
└─────────────────────────────────────────────────────────────────┘
```

### Key Design Decisions

1. **Read-time unification:** UNION query at read time, not write time
2. **True pagination:** Server-side pagination over active + cleared history
3. **Deterministic ordering:** Timestamp DESC + source + external_id for stable pages
4. **Transport vs View-model:** Backend DTO (UnifiedAlert) separate from frontend (AlertItem)
5. **Tagged injection:** New sources auto-discovered via tag-based DI

---

## Components

### UnifiedAlertsQuery

**File:** `app/Services/Alerts/UnifiedAlertsQuery.php`

Main query service using tagged provider injection.

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
        $union = $this->unionSelect();

        if ($union === null) {
            return $this->emptyPaginator($criteria);
        }

        $query = DB::query()->fromSub($union, 'unified_alerts');

        // Apply status filter
        if ($criteria->status === AlertStatus::Active->value) {
            $query->where('is_active', true);
        } elseif ($criteria->status === AlertStatus::Cleared->value) {
            $query->where('is_active', false);
        }

        return $query
            ->orderByDesc('timestamp')
            ->orderBy('source')
            ->orderByDesc('external_id')
            ->paginate($criteria->perPage)
            ->through(fn (object $row) => $this->mapper->fromRow($row));
    }
}
```

### UnifiedAlertsCriteria

**File:** `app/Services/Alerts/DTOs/UnifiedAlertsCriteria.php`

Query criteria with validation.

```php
readonly class UnifiedAlertsCriteria
{
    public const int DEFAULT_PER_PAGE = 50;
    public const int MIN_PER_PAGE = 1;
    public const int MAX_PER_PAGE = 200;

    public function __construct(
        public string $status = AlertStatus::All->value,
        public int $perPage = self::DEFAULT_PER_PAGE,
        public ?int $page = null,
    ) {
        $this->status = AlertStatus::normalize($status);
        $this->perPage = self::normalizePerPage($perPage);
        $this->page = self::normalizePage($page);
    }
}
```

### GtaAlertsController

**File:** `app/Http/Controllers/GtaAlertsController.php`

Public page controller.

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
    $paginator->withQueryString();

    return Inertia::render('gta-alerts', [
        'alerts' => UnifiedAlertResource::collection($paginator),
        'filters' => ['status' => $status],
        'latest_feed_updated_at' => $this->latestFeedUpdatedAt()?->toIso8601String(),
    ]);
}
```

---

## Provider Implementations

### FireAlertSelectProvider

Maps `fire_incidents` table to unified schema:

| Unified Column | Fire Column | Expression |
|----------------|-------------|------------|
| `id` | - | `CONCAT('fire:', event_num)` |
| `source` | - | `'fire'` |
| `external_id` | `event_num` | `CAST(event_num AS CHAR)` |
| `is_active` | `is_active` | Direct |
| `timestamp` | `dispatch_time` | Direct |
| `title` | `event_type` | Direct |
| `location_name` | `prime_street`, `cross_streets` | `CONCAT_WS(' / ', ...)` |
| `lat` | - | `NULL` |
| `lng` | - | `NULL` |
| `meta` | `alarm_level`, `units_dispatched`, `beat`, `event_num` | `JSON_OBJECT(...)` |

### PoliceAlertSelectProvider

Maps `police_calls` table to unified schema:

| Unified Column | Police Column | Expression |
|----------------|---------------|------------|
| `id` | - | `CONCAT('police:', object_id)` |
| `source` | - | `'police'` |
| `external_id` | `object_id` | Direct |
| `is_active` | `is_active` | Direct |
| `timestamp` | `occurrence_time` | Direct |
| `title` | `call_type` | Direct |
| `location_name` | `cross_streets` | Direct |
| `lat` | `latitude` | Direct |
| `lng` | `longitude` | Direct |
| `meta` | `division`, `call_type_code`, `object_id` | `JSON_OBJECT(...)` |

---

## Frontend Integration

### Inertia Props

The `gta-alerts` page receives:

```typescript
interface GTAAlertsProps {
    alerts: {
        data: UnifiedAlertResource[];
        links: Record<string, string | null>;
        meta: Record<string, unknown>;
    };
    filters: {
        status: 'all' | 'active' | 'cleared';
    };
    latest_feed_updated_at: string | null;
}
```

### Transport to View-Model Mapping

**File:** `resources/js/features/gta-alerts/services/AlertService.ts`

```typescript
mapUnifiedAlertToAlertItem(unified: UnifiedAlertResource): AlertItem {
    return {
        id: unified.id,
        title: unified.title,
        location: this.formatLocation(unified.location),
        timeAgo: this.formatTimeAgo(unified.timestamp),
        timestamp: unified.timestamp,
        description: this.buildDescription(unified),
        type: this.mapType(unified.source),
        severity: this.calculateSeverity(unified),
        iconName: this.getIconForSource(unified.source),
        accentColor: this.getAccentColor(unified.source),
        iconColor: this.getIconColor(unified.source),
        metadata: this.extractMetadata(unified),
    };
}
```

---

## Database Schema

### fire_incidents

```sql
CREATE TABLE fire_incidents (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_num VARCHAR(255) UNIQUE,
    event_type VARCHAR(255),
    prime_street VARCHAR(255),
    cross_streets VARCHAR(255),
    dispatch_time DATETIME,
    alarm_level INT,
    beat VARCHAR(255),
    units_dispatched VARCHAR(255),
    is_active BOOLEAN DEFAULT TRUE,
    feed_updated_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX (is_active, dispatch_time),
    INDEX event_type (event_type)
);
```

### police_calls

```sql
CREATE TABLE police_calls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    object_id VARCHAR(255) UNIQUE,
    call_type_code VARCHAR(255),
    call_type VARCHAR(255),
    division VARCHAR(255),
    cross_streets VARCHAR(255),
    latitude DECIMAL(10,7),
    longitude DECIMAL(10,7),
    occurrence_time DATETIME,
    is_active BOOLEAN DEFAULT TRUE,
    feed_updated_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX (is_active, occurrence_time),
    INDEX division (division)
);
```

---

## Testing

### Unit Tests

- `tests/Unit/Services/Alerts/Providers/FireAlertSelectProviderTest.php`
- `tests/Unit/Services/Alerts/Providers/PoliceAlertSelectProviderTest.php`
- `tests/Unit/Services/Alerts/Mappers/UnifiedAlertMapperTest.php`
- `tests/Unit/Enums/AlertSourceTest.php`
- `tests/Unit/Enums/AlertStatusTest.php`
- `tests/Unit/Services/Alerts/AlertIdTest.php`
- `tests/Unit/Services/Alerts/UnifiedAlertsCriteriaTest.php`

### Feature Tests

- `tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php` - SQLite tests
- `tests/Feature/UnifiedAlerts/UnifiedAlertsMySqlDriverTest.php` - MySQL cross-driver tests

### Manual Verification

- `tests/manual/verify_phase_1_foundations.php`
- `tests/manual/verify_phase_2_mapper_extraction.php`
- `tests/manual/verify_phase_3_unified_querying.php`
- `tests/manual/verify_phase_4_frontend_integration.php`
- `tests/manual/verify_phase_5_quality_gate.php`

---

## Related Documentation

- **[enums.md](enums.md)** - AlertSource, AlertStatus, AlertId documentation
- **[dtos.md](dtos.md)** - UnifiedAlert, UnifiedAlertsCriteria, AlertLocation
- **[mappers.md](mappers.md)** - UnifiedAlertMapper
- **[../architecture/provider-adapter-pattern.md](../architecture/provider-adapter-pattern.md)** - Provider pattern explanation
- **[sources/toronto-fire.md](sources/toronto-fire.md)** - Toronto Fire integration
- **[sources/toronto-police.md](sources/toronto-police.md)** - Toronto Police integration
