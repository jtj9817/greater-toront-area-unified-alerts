# Dynamic Zones Architecture

This document specifies the architecture for replacing the hard-coded `ZonesView` frontend component with a backend-driven zones feature that computes real-time zone statistics from alert data within the last 24 hours.

## Current State

The `ZonesView` component (`resources/js/features/gta-alerts/components/ZonesView.tsx`) renders 6 GTA geographic zones with entirely hard-coded data:

```typescript
const ZONES = [
    { id: 1, name: 'Downtown Core', status: 'High Activity', count: 12, ... },
    { id: 2, name: 'Scarborough', status: 'Moderate', count: 5, ... },
    // ... 4 more zones
];
```

- The component accepts **no props**.
- Alert counts, activity statuses, and color assignments are static.
- There is **no connection** between zones and real alert data.

### Existing Geographic Fields

| Source | Model Field | Example Values | Notes |
|--------|-------------|---------------|-------|
| Fire | `fire_incidents.beat` | Raw string from XML feed | Toronto Fire station beat identifiers |
| Police | `police_calls.division` | `D11`..`D55` | Toronto Police Service division codes |
| Transit | n/a | No geographic field | TTC alerts lack zone-level data |

Neither `beat` nor `division` currently maps to a user-facing zone. This feature creates that mapping.

### Existing Database Indexes

Relevant indexes that the new queries will use:

- `fire_incidents`: composite `(is_active, dispatch_time)`, standalone `event_type`
- `police_calls`: composite `(is_active, occurrence_time)`, standalone `division`

No additional indexes are required. The queries filter on `dispatch_time >= ?` and `occurrence_time >= ?`, which the composite indexes cover.

---

## Design Decisions

### 1. Backend Computation via Inertia Props

Zone statistics are computed server-side and passed to the frontend as an Inertia prop (`zone_stats`), alongside the existing `alerts` prop. This avoids a separate API endpoint and keeps the data flow consistent with the existing pattern in `GtaAlertsController`.

**Rationale:** The frontend currently receives only 50 paginated alerts per page. Accurate zone statistics require aggregating across all alerts in the 24-hour window, which can only be done efficiently on the backend.

### 2. Config-Based Zone Mapping

A PHP config file (`config/zones.php`) defines the 6 fixed zones and maps `beat` codes and `division` codes to zone IDs. This is a lookup table, not a geographic boundary calculation.

**Rationale:** Beat codes and police divisions are discrete identifiers, not coordinates. A lookup table is deterministic, fast, and easy to maintain. Alerts with unmapped codes are excluded from zone counts rather than assigned to a fallback zone — this keeps counts accurate and surfaceable.

### 3. Direct Model Queries (Not UnifiedAlertsQuery)

The service queries `FireIncident` and `PoliceCall` models directly instead of going through `UnifiedAlertsQuery`. The unified query constructs full alert DTOs with UNION ALL, meta JSON assembly, and pagination — none of which zone aggregation needs.

**Rationale:** Zone stats only require `beat`/`division`, `is_active`, and `dispatch_time`/`occurrence_time`. Selecting just these columns avoids the overhead of JSON meta construction, location concatenation, and DTO hydration. Two targeted `SELECT` queries with `WHERE timestamp >= ?` are cheaper than a full UNION ALL pipeline.

### 4. All Alerts in 24-Hour Window

Zone counts include both active (`is_active = true`) and cleared (`is_active = false`) alerts where the timestamp falls within the last 24 hours. The DTO exposes both `activeCount` and `clearedCount` to allow the frontend to distinguish if needed.

### 5. Activity Status Thresholds Based on Total Count

The activity status label (High Activity, Moderate, etc.) is derived from the **total** count (active + cleared) within 24 hours. Thresholds are defined in config and applied on the backend.

| Total Count | Status | Color Token |
|-------------|--------|-------------|
| >= 10 | High Activity | `coral` |
| >= 5 | Moderate | `burnt-orange` |
| >= 2 | Low Activity | `forest` |
| >= 1 | Normal | `amber` |
| 0 | Monitoring | `gray` |

### 6. UI Color Derivation on Frontend

The backend DTO carries only data (`id`, `name`, `counts`, `activityStatus`). The frontend derives Tailwind color classes from `activityStatus` using a mapping utility. This keeps the backend UI-agnostic and follows the existing pattern where `AlertService` derives icon colors and accent classes from alert type/severity.

