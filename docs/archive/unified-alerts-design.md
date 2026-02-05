# Unified Alerts Architecture (Provider & Adapter)

This document outlines an architecture for unifying divergent emergency data sources (Fire, Police, etc.) into a single, cohesive feed while maintaining domain separation.

## Current State (as of this codebase)

- The public landing page is the GTA Alerts experience (home route `/`), served by `App\Http\Controllers\GtaAlertsController`.
- Today, GTA Alerts only loads Toronto Fire incidents from the backend as a paginated `FireIncidentResource` collection (`incidents` prop).
- The GTA Alerts UI is already designed around a frontend view-model called `AlertItem` (not `UnifiedAlert`), and it maps backend resources into that view-model via `AlertService.mapIncidentToAlertItem()`.
- Search/filtering in the GTA Alerts UI is client-side (in-memory) via `AlertService.search()` and `FeedView`; the current backend `search` query param only affects the initial dataset returned by `GtaAlertsController`.

This means the “unified alerts” backend DTO is best treated as a transport shape (backend-to-frontend), while the UI continues to use `AlertItem` as the view-model.

## Design Decisions

For deeper rationale, see `docs/Unified-Alerts-Architecture-QA.md`.

- **Read-time unification:** Use a UNION query (MySQL) to produce a single unified feed.
- **True pagination over history:** Page over active + cleared alerts by default (`status=all`).
- **Deterministic ordering:** Sort by `timestamp` plus tie-breakers so pagination is stable.
- **Keep UI view-model:** Frontend continues to render `AlertItem` and maps transport → view-model in `AlertService`.

## Core Architecture Pattern: Provider & Adapter

To avoid tight coupling in the Controller and to enable easy extension (e.g., adding Transit alerts later), we use a **Provider** pattern.

1.  **Sources (Models):** Keep raw data in specific models (`FireIncident`, `PoliceCall`).
2.  **Adapters (Providers):** Transform raw models into a unified transport DTO (`UnifiedAlert`).
3.  **Aggregator:** Collects, sorts, and delivers the unified list to the controller/page.
4.  **UI (View-model):** Frontend maps the transport DTO into `AlertItem` and renders existing GTA Alerts components.

---

## Backend Specification (Laravel)

### 1. The Transport DTO (Contract)

**Namespace:** `App\Services\Alerts\DTOs`
**File:** `UnifiedAlert.php`

A strict Value Object that defines the transport shape of an alert. This DTO is intentionally UI-agnostic; presentation concerns (icons, accent colors, “time ago” strings, etc.) stay in the frontend `AlertItem` mapping layer.

```php
namespace App\Services\Alerts\DTOs;

use Carbon\CarbonImmutable;

readonly class UnifiedAlert
{
    public function __construct(
        public string $id,                   // Global unique ID (e.g., "fire:EVENT_NUM" or "police:OBJECTID")
        public string $source,               // "fire", "police" (future: "transit", etc.)
        public string $externalId,           // Original ID from the source (event_num / object_id)
        public bool $isActive,               // Active vs cleared (history is included in paging)
        public CarbonImmutable $timestamp,   // When it occurred (dispatch/occurrence time)
        public string $title,                // Primary label (e.g., event type / call type)
        public ?AlertLocation $location,     // Optional; may be null when only free-text exists
        public array $meta = []              // Source-specific fields (alarm_level, division, etc.)
    ) {}
}
```

**Note:** With the UNION approach, the unified feed is typically retrieved as database rows (not as hydrated Eloquent models). You can either (a) transform rows directly in `UnifiedAlertResource`, or (b) map each row into this DTO if you want stronger typing at the service boundary.

**Nested DTO:** `AlertLocation.php`
```php
readonly class AlertLocation
{
    public function __construct(
        public ?string $name,           // Free-text location label (e.g., "Yonge St / Dundas St")
        public ?float $lat = null,
        public ?float $lng = null,
        public ?string $postalCode = null
    ) {}
}
```

### 2. The Interface (Select Provider)

**Namespace:** `App\Services\Alerts\Contracts`
**File:** `AlertSelectProvider.php`

Each source supplies a standardized SELECT that can be UNION’d and paginated.

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

### 3. Implementations (Select Providers)

**Namespace:** `App\Services\Alerts\Providers`

