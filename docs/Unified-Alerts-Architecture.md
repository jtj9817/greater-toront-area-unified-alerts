# Unified Alerts Architecture (Provider & Adapter)

This document outlines the architecture for unifying divergent emergency data sources (Fire, Police, etc.) into a single, cohesive feed while maintaining domain separation.

## Core Architecture Pattern: Provider & Adapter

To avoid tight coupling in the Controller and to enable easy extension (e.g., adding Transit alerts later), we use a **Provider** pattern.

1.  **Sources (Models):** Keep raw data in specific models (`FireIncident`, `PoliceCall`).
2.  **Adapters (Providers):** Transform raw models into a unified `UnifiedAlert` DTO.
3.  **Aggregator:** Collects, sorts, and delivers the unified list to the Controller.
4.  **UI (Polymorphic):** Frontend renders a common "Shell" and specific "Bodies" based on the source.

---

## Backend Specification (Laravel)

### 1. The Contract (DTO)

**Namespace:** `App\Services\Alerts\DTOs`
**File:** `UnifiedAlert.php`

A strict Value Object that defines the shape of an alert.

```php
namespace App\Services\Alerts\DTOs;

use Carbon\CarbonImmutable;

readonly class UnifiedAlert
{
    public function __construct(
        public string $id,              // Unique Global ID (e.g., "fire_F26015952")
        public string $source,          // "fire", "police", "transit"
        public string $externalId,      // Original ID from source
        public string $title,           // Main display text (e.g., "Residential Fire", "Assault")
        public CarbonImmutable $timestamp,
        public AlertLocation $location, // Nested DTO
        public int $severity,           // Normalized 1 (low) to 5 (critical)
        public array $meta              // Source-specific data (units, alarm_level, etc.)
    ) {}
}
```

**Nested DTO:** `AlertLocation.php`
```php
readonly class AlertLocation
{
    public function __construct(
        public ?string $name,           // "Yonge St / Dundas St"
        public ?float $lat = null,
        public ?float $lng = null,
        public ?string $postalCode = null
    ) {}
}
```

### 2. The Interface (Provider)

**Namespace:** `App\Services\Alerts\Contracts`
**File:** `AlertProvider.php`

```php
namespace App\Services\Alerts\Contracts;

use Illuminate\Support\Collection;

interface AlertProvider
{
    /**
     * @return Collection<int, \App\Services\Alerts\DTOs\UnifiedAlert>
     */
    public function getActiveAlerts(): Collection;
}
```

### 3. Implementations

**Namespace:** `App\Services\Alerts\Providers`

#### `FireAlertProvider.php`
*   **Dependency:** `App\Models\FireIncident`
*   **Logic:**
    *   Fetch `FireIncident::active()->get()`.
    *   Map to `UnifiedAlert`.
    *   **Severity Mapping:**
        *   Alarm Level 0 -> Severity 1
        *   Alarm Level 1 -> Severity 3
        *   Alarm Level 2+ -> Severity 5

#### `PoliceAlertProvider.php`
*   **Dependency:** `App\Models\PoliceCall`
*   **Logic:**
    *   Fetch `PoliceCall::active()->get()`.
    *   Map to `UnifiedAlert`.
    *   **Severity Mapping:**
        *   "Shooting", "Stabbing" -> Severity 5
        *   "Assault" -> Severity 3
        *   "Disorderly" -> Severity 1

### 4. The Aggregator

**Namespace:** `App\Services\Alerts`
**File:** `AlertAggregator.php`

```php
class AlertAggregator
{
    protected array $providers;

    public function __construct(
        FireAlertProvider $fire,
        PoliceAlertProvider $police
    ) {
        $this->providers = [$fire, $police];
    }

    public function fetchAll(): Collection
    {
        return collect($this->providers)
            ->flatMap(fn (AlertProvider $p) => $p->getActiveAlerts())
            ->sortByDesc(fn (UnifiedAlert $a) => $a->timestamp->getTimestamp())
            ->values();
    }
}
```

### 5. Controller Refactoring

**File:** `App\Http\Controllers\DashboardController.php`

