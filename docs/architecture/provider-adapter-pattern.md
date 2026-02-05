# Provider & Adapter Pattern

This document explains the Provider & Adapter pattern used in the GTA Alerts unified alerts system.

## Overview

The **Provider & Adapter** pattern enables the system to aggregate data from multiple heterogeneous sources (Fire, Police, Transit) into a single unified feed while maintaining clean separation of concerns.

```
┌─────────────────────────────────────────────────────────────┐
│                    Source Models                            │
│  ┌─────────────────┐  ┌─────────────────┐  ┌────────────┐ │
│  │ FireIncident    │  │  PoliceCall     │  │ TransitAlert│ │
│  └─────────────────┘  └─────────────────┘  └────────────┘ │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                  Select Providers (Adapters)                │
│  ┌──────────────────┐  ┌──────────────────┐  ┌───────────┐│
│  │FireAlertSelect   │  │PoliceAlertSelect │  │TransitAlert││
│  │Provider          │  │Provider          │  │SelectProvider│
│  └──────────────────┘  └──────────────────┘  └───────────┘│
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│              UnifiedAlertsQuery (Aggregator)                │
│        Builds UNION ALL from tagged providers               │
└─────────────────────────────────────────────────────────────┘
                            │
                            ▼
┌─────────────────────────────────────────────────────────────┐
│                    UnifiedAlert DTO                         │
│              (Transport to Frontend)                        │
└─────────────────────────────────────────────────────────────┘
```

## Key Benefits

1. **Open/Closed Principle:** Add new sources without modifying existing code
2. **Single Responsibility:** Each provider handles one source's mapping logic
3. **Type Safety:** Common interface ensures consistent query contracts
4. **Testability:** Providers can be unit tested in isolation
5. **Database-Level Union:** Efficient pagination across all sources

## AlertSelectProvider Interface

**File:** `app/Services/Alerts/Contracts/AlertSelectProvider.php`

```php
namespace App\Services\Alerts\Contracts;

use Illuminate\Database\Query\Builder;

interface AlertSelectProvider
{
    /**
     * Return a query selecting the unified columns:
     * - id (string)
     * - source (string)
     * - external_id (string)
     * - is_active (bool/int)
     * - timestamp (datetime)
     * - title (string)
     * - location_name (string|null)
     * - lat (decimal|null)
     * - lng (decimal|null)
     * - meta (json|string|null)
     */
    public function select(): Builder;
}
```

### Contract Requirements

Each provider must return a `Builder` that selects these exact columns:

| Column | Type | Description |
|--------|------|-------------|
| `id` | string | Composite ID: `{source}:{external_id}` |
| `source` | string | Alert source identifier (matches `AlertSource` enum) |
| `external_id` | string | Original source ID (event_num, object_id, etc.) |
| `is_active` | bool/int | Active status (1/0 or true/false) |
| `timestamp` | datetime | When alert occurred (dispatch_time, occurrence_time, etc.) |
| `title` | string | Primary label (event_type, call_type, etc.) |
| `location_name` | string/null | Text location |
| `lat` | decimal/null | Latitude coordinate |
| `lng` | decimal/null | Longitude coordinate |
| `meta` | json/string/null | Source-specific metadata as JSON |

### Return Type

- Returns `Builder` from `toBase()` (not Eloquent builder)
- Enables UNION operations across different table structures
- Provider must call `->toBase()` to convert Eloquent to query builder

## Tagged Provider Injection

**File:** `app/Providers/AppServiceProvider.php`

Providers are registered with a tag for automatic injection:

```php
public function register(): void
{
    $this->app->tag([
        FireAlertSelectProvider::class,
        PoliceAlertSelectProvider::class,
        TransitAlertSelectProvider::class,
    ], 'alerts.select-providers');
}
```

### Injection in UnifiedAlertsQuery

The `UnifiedAlertsQuery` uses Laravel's tagged injection:

```php
use Illuminate\Container\Attributes\Tag;

class UnifiedAlertsQuery
{
    public function __construct(
        #[Tag('alerts.select-providers')]
        private readonly iterable $providers,
        private readonly UnifiedAlertMapper $mapper,
    ) {}
}
```

### Benefits of Tagged Injection

1. **Auto-discovery:** New providers are automatically included when tagged
2. **Loose coupling:** Query doesn't need to know concrete provider classes
3. **Easy testing:** Can swap providers in test environments
4. **Future-proof:** Adding transit/hazard sources only requires registration

## Provider Implementations

### FireAlertSelectProvider

**File:** `app/Services/Alerts/Providers/FireAlertSelectProvider.php`