#### `FireAlertSelectProvider.php`
*   **Dependency:** `App\Models\FireIncident`
*   **Logic:**
    *   Fetch `FireIncident::query()` (do not scope to active; history is included by default).
    *   Order by `dispatch_time` desc.
    *   Return a standardized SELECT using:
        *   `external_id`: `event_num`
        *   `id`: `fire:{event_num}`
        *   `is_active`: `is_active`
        *   `timestamp`: `dispatch_time`
        *   `title`: `event_type`
        *   `location_name`: build from `prime_street` + `cross_streets`
        *   `lat/lng`: `NULL`
        *   `meta`: include `alarm_level`, `units_dispatched`, `beat`, `event_num`
    *   **Note:** Severity is currently a frontend concern (`AlertItem['severity']`), derived from `alarm_level` in `AlertService`.

#### `PoliceAlertSelectProvider.php`
*   **Dependency:** `App\Models\PoliceCall`
*   **Logic:**
    *   Fetch `PoliceCall::query()` (do not scope to active; history is included by default).
    *   Order by `occurrence_time` desc.
    *   Return a standardized SELECT using:
        *   `external_id`: `object_id`
        *   `id`: `police:{object_id}`
        *   `is_active`: `is_active`
        *   `timestamp`: `occurrence_time`
        *   `title`: `call_type` (and/or `call_type_code`)
        *   `location_name`: `cross_streets` (if present)
        *   `lat/lng`: `latitude` / `longitude` (if present)
        *   `meta`: include `division`, `call_type_code`, `object_id`
    *   **Note:** If we want “police” category chips to be accurate, `AlertService.normalizeType()` should eventually learn how to classify police calls based on `call_type`/`call_type_code` (frontend).

### 4. Unified Alerts Query (UNION + True Pagination)

**Namespace:** `App\Services\Alerts`
**File:** `UnifiedAlertsQuery.php`

Because the dashboard must support true pagination over **history** (active + cleared) and we are staying on MySQL, we use a database-backed UNION query rather than an in-memory “collect and sort”.

Key requirements this enables:

- **History paging:** include both `is_active = 1` and `is_active = 0` by default (`status=all`).
- **Stable ordering:** order by `timestamp` plus a deterministic tie-breaker (so page boundaries don’t drift).
- **Server pagination:** paginate at the DB layer, not after fetching everything.

Implementation sketch (query-builder UNION):

```php
use App\Services\Alerts\Providers\FireAlertSelectProvider;
use App\Services\Alerts\Providers\PoliceAlertSelectProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Pagination\LengthAwarePaginator;

class UnifiedAlertsQuery
{
    public function __construct(
        private readonly FireAlertSelectProvider $fire,
        private readonly PoliceAlertSelectProvider $police,
    ) {}

    public function paginate(
        int $perPage = 50,
        string $status = 'all', // all|active|cleared
    ): LengthAwarePaginator {
        $union = $this->fire->select()->unionAll($this->police->select());

        $query = DB::query()->fromSub($union, 'unified_alerts');

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'cleared') {
            $query->where('is_active', false);
        }

        return $query
            ->orderByDesc('timestamp')
            ->orderBy('source')
            ->orderByDesc('external_id')
            ->paginate($perPage);
    }
}
```

Notes:

- The above can be implemented either as a query-builder UNION or as a DB view; both are “UNION approach”.
- The standardized SELECT returns flat columns (`location_name`, `lat`, `lng`, `meta`). `UnifiedAlertResource` is responsible for shaping these into the nested `location` object expected by the frontend.
- If OFFSET pagination becomes slow at deep history pages, switch to cursor/keyset pagination using `(timestamp, source, external_id)` as the cursor.

### 5. Controller Refactoring

**File:** `App\Http\Controllers\GtaAlertsController.php`

Refactor to inject `UnifiedAlertsQuery` for the public GTA Alerts page.

To keep the migration low-risk, you can temporarily return both props:
- existing `incidents` (current FireIncident feed), and
- new `alerts` (unified transport DTOs transformed for the frontend).

```php
public function __invoke(Request $request, UnifiedAlertsQuery $alerts): Response
{
    $status = $request->string('status')->toString() ?: 'all';
    $perPage = (int) ($request->input('per_page', 50));

    $results = $alerts->paginate(perPage: $perPage, status: $status);

    return Inertia::render('gta-alerts', [
        'alerts' => UnifiedAlertResource::collection($results),
        'filters' => [
            'status' => $status, // default: all
        ],
    ]);
}
```

---

## Frontend Specification (React + Inertia)

### 1. Types (Transport vs View-model)

**Existing file:** `resources/js/features/gta-alerts/types.ts`