---

## Architecture Overview

```
┌──────────────────────────────────────────────────┐
│ config/zones.php                                  │
│ ┌──────────────────────────────────────────────┐ │
│ │ definitions: [{id, name}, ...]               │ │
│ │ fire_beat_map: {beat → zone_id, ...}         │ │
│ │ police_division_map: {division → zone_id, ...}│ │
│ │ activity_thresholds: {high: 10, ...}         │ │
│ └──────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────────────────────┐
│ ZoneStatsService                                  │
│   getStats(hoursBack: 24): ZoneStats[]            │
│   ├─ FireIncident::where(dispatch_time >= -24h)   │
│   │  ->select(beat, is_active)->get()             │
│   ├─ PoliceCall::where(occurrence_time >= -24h)   │
│   │  ->select(division, is_active)->get()         │
│   ├─ Map beat/division → zone via config          │
│   ├─ Aggregate counts per zone                    │
│   └─ Apply thresholds → activityStatus            │
│   Returns: ZoneStats[] (6 DTOs, one per zone)     │
└──────────────────────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────────────────────┐
│ GtaAlertsController                               │
│   __invoke(Request, UnifiedAlertsQuery,            │
│            ZoneStatsService)                       │
│   ├─ $alerts->paginate(50, $status)  (existing)   │
│   ├─ $zones->getStats()              (new)        │
│   └─ Inertia::render('gta-alerts', [              │
│         'alerts' => ...,             (existing)    │
│         'zone_stats' => ZoneStatsResource::        │
│              collection($zoneStats), (new)         │
│         ...                                        │
│      ])                                            │
└──────────────────────────────────────────────────┘
          │
          ▼ Inertia JSON
┌──────────────────────────────────────────────────┐
│ pages/gta-alerts.tsx                              │
│   Props: { alerts, zone_stats, filters, ... }     │
│   └─ <AlertsApp zoneStats={zone_stats} ... />     │
└──────────────────────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────────────────────┐
│ features/gta-alerts/App.tsx                       │
│   case 'zones':                                   │
│     <ZonesView zoneStats={zoneStats} />           │
└──────────────────────────────────────────────────┘
          │
          ▼
┌──────────────────────────────────────────────────┐
│ components/ZonesView.tsx                          │
│   zoneStats.map(zone => {                         │
│     display = getZoneDisplayProps(zone.activity_   │
│                status)                             │
│     render card with zone.total_count,             │
│       display.label, display.color, etc.           │
│   })                                              │
└──────────────────────────────────────────────────┘
```

---

## Files to Create

### 1. `config/zones.php`

Zone definitions, geographic code mappings, and activity thresholds.

```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Zone Definitions
    |--------------------------------------------------------------------------
    | Fixed list of GTA geographic zones. Order determines display order.
    */
    'definitions' => [
        ['id' => 'downtown-core', 'name' => 'Downtown Core'],
        ['id' => 'scarborough',   'name' => 'Scarborough'],
        ['id' => 'north-york',    'name' => 'North York'],
        ['id' => 'etobicoke',     'name' => 'Etobicoke'],
        ['id' => 'peel-region',   'name' => 'Peel Region'],
        ['id' => 'york-region',   'name' => 'York Region'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fire Beat → Zone Mapping
    |--------------------------------------------------------------------------
    | Maps Toronto Fire Services beat codes to zone IDs.
    | Unmapped beats are excluded from zone counts.
    | Populate from observed feed data.
    */
    'fire_beat_map' => [
        // Downtown Core fire beats (stations in downtown area)
        // e.g., 'C31' => 'downtown-core',

        // Scarborough fire beats
        // e.g., 'S41' => 'scarborough',

        // Populate as real beat codes are observed in feed data
    ],

    /*
    |--------------------------------------------------------------------------
    | Police Division → Zone Mapping
    |--------------------------------------------------------------------------
    | Maps Toronto Police Service division codes to zone IDs.
    | Division format in DB: "D11", "D52", etc.
    */
    'police_division_map' => [
        // Downtown Core
        'D11' => 'downtown-core',
        'D13' => 'downtown-core',
        'D14' => 'downtown-core',
        'D51' => 'downtown-core',
        'D52' => 'downtown-core',

        // Scarborough
        'D41' => 'scarborough',
        'D42' => 'scarborough',
        'D43' => 'scarborough',

        // North York
        'D31' => 'north-york',
        'D32' => 'north-york',
        'D33' => 'north-york',
        'D53' => 'north-york',

        // Etobicoke
        'D22' => 'etobicoke',
        'D23' => 'etobicoke',

        // East York / Other Toronto divisions
        'D12' => 'downtown-core',
        'D54' => 'north-york',
        'D55' => 'downtown-core',
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity Thresholds
    |--------------------------------------------------------------------------
    | Total alert count (active + cleared in 24h) to activity status mapping.
    | Applied in descending order; first match wins.
    */
    'activity_thresholds' => [
        'high_activity' => 10,
        'moderate'      => 5,
        'low_activity'  => 2,
        'normal'        => 1,
        // 0 → 'monitoring' (implicit default)
    ],
];
```