```php
class FireAlertSelectProvider implements AlertSelectProvider
{
    public function select(): Builder
    {
        $driver = DB::getDriverName();
        $source = AlertSource::Fire->value;

        $idExpression = $driver === 'sqlite'
            ? "('{$source}:' || event_num)"
            : "CONCAT('{$source}:', event_num)";

        $locationExpression = $driver === 'sqlite'
            ? "CASE
                WHEN prime_street IS NOT NULL AND cross_streets IS NOT NULL
                THEN prime_street || ' / ' || cross_streets
                ...
               END"
            : "NULLIF(CONCAT_WS(' / ', prime_street, cross_streets), '')";

        $metaExpression = $driver === 'sqlite'
            ? "json_object('alarm_level', alarm_level, ...)"
            : "JSON_OBJECT('alarm_level', alarm_level, ...)";

        return FireIncident::query()
            ->selectRaw("{$idExpression} as id, ...")
            ->toBase();
    }
}
```

### PoliceAlertSelectProvider

**File:** `app/Services/Alerts/Providers/PoliceAlertSelectProvider.php`

Similar structure to fire provider, but maps:
- `division` → meta
- `call_type_code` → meta
- `latitude`/`longitude` → lat/lng

### TransitAlertSelectProvider (Stub)

Currently returns `WHERE 1=0` to return no results:

```php
public function select(): Builder
{
    return DB::query()
        ->selectRaw(
            "'transit:' || external_id as id,
             'transit' as source,
             external_id,
             is_active,
             active_period_start as timestamp,
             title,
             NULL as location_name,
             NULL as lat,
             NULL as lng,
             '{}' as meta"
        )
        ->whereRaw('1 = 0')  // Stub: no results until implemented
        ->toBase();
}
```

## Database Driver Compatibility

Providers handle SQL dialect differences:

```php
$driver = DB::getDriverName();

if ($driver === 'sqlite') {
    // SQLite string concatenation: || operator
    // JSON functions: json_object()
} else {
    // MySQL/Postgres: CONCAT(), CONCAT_WS()
    // JSON functions: JSON_OBJECT()
}
```

This ensures the system works with:
- SQLite (development/testing)
- MySQL (production)
- PostgreSQL (future support)

## UNION ALL Query Construction

**File:** `app/Services/Alerts/UnifiedAlertsQuery.php`

```php
private function unionSelect(): ?Builder
{
    $providers = [];

    // Validate and collect providers
    foreach ($this->providers as $provider) {
        if (! $provider instanceof AlertSelectProvider) {
            throw new \InvalidArgumentException("Invalid provider type");
        }
        $providers[] = $provider;
    }

    if ($providers === []) {
        return null;  // No providers registered
    }

    // Build UNION ALL
    $first = array_shift($providers);
    $union = $first->select();

    foreach ($providers as $provider) {
        $union->unionAll($provider->select());
    }

    return $union;
}
```

### Why UNION ALL?

- **UNION ALL** is faster than `UNION` (no duplicate removal)
- Duplicate IDs across sources are impossible (different source prefixes)
- Enables true pagination across all sources at database level

### Ordering Strategy

Deterministic ordering ensures stable pagination:

```php
return $query
    ->orderByDesc('timestamp')     // Primary: newest first
    ->orderBy('source')             // Tie-breaker 1: consistent source order
    ->orderByDesc('external_id')    // Tie-breaker 2: consistent ID order
    ->paginate($criteria->perPage);
```

This prevents alerts from "shifting" between pages during pagination.

## Adding a New Source

### 1. Create Model and Migration

```bash
php artisan make:model HazardAlert -m
```

### 2. Create Provider

```php
use App\Services\Alerts\Contracts\AlertSelectProvider;

class HazardAlertSelectProvider implements AlertSelectProvider
{
    public function select(): Builder
    {
        return HazardAlert::query()
            ->selectRaw("'hazard:' || id as id, 'hazard' as source, ...")
            ->toBase();
    }
}
```

### 3. Register in AppServiceProvider

```php
$this->app->tag([
    FireAlertSelectProvider::class,
    PoliceAlertSelectProvider::class,
    TransitAlertSelectProvider::class,
    HazardAlertSelectProvider::class,  // New
], 'alerts.select-providers');
```

### 4. Add to AlertSource Enum

```php
enum AlertSource: string
{
    case Fire = 'fire';
    case Police = 'police';
    case Transit = 'transit';
    case Hazard = 'hazard';  // New
}
```

### 5. Update Controller and Frontend

- Add to `latestFeedUpdatedAt()` logic
- Create frontend mapping in `AlertService`

## Related Documentation

- **[dtos.md](../backend/dtos.md)** - UnifiedAlert DTO definition
- **[mappers.md](../backend/mappers.md)** - UnifiedAlertMapper for row transformation
- **[unified-alerts-system.md](../backend/unified-alerts-system.md)** - Complete unified alerts architecture