Refactor to inject `AlertAggregator`.

```php
public function __invoke(AlertAggregator $aggregator): Response
{
    $alerts = $aggregator->fetchAll();

    // Calculate generic stats based on the unified collection
    $stats = [
        'total' => $alerts->count(),
        'by_type' => $alerts->groupBy('source')->map->count(),
    ];

    return Inertia::render('dashboard', [
        'alerts' => UnifiedAlertResource::collection($alerts)->resolve(),
        'stats' => $stats,
    ]);
}
```

---

## Frontend Specification (React + Inertia)

### 1. Types

**File:** `resources/js/features/gta-alerts/types/index.ts`

```typescript
export interface AlertLocation {
    name: string | null;
    lat: number | null;
    lng: number | null;
}

export interface UnifiedAlert {
    id: string;
    source: 'fire' | 'police' | 'transit';
    title: string;
    timestamp: string; // ISO 8601
    location: AlertLocation;
    severity: 1 | 2 | 3 | 4 | 5;
    meta: Record<string, any>;
}
```

### 2. Component Structure

**Directory:** `resources/js/features/gta-alerts/components/`

#### `AlertCard.tsx` (Polymorphic Container)
Responsibility:
*   Renders common shell (Border, Header, Timestamp, Severity Badge).
*   Determines which "Body" component to render based on `alert.source`.

```tsx
import { FireCardBody } from './cards/FireCardBody';
import { PoliceCardBody } from './cards/PoliceCardBody';

const BODY_REGISTRY = {
    fire: FireCardBody,
    police: PoliceCardBody,
};

export function AlertCard({ alert }: { alert: UnifiedAlert }) {
    const BodyComponent = BODY_REGISTRY[alert.source] || GenericCardBody;
    
    return (
        <Card className="mb-4">
             <AlertHeader alert={alert} />
             <CardContent>
                 <BodyComponent meta={alert.meta} />
             </CardContent>
        </Card>
    );
}
```

#### `cards/FireCardBody.tsx`
*   Displays: `Alarm Level`, `Units Dispatched`.

#### `cards/PoliceCardBody.tsx`
*   Displays: `Division`, `Event Type Code`.

---

## Testing Strategy

### 1. Unit Testing (Backend)

**Target:** `AlertAggregator`
*   **Method:** Mock `AlertProvider`.
*   **Test:** Ensure it calls all providers and sorts the result correctly by timestamp.
*   **Tool:** Pest PHP.

```php
it('aggregates and sorts alerts', function () {
    $mockFire = Mockery::mock(FireAlertProvider::class);
    $mockFire->shouldReceive('getActiveAlerts')->andReturn(collect([/* ... */]));
    
    // ...
});
```

### 2. Integration Testing (Providers)

**Target:** `FireAlertProvider`, `PoliceAlertProvider`
*   **Method:** Use Database Factories (`FireIncidentFactory`).
*   **Test:** Create 5 incidents (2 active, 3 inactive) in DB. Assert Provider returns exactly 2 `UnifiedAlert` objects with correct mapped fields.

### 3. Frontend Component Tests

**Target:** `AlertCard`
*   **Method:** Render `AlertCard` with a Fire alert mock. Assert "Units Dispatched" is visible. Render with Police alert mock. Assert "Division" is visible.

---

## Implementation Checklist

1.  [ ] **Backend:** Create `UnifiedAlert` and `AlertLocation` DTOs.
2.  [ ] **Backend:** Create `AlertProvider` interface.
3.  [ ] **Backend:** Implement `FireAlertProvider` & `PoliceAlertProvider`.
4.  [ ] **Backend:** Implement `AlertAggregator`.
5.  [ ] **Backend:** Create `UnifiedAlertResource` (API Transformer).
6.  [ ] **Backend:** Update `DashboardController` to use Aggregator.
7.  [ ] **Frontend:** Create TypeScript interfaces.
8.  [ ] **Frontend:** Create `FireCardBody` and `PoliceCardBody`.
9.  [ ] **Frontend:** Create `AlertCard` container.
10. [ ] **Frontend:** Update `dashboard.tsx` to use the new `alerts` prop.