**Notes on beat mapping:** Toronto Fire beat codes are feed-specific identifiers whose format is not publicly documented. The `fire_beat_map` should be populated incrementally by observing actual beat values from the live feed. A helper artisan command or a one-time query (`SELECT DISTINCT beat FROM fire_incidents`) can populate this. Until populated, fire incidents will not contribute to zone counts — police data alone will drive zone statistics.

---

### 2. `app/Services/Zones/DTOs/ZoneStats.php`

Readonly DTO carrying statistics for a single zone. Follows the existing pattern in `App\Services\Alerts\DTOs\UnifiedAlert`.

```php
<?php

namespace App\Services\Zones\DTOs;

readonly class ZoneStats
{
    public function __construct(
        public string $id,
        public string $name,
        public int $activeCount,
        public int $clearedCount,
        public int $totalCount,
        public string $activityStatus,
    ) {}
}
```

**Fields:**

| Field | Type | Description |
|-------|------|-------------|
| `id` | `string` | Zone slug from config (e.g., `downtown-core`) |
| `name` | `string` | Display name from config (e.g., `Downtown Core`) |
| `activeCount` | `int` | Alerts with `is_active = true` in the zone within 24h |
| `clearedCount` | `int` | Alerts with `is_active = false` in the zone within 24h |
| `totalCount` | `int` | `activeCount + clearedCount` |
| `activityStatus` | `string` | One of: `high_activity`, `moderate`, `low_activity`, `normal`, `monitoring` |

---

### 3. `app/Services/Zones/ZoneStatsService.php`

Service that queries both models, maps geographic codes to zones, aggregates counts, and applies activity thresholds.

```php
<?php

namespace App\Services\Zones;

use App\Models\FireIncident;
use App\Models\PoliceCall;
use App\Services\Zones\DTOs\ZoneStats;
use Carbon\CarbonImmutable;

class ZoneStatsService
{
    /**
     * @return list<ZoneStats>
     */
    public function getStats(int $hoursBack = 24): array;
}
```

**Internal logic:**

1. Compute `$since = CarbonImmutable::now()->subHours($hoursBack)`.
2. Query fire incidents:
   ```php
   FireIncident::query()
       ->where('dispatch_time', '>=', $since)
       ->whereNotNull('beat')
       ->select(['beat', 'is_active'])
       ->get();
   ```
3. Query police calls:
   ```php
   PoliceCall::query()
       ->where('occurrence_time', '>=', $since)
       ->whereNotNull('division')
       ->select(['division', 'is_active'])
       ->get();
   ```
4. Initialize a counters array: `$counts[$zoneId] = ['active' => 0, 'cleared' => 0]` for each zone in `config('zones.definitions')`.
5. For each fire incident, look up `config('zones.fire_beat_map')[$beat]`. If found, increment the zone's `active` or `cleared` counter based on `is_active`.
6. For each police call, look up `config('zones.police_division_map')[$division]`. Same increment logic.
7. For each zone, compute `totalCount = active + cleared`, derive `activityStatus` from `config('zones.activity_thresholds')`, and construct a `ZoneStats` DTO.
8. Return the array of 6 DTOs in definition order.

**Activity status resolution:**

```php
private function determineActivityStatus(int $totalCount): string
{
    foreach (config('zones.activity_thresholds') as $status => $threshold) {
        if ($totalCount >= $threshold) {
            return $status;
        }
    }

    return 'monitoring';
}
```

The `activity_thresholds` config is ordered descending by threshold value. The first threshold that `$totalCount` meets or exceeds determines the status.

---