```typescript
// View-model used by existing UI components.
export interface AlertItem {
  id: string;
  title: string;
  location: string;
  timeAgo: string;
  description: string;
  type: 'fire' | 'police' | 'transit' | 'hazard' | 'medical';
  severity: 'high' | 'medium' | 'low';
  status?: 'active' | 'cleared'; // derived from UnifiedAlertResource.is_active (recommended for history UX)
  iconName: string;
  accentColor: string;
  metadata?: {
    eventNum: string;
    alarmLevel: number;
    unitsDispatched: string | null;
    beat: string | null;
  };
}

// Current backend resource feeding the UI (Toronto Fire only).
export interface IncidentResource {
  id: number;
  event_num: string;
  event_type: string;
  prime_street: string;
  cross_streets: string | null;
  dispatch_time: string;
  alarm_level: number;
  beat: string | null;
  units_dispatched: string | null;
  is_active: boolean;
  feed_updated_at: string;
}

// Proposed unified backend transport shape (recommended add alongside IncidentResource).
export interface UnifiedAlertResource {
  id: string;
  source: 'fire' | 'police';
  external_id: string;
  is_active: boolean;
  timestamp: string; // ISO 8601
  title: string;
  location: {
    name: string | null;
    lat: number | null;
    lng: number | null;
  } | null;
  meta: Record<string, unknown>;
}
```

**Mapping rule:** keep UI components consuming `AlertItem`. Update `AlertService` to add `mapUnifiedAlertToAlertItem(unified: UnifiedAlertResource): AlertItem` while keeping `mapIncidentToAlertItem()` for backward compatibility during rollout. The mapper should also derive `AlertItem.status` from `unified.is_active` so the UI can clearly display “Cleared” items in history.

### 2. Component Structure (Current GTA Alerts)

**Directory:** `resources/js/features/gta-alerts/components/`

#### `FeedView.tsx`
*   Uses `AlertService.search()` for client-side search and filtering.
*   Renders a list of `AlertCard` entries.

#### `AlertCard.tsx`
*   Accepts `item: AlertItem` and renders the full card shell/content.

#### `AlertDetailsView.tsx`
*   Selects specialized detail renderers based on `alert.type` (Fire/Police/Default).

---

## Testing Strategy

### 1. Unit Testing (Backend)

**Target:** `UnifiedAlertsQuery`
*   **Method:** Seed both `fire_incidents` and `police_calls`.
*   **Test:** Ensure it returns a single mixed feed ordered by `timestamp` (with a deterministic tie-breaker) and that `status=all` includes both active and cleared rows.
*   **Tool:** Pest PHP.

```php
it('paginates unified alerts deterministically', function () {
    // Arrange: create fire incidents + police calls with known timestamps.
    // Act: call UnifiedAlertsQuery::paginate(perPage: 2, status: 'all').
    // Assert: ordering + presence of both active and cleared rows.
});
```

### 2. Integration Testing (Source Sync + History)

**Targets:** `FetchFireIncidentsCommand`, `FetchPoliceCallsCommand`
*   **Method:** Use database factories and fake HTTP responses.
*   **Test:** Ensure scrapes/upserts do not delete old rows and that missing items are marked `is_active = false` so history remains paginatable.

### 3. Frontend Component Tests

**Targets (already present):**
*   `AlertService` mapping tests (`mapIncidentToAlertItem`, search filtering).
*   `AlertCard` render/click behavior.
*   `FeedView` list/empty-state behavior.

**Proposed add:** tests for `mapUnifiedAlertToAlertItem()` once `UnifiedAlertResource` is introduced.

---

## Implementation Checklist

1.  [ ] **Backend:** Create `UnifiedAlert` and `AlertLocation` DTOs.
2.  [ ] **Backend:** Create `AlertSelectProvider` interface.
3.  [ ] **Backend:** Implement `FireAlertSelectProvider` & `PoliceAlertSelectProvider`.
4.  [ ] **Backend:** Implement `UnifiedAlertsQuery` (UNION + pagination, default `status=all`).
5.  [ ] **Backend:** Create `UnifiedAlertResource` (API transformer for unified rows/DTOs).
6.  [ ] **Backend:** Update `GtaAlertsController` to provide `alerts` (optionally alongside existing `incidents` during rollout), and default `filters.status` to `all`.
7.  [ ] **Frontend:** Add `UnifiedAlertResource` type (keep `AlertItem` as the UI view-model).
8.  [ ] **Frontend:** Add `AlertService.mapUnifiedAlertToAlertItem()` and update the app to consume `alerts` when present.
9.  [ ] **Frontend:** Keep existing `AlertCard` / `FeedView` structure (no “card body registry” required).