### 4. `app/Http/Resources/ZoneStatsResource.php`

JSON resource wrapping the `ZoneStats` DTO. Follows the pattern of `UnifiedAlertResource`.

```php
<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Services\Zones\DTOs\ZoneStats
 */
class ZoneStatsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'active_count' => $this->activeCount,
            'cleared_count' => $this->clearedCount,
            'total_count' => $this->totalCount,
            'activity_status' => $this->activityStatus,
        ];
    }
}
```

**JSON output per zone:**

```json
{
    "id": "downtown-core",
    "name": "Downtown Core",
    "active_count": 8,
    "cleared_count": 4,
    "total_count": 12,
    "activity_status": "high_activity"
}
```

---

## Files to Modify

### 5. `app/Http/Controllers/GtaAlertsController.php`

**Changes:**

- Add `ZoneStatsService` parameter to the `__invoke` method signature (Laravel auto-resolves from the container).
- Call `$zones->getStats()` and wrap result with `ZoneStatsResource::collection()`.
- Add `zone_stats` key to the Inertia props array.

**Before:**

```php
public function __invoke(Request $request, UnifiedAlertsQuery $alerts): Response
{
    // ... existing code ...
    return Inertia::render('gta-alerts', [
        'alerts' => UnifiedAlertResource::collection($paginator),
        'filters' => ['status' => $status],
        'latest_feed_updated_at' => $latestFeedUpdatedAt?->toIso8601String(),
    ]);
}
```

**After:**

```php
public function __invoke(
    Request $request,
    UnifiedAlertsQuery $alerts,
    ZoneStatsService $zones,
): Response {
    // ... existing code ...
    return Inertia::render('gta-alerts', [
        'alerts' => UnifiedAlertResource::collection($paginator),
        'filters' => ['status' => $status],
        'latest_feed_updated_at' => $latestFeedUpdatedAt?->toIso8601String(),
        'zone_stats' => ZoneStatsResource::collection($zones->getStats()),
    ]);
}
```

**Impact on existing behavior:** None. The `alerts`, `filters`, and `latest_feed_updated_at` props are unchanged. The new `zone_stats` prop is additive.

**Impact on existing tests:** The `GtaAlertsTest` tests will continue to pass because they assert on specific prop keys (`alerts`, `latest_feed_updated_at`, `filters`) and do not assert the absence of other props. The `zone_stats` prop will simply be present alongside existing props.

---

### 6. `resources/js/features/gta-alerts/types.ts`

**Add** the `ZoneStatsResource` interface after the existing interfaces.

```typescript
export interface ZoneStatsResource {
    id: string;
    name: string;
    active_count: number;
    cleared_count: number;
    total_count: number;
    activity_status: 'high_activity' | 'moderate' | 'low_activity' | 'normal' | 'monitoring';
}
```

**Impact:** Additive only. No existing types are changed.

---

### 7. `resources/js/pages/gta-alerts.tsx`

**Changes:**

- Import `ZoneStatsResource` from types.
- Add `zone_stats: ZoneStatsResource[]` to the `GTAAlertsProps` interface.
- Destructure `zone_stats` from props.
- Pass `zoneStats={zone_stats}` to `<AlertsApp />`.

**Before:**

```typescript
interface GTAAlertsProps {
    alerts: { ... };
    filters: { ... };
    latest_feed_updated_at: string | null;
}

export default function GTAAlerts({ alerts, filters, latest_feed_updated_at }: GTAAlertsProps) {
    return (
        <AlertsApp
            alerts={alerts}
            filters={filters}
            latestFeedUpdatedAt={latest_feed_updated_at}
        />
    );
}
```

**After:**

```typescript
interface GTAAlertsProps {
    alerts: { ... };
    filters: { ... };
    latest_feed_updated_at: string | null;
    zone_stats: ZoneStatsResource[];
}

export default function GTAAlerts({ alerts, filters, latest_feed_updated_at, zone_stats }: GTAAlertsProps) {
    return (
        <AlertsApp
            alerts={alerts}
            filters={filters}
            latestFeedUpdatedAt={latest_feed_updated_at}
            zoneStats={zone_stats}
        />
    );
}
```

**Impact:** Additive prop threading. Existing props unchanged.

---

### 8. `resources/js/features/gta-alerts/App.tsx`

**Changes:**

- Import `ZoneStatsResource` from types.
- Add `zoneStats: ZoneStatsResource[]` to `AppProps`.
- Destructure `zoneStats` from props.
- Pass `zoneStats` to `<ZonesView />`.

**Before (line 88):**

```tsx
case 'zones':
    return <ZonesView />;
```

**After:**

```tsx
case 'zones':
    return <ZonesView zoneStats={zoneStats} />;
```

**Impact:** `ZonesView` now requires the `zoneStats` prop. No other components or views are affected.

---

### 9. `resources/js/features/gta-alerts/components/ZonesView.tsx`

**Changes:**

- Remove the hard-coded `ZONES` constant (lines 4–59).
- Import `ZoneStatsResource` from types.
- Define a `ZonesViewProps` interface with `zoneStats: ZoneStatsResource[]`.
- Update the component to accept props and render from `zoneStats`.
- Derive display properties (color, bg, dotColor, label) from `zone.activity_status` using an inline mapping object.

**Before:**

```tsx
const ZONES = [
    { id: 1, name: 'Downtown Core', status: 'High Activity', color: 'text-coral', ... },
    // ... 5 more hard-coded zones
];

export const ZonesView: React.FC = () => {
    return (
        // ... renders ZONES.map(...)
    );
};
```

**After:**

```tsx
import type { ZoneStatsResource } from '../types';

const ACTIVITY_DISPLAY: Record<string, { label: string; color: string; bg: string; dotColor: string }> = {
    high_activity: { label: 'High Activity', color: 'text-coral',         bg: 'bg-coral/10',         dotColor: 'bg-coral' },
    moderate:      { label: 'Moderate',      color: 'text-burnt-orange',  bg: 'bg-burnt-orange/10',  dotColor: 'bg-burnt-orange' },
    low_activity:  { label: 'Low Activity',  color: 'text-forest',        bg: 'bg-forest/10',        dotColor: 'bg-forest' },
    normal:        { label: 'Normal',        color: 'text-amber',         bg: 'bg-amber/10',         dotColor: 'bg-amber' },
    monitoring:    { label: 'Monitoring',    color: 'text-gray-400',      bg: 'bg-white/5',          dotColor: 'bg-gray-500' },
};

interface ZonesViewProps {
    zoneStats: ZoneStatsResource[];
}

export const ZonesView: React.FC<ZonesViewProps> = ({ zoneStats }) => {
    return (
        // ... renders zoneStats.map(zone => {
        //   const display = ACTIVITY_DISPLAY[zone.activity_status] ?? ACTIVITY_DISPLAY.monitoring;
        //   ... use zone.total_count, display.label, display.color, etc.
        // })
    );
};
```

**Impact:** The component becomes purely data-driven. The same visual design is preserved — card layout, grid, hover effects, icon usage are all unchanged. The only difference is data source (props vs constant).

---

## Failure Modes and Error Handling

### 1. Empty Database (No Alerts in 24h Window)

**Scenario:** Fresh deployment or quiet period with no incidents.

**Behavior:** Both queries return empty collections. All 6 zones get `activeCount: 0, clearedCount: 0, totalCount: 0, activityStatus: 'monitoring'`. The UI renders all zones in gray with "Monitoring" status and count of 0.

**No special handling needed.** This is the expected steady-state for inactive zones.

### 2. Unmapped Beat/Division Code

**Scenario:** A fire incident has `beat = 'X99'` which is not in `config('zones.fire_beat_map')`, or a police call has `division = 'D99'` not in `config('zones.police_division_map')`.

**Behavior:** The alert is silently excluded from zone counts. It does not appear in any zone's statistics.

**Rationale:** Assigning to a fallback zone would produce misleading counts. Excluding is transparent and accurate. To surface unmapped codes, a separate monitoring mechanism (log warning, admin dashboard) can be added later.

### 3. Null Beat/Division

**Scenario:** Fire incident has `beat = null` or police call has `division = null`.

**Behavior:** The `whereNotNull('beat')` / `whereNotNull('division')` clause in the query excludes these rows at the database level. They never reach the mapping logic.

### 4. Config File Missing or Malformed

**Scenario:** `config/zones.php` is deleted, or `definitions` key is missing.

**Behavior:** `config('zones.definitions')` returns `null`. The service should handle this gracefully by treating it as an empty array, resulting in no zones returned. The frontend receives `zone_stats: []` and renders an empty grid.

**Implementation:** The service will default to an empty array if the config key is null:

```php
$definitions = config('zones.definitions', []);
```

### 5. Database Query Failure

**Scenario:** Database connection timeout or query error when fetching incidents/calls.

**Behavior:** The exception propagates up through the controller. Laravel's error handling returns a 500 response. This is the same behavior as a failure in the existing `UnifiedAlertsQuery::paginate()` call — the page fails to load entirely.

**Rationale:** Zone stats are not independently recoverable. If the database is down, the alerts prop will also fail. There is no value in catching the zone query exception separately.

### 6. Very Large Dataset (Performance Degradation)

**Scenario:** An unusual event produces thousands of alerts within 24 hours.

**Behavior:** The two `SELECT` queries fetch only `beat`/`division` + `is_active` (2 columns) per row, which is lightweight. For 10,000 rows, this is approximately 200KB of data — negligible memory impact. The in-memory aggregation loop is O(n) with hash-table lookups.

**Mitigation (if needed later):** Push aggregation to SQL with `GROUP BY`:

```sql
SELECT beat, is_active, COUNT(*) as cnt
FROM fire_incidents
WHERE dispatch_time >= ?
AND beat IS NOT NULL
GROUP BY beat, is_active
```

This reduces the result set to at most `(number of distinct beats) * 2` rows. This optimization is not included in the initial implementation because the expected data volume does not warrant it.

### 7. Activity Thresholds Config Out of Order

**Scenario:** The `activity_thresholds` config is modified to have thresholds in ascending order instead of descending.

**Behavior:** The first-match logic would always match the lowest threshold, making all non-zero zones `normal`. This is a config error, not a code error.

**Mitigation:** The service sorts thresholds by value descending before iterating, making the config order irrelevant:

```php
$thresholds = config('zones.activity_thresholds', []);
arsort($thresholds);
```

---

## Tests

### New Test Files

#### `tests/Unit/Services/Zones/ZoneStatsServiceTest.php`

Pest unit tests for `ZoneStatsService`. Uses `RefreshDatabase` trait and model factories.

| Test | Description |
|------|-------------|
| `returns all zones with zero counts when no alerts exist` | No incidents/calls in DB. Assert 6 ZoneStats DTOs with `totalCount: 0` and `activityStatus: 'monitoring'`. |
| `counts fire incidents by beat mapping` | Create fire incidents with known beat codes mapped to different zones. Assert correct per-zone `activeCount` and `clearedCount`. |
| `counts police calls by division mapping` | Create police calls with mapped division codes. Assert per-zone counts. |
| `aggregates both fire and police into same zone` | Create fire incident (beat mapped to `downtown-core`) and police call (division mapped to `downtown-core`). Assert combined count. |
| `excludes fire incidents with unmapped beat codes` | Create incident with `beat = 'UNMAPPED'`. Assert all zones have `totalCount: 0`. |
| `excludes police calls with unmapped division codes` | Create call with `division = 'D99'`. Assert all zones have `totalCount: 0`. |
| `excludes alerts with null beat or division` | Create fire incident with `beat = null` and police call with `division = null`. Assert all zones have `totalCount: 0`. |
| `excludes alerts outside 24-hour window` | Create incident with `dispatch_time` 25 hours ago. Assert zone has `totalCount: 0`. |
| `includes alerts at exactly 24-hour boundary` | Create incident with `dispatch_time` exactly 24 hours ago. Assert it is counted. |
| `correctly distinguishes active and cleared alerts` | Create 3 active and 2 cleared incidents in the same zone. Assert `activeCount: 3`, `clearedCount: 2`, `totalCount: 5`. |
| `applies activity thresholds correctly` | Create incidents to hit each threshold boundary (0, 1, 2, 5, 10). Assert each zone gets the expected `activityStatus`. |
| `returns zones in config definition order` | Assert the returned array order matches `config('zones.definitions')` order regardless of which zones have higher counts. |
| `uses custom hoursBack parameter` | Call `getStats(hoursBack: 1)`. Create incident at 30 min ago (included) and 90 min ago (excluded). Assert correctly. |

#### `tests/Unit/Http/Resources/ZoneStatsResourceTest.php`

| Test | Description |
|------|-------------|
| `transforms ZoneStats DTO to expected JSON shape` | Create a `ZoneStats` DTO, wrap in `ZoneStatsResource`, assert `toArray()` output matches expected keys and values with snake_case field names. |

#### `tests/Feature/GtaAlertsTest.php` (Existing — Add Tests)

| Test | Description |
|------|-------------|
| `the home page provides zone_stats data` | Create fire incidents and police calls with mapped beat/division codes. Assert `zone_stats` is present in Inertia props with correct structure and counts. |
| `zone_stats contains all 6 zones even with no alerts` | No factory data. Assert `zone_stats` has 6 items with `total_count: 0`. |

### Existing Tests — No Modifications Required

The following existing test files require **no changes**:

- `tests/Feature/GtaAlertsTest.php` — Existing tests assert specific props (`alerts`, `filters`, `latest_feed_updated_at`). The additive `zone_stats` prop does not break any assertions.
- `tests/Unit/Models/FireIncidentTest.php` — Model structure unchanged.
- `tests/Unit/Models/PoliceCallTest.php` — Model structure unchanged.
- `tests/Unit/Services/Alerts/Providers/*` — Provider queries unchanged.
- `tests/Feature/UnifiedAlerts/*` — UNION query unchanged.
- All auth, settings, and command tests — Unrelated to zones.

---

## Data Contract

### Inertia Props (Complete)

After implementation, the `gta-alerts` Inertia page receives:

```typescript
{
    alerts: {
        data: UnifiedAlertResource[],
        links: Record<string, string | null>,
        meta: Record<string, unknown>,
    },
    filters: {
        status: 'all' | 'active' | 'cleared',
    },
    latest_feed_updated_at: string | null,
    zone_stats: ZoneStatsResource[],   // NEW
}
```

### ZoneStatsResource TypeScript Interface

```typescript
interface ZoneStatsResource {
    id: string;
    name: string;
    active_count: number;
    cleared_count: number;
    total_count: number;
    activity_status: 'high_activity' | 'moderate' | 'low_activity' | 'normal' | 'monitoring';
}
```

### Example `zone_stats` Payload

```json
[
    {
        "id": "downtown-core",
        "name": "Downtown Core",
        "active_count": 8,
        "cleared_count": 4,
        "total_count": 12,
        "activity_status": "high_activity"
    },
    {
        "id": "scarborough",
        "name": "Scarborough",
        "active_count": 3,
        "cleared_count": 2,
        "total_count": 5,
        "activity_status": "moderate"
    },
    {
        "id": "north-york",
        "name": "North York",
        "active_count": 1,
        "cleared_count": 1,
        "total_count": 2,
        "activity_status": "low_activity"
    },
    {
        "id": "etobicoke",
        "name": "Etobicoke",
        "active_count": 1,
        "cleared_count": 0,
        "total_count": 1,
        "activity_status": "normal"
    },
    {
        "id": "peel-region",
        "name": "Peel Region",
        "active_count": 0,
        "cleared_count": 0,
        "total_count": 0,
        "activity_status": "monitoring"
    },
    {
        "id": "york-region",
        "name": "York Region",
        "active_count": 0,
        "cleared_count": 0,
        "total_count": 0,
        "activity_status": "monitoring"
    }
]
```

---

## File Summary

| Action | File | Purpose |
|--------|------|---------|
| **Create** | `config/zones.php` | Zone definitions, beat/division mappings, thresholds |
| **Create** | `app/Services/Zones/DTOs/ZoneStats.php` | Readonly DTO for zone statistics |
| **Create** | `app/Services/Zones/ZoneStatsService.php` | Query + aggregation service |
| **Create** | `app/Http/Resources/ZoneStatsResource.php` | JSON serialization resource |
| **Create** | `tests/Unit/Services/Zones/ZoneStatsServiceTest.php` | Unit tests for service |
| **Create** | `tests/Unit/Http/Resources/ZoneStatsResourceTest.php` | Unit test for resource |
| **Modify** | `app/Http/Controllers/GtaAlertsController.php` | Inject service, add `zone_stats` prop |
| **Modify** | `resources/js/features/gta-alerts/types.ts` | Add `ZoneStatsResource` interface |
| **Modify** | `resources/js/pages/gta-alerts.tsx` | Thread `zone_stats` prop |
| **Modify** | `resources/js/features/gta-alerts/App.tsx` | Thread `zoneStats` prop to `ZonesView` |
| **Modify** | `resources/js/features/gta-alerts/components/ZonesView.tsx` | Replace hard-coded data with props |
