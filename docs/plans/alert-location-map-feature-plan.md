# Feature Plan: Leaflet + OpenStreetMap Alert Location Map

**Created**: 2026-03-26
**Completed**: Not Started
**Status**: 🔴 Not Started
**Purpose**: Replace the current alert-details map placeholder with a real Leaflet-based OpenStreetMap map for alerts that already have latitude and longitude.

---

## Problem Statement
The GTA Alerts details view currently exposes a "Location Map" concept, but the implementation stops short of a working map.

1. **Placeholder Instead of Product Behavior**: The fire details branch renders a decorative "Interactive Map Loading..." card instead of a live map.
2. **Coordinate Data Is Not Preserved to the Presentation Layer**: Unified alerts can carry `location.name`, `location.lat`, and `location.lng`, but the current presentation mapper collapses that into a single string label.
3. **Uneven Provider Support**: Police alerts already expose real coordinates, while Fire, TTC, and GO Transit generally emit text-only locations with `lat` and `lng` set to `null`.
4. **No Clear Fallback UX**: Alerts without coordinates do not have a defined user-facing behavior for the location feature beyond the existing placeholder in the fire branch.

As a result, the app communicates that a map exists while not actually rendering one. A scoped v1 should deliver a real map wherever coordinates already exist and a clear fallback when they do not.

---

## Design Decisions (Engineering Preferences)

| Decision | Choice |
| :--- | :--- |
| **Map Library** | `leaflet` + `react-leaflet` |
| **Tile Source (v1)** | OpenStreetMap raster tiles via `https://tile.openstreetmap.org/{z}/{x}/{y}.png` |
| **Implementation Scope** | Frontend-only for v1; no geocoding or backend schema changes |
| **Map Eligibility Rule** | Render map only when both `lat` and `lng` are finite numbers |
| **Location Fallback** | Show explicit "Map unavailable for this alert" state when coordinates are missing |
| **Detail View Strategy** | Replace fire-only placeholder with a shared location map section reusable across alert types |
| **Coordinate Transport Strategy** | Preserve raw coordinates through the presentation layer instead of re-querying data |
| **SSR Strategy** | Treat the map as client-only UI because Inertia SSR is enabled in this application |
| **Tile Hosting Strategy** | Use the public OSM tile server for initial rollout, with an easy swap path to a hosted OSM-compatible provider later |
| **Security Header Strategy** | No CSP change for v1 if raster tiles remain cross-origin images only |
| **Testing Strategy** | Vitest component coverage with map component mocking; no manual-only verification |

---

## Solution Architecture

### Overview
```text
UnifiedAlertsQuery / Feed API
        |
        v
UnifiedAlertResource
  location: { name, lat, lng }
        |
        v
fromResource() -> DomainAlert
        |
        v
mapDomainAlertToPresentation()
  preserve:
  - location label
  - location coordinates
        |
        v
AlertDetailsView
        |
        +--> if coords exist --> AlertLocationMap
        |                         |
        |                         +--> Leaflet MapContainer
        |                         +--> OSM TileLayer
        |                         +--> Marker + popup
        |
        +--> if coords missing --> Map unavailable state
```

### Current State vs Target State
```text
Current:
Provider coords -> API resource -> DomainAlert -> presentation string only -> placeholder UI

Target:
Provider coords -> API resource -> DomainAlert -> presentation coords retained -> real map UI
```

---

## Current Codebase Findings

### Existing Backend Data Flow
- `app/Services/Alerts/Providers/PoliceAlertSelectProvider.php`
  - Already selects `latitude` and `longitude` into unified `lat` / `lng`.
- `app/Services/Alerts/Providers/FireAlertSelectProvider.php`
  - Emits formatted street text but explicitly sets `lat` / `lng` to `NULL`.
- `app/Services/Alerts/Providers/TransitAlertSelectProvider.php`
  - Emits text location only; no coordinates.
- `app/Services/Alerts/Providers/GoTransitAlertSelectProvider.php`
  - Emits corridor/route text only; no coordinates.
- `app/Services/Alerts/Mappers/UnifiedAlertMapper.php`
  - Preserves `name`, `lat`, and `lng` in the `AlertLocation` DTO.
- `app/Http/Resources/UnifiedAlertResource.php`
  - Serializes `location` to the frontend as `{ name, lat, lng }`.

### Existing Frontend Data Flow
- `resources/js/features/gta-alerts/domain/alerts/resource.ts`
  - Zod schema already validates `location.name`, `location.lat`, and `location.lng`.
- `resources/js/features/gta-alerts/domain/alerts/mapperUtils.ts`
  - Passes the full `location` object through into domain alert construction.
- `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.ts`
  - Drops raw coordinates and retains only a string label.
- `resources/js/features/gta-alerts/domain/alerts/view/types.ts`
  - Presentation type has no field for coordinates.
- `resources/js/features/gta-alerts/components/AlertDetailsView.tsx`
  - Contains the current fire-only placeholder card for "Location Map".
- `resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx`
  - Already constructs alerts with coordinates in test fixtures, so there is an existing seam for real map behavior.

### Runtime and Security Findings
- `resources/js/app.tsx`
  - Single frontend entry point; correct place to import Leaflet CSS once globally through Vite.
- `resources/js/ssr.tsx`
  - Inertia SSR is configured, so browser-only map code must not be rendered eagerly on the server.
- `vite.config.ts`
  - Vite is configured with both `resources/js/app.tsx` and `resources/js/ssr.tsx`, confirming the feature must be safe for both client and SSR builds.
- `app/Http/Middleware/EnsureSecurityHeaders.php`
  - `img-src` already allows `https:`, which is sufficient for raster tile images.
  - `connect-src` is currently restrictive, so v1 should avoid vector-tile or fetch-heavy patterns that would require CSP expansion.
- `tests/Feature/Security/SecurityHeadersTest.php`
  - Existing CSP coverage should remain stable if the implementation stays on raster tiles.
- `app/Http/Controllers/GtaAlertsController.php` and `app/Http/Controllers/Api/FeedController.php`
  - No new props or controller changes are required for v1 because the unified alert resource already serializes `location`.
- `resources/js/features/gta-alerts/App.tsx`
  - Existing Inertia partial reloads already request the `alerts` prop, so the map feature should ride on the same prop without introducing a new page prop key.

---

## External Constraints and References

1. **Leaflet setup requirements**
   - Leaflet requires its CSS to be loaded and the map container to have a defined height.
   - Source: https://leafletjs.com/examples/quick-start/

2. **React Leaflet integration requirements**
   - React Leaflet does not replace Leaflet; it assumes a working Leaflet setup.
   - TypeScript support requires `@types/leaflet`.
   - TypeScript imports should come from the package entry-point, not subpath imports, to retain type support.
   - Source: https://react-leaflet.js.org/docs/start-installation/
   - Source: https://react-leaflet.js.org/docs/start-setup/

3. **OpenStreetMap tile usage policy**
   - Public OSM tile servers are not an SLA-backed production service.
   - The exact recommended URL is `https://tile.openstreetmap.org/{z}/{x}/{y}.png`.
   - Attribution must remain visible.
   - Source: https://operations.osmfoundation.org/policies/tiles/

These constraints make raster OSM tiles acceptable for a simple v1, while also making it important to keep the tile URL configurable so the app can move to a hosted OSM-compatible provider later without a refactor.

4. **Laravel Vite CSP nonce behavior**
   - Laravel automatically applies CSP nonces to generated Vite script and style tags when `Vite::useCspNonce(...)` is active in middleware.
   - This reinforces that Leaflet CSS should be bundled through Vite imports instead of being added by a hard-coded external `<link>` tag.
   - Source: Laravel Boost docs search result for `vite.md > Script and Style Tag Attributes > Content Security Policy (CSP) Nonce`

5. **Inertia partial reload behavior**
   - The `only` option returns only selected props on subsequent visits to the same page component.
   - Because the map uses data already inside `alerts`, no additional Inertia prop key is needed for v1.
   - Source: Laravel Boost docs search result for `data-props/partial-reloads > Only Certain Props`

---

## Implementation Tasks

### Phase 1: Dependency and Frontend Foundation 🔴

#### Task 1.1: Add Mapping Dependencies 🔴
**File**: `package.json`

```json
{
  "dependencies": {
    "leaflet": "^1.9.x",
    "react-leaflet": "^5.x"
  },
  "devDependencies": {
    "@types/leaflet": "^1.9.x"
  }
}
```

**Key Logic/Responsibilities**:
- Add `leaflet` and `react-leaflet` as runtime dependencies.
- Add `@types/leaflet` for TypeScript support.
- Keep versions aligned with current React and bundler behavior instead of pinning to stale examples.
- Use the package entry-point imports in TypeScript-facing components to preserve typings.

#### Task 1.2: Load Leaflet CSS Once at the App Entry Point 🔴
**File**: `resources/js/app.tsx`

```tsx
import '../css/app.css';
import 'leaflet/dist/leaflet.css';
```

**Key Logic/Responsibilities**:
- Import Leaflet CSS globally from the app entry point rather than per-component or CDN markup.
- Prevent duplicated CSS imports and avoid layout drift between detail branches.
- Keep the stylesheet inside the normal Laravel Vite pipeline so the existing CSP nonce flow continues to apply cleanly.

#### Task 1.3: Add Shared Leaflet Asset Configuration 🔴
**File**: `resources/js/features/gta-alerts/lib/leaflet.ts`

```ts
import L from 'leaflet';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';

delete (L.Icon.Default.prototype as { _getIconUrl?: unknown })._getIconUrl;

L.Icon.Default.mergeOptions({
    iconRetinaUrl: markerIcon2x,
    iconUrl: markerIcon,
    shadowUrl: markerShadow,
});
```

**Key Logic/Responsibilities**:
- Solve the common Vite/Webpack Leaflet marker icon resolution problem up front.
- Centralize Leaflet runtime configuration so it is initialized once and reused by any future map surfaces.

---

### Phase 2: Preserve Coordinates Through the Presentation Boundary 🔴

#### Task 2.1: Extend Alert Presentation Types to Keep Coordinates 🔴
**File**: `resources/js/features/gta-alerts/domain/alerts/view/types.ts`

```ts
export interface AlertPresentationCoordinates {
    lat: number;
    lng: number;
}

export interface AlertPresentation {
    id: string;
    title: string;
    location: string;
    locationCoords: AlertPresentationCoordinates | null;
    timeAgo: string;
    timestamp: string;
    description: string;
    type: AlertPresentationType;
    severity: AlertPresentationSeverity;
    iconName: string;
    accentColor: string;
    iconColor: string;
    metadata?: AlertPresentationMetadata;
}
```

**Key Logic/Responsibilities**:
- Preserve the existing `location` string for UI copy.
- Add a dedicated normalized coordinates field so rendering logic does not have to infer from raw nested location objects.

#### Task 2.2: Map Raw Domain Location into Presentation Coordinates 🔴
**File**: `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.ts`

```ts
const hasCoordinates =
    typeof alert.location?.lat === 'number' &&
    Number.isFinite(alert.location.lat) &&
    typeof alert.location?.lng === 'number' &&
    Number.isFinite(alert.location.lng);

return {
    id: alert.id,
    title: alert.title,
    location: alert.location?.name?.trim() || 'Unknown location',
    locationCoords: hasCoordinates
        ? { lat: alert.location!.lat!, lng: alert.location!.lng! }
        : null,
    // ...
};
```

**Key Logic/Responsibilities**:
- Keep the mapper as the single presentation boundary.
- Explicitly reject partial or non-finite coordinates.
- Preserve current string behavior for cards and headers.

#### Task 2.3: Update Presentation Mapper Tests 🔴
**File**: `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.test.ts`

**Key Logic/Responsibilities**:
- Add assertions that coordinates survive when present.
- Add assertions that `locationCoords` is `null` when either coordinate is missing.
- Guard against future regressions that silently strip mapping data again.

---

### Phase 3: Build a Reusable Alert Map Component 🔴

#### Task 3.1: Create `AlertLocationMap` Component 🔴
**File**: `resources/js/features/gta-alerts/components/AlertLocationMap.tsx`

```tsx
import { MapContainer, Marker, Popup, TileLayer } from 'react-leaflet';
import '../lib/leaflet';

interface AlertLocationMapProps {
    lat: number;
    lng: number;
    label: string;
}

export function AlertLocationMap({
    lat,
    lng,
    label,
}: AlertLocationMapProps) {
    return (
        <div className="h-72 overflow-hidden rounded-lg border border-white/10">
            <MapContainer
                center={[lat, lng]}
                zoom={15}
                scrollWheelZoom={false}
                className="h-full w-full"
            >
                <TileLayer
                    attribution='&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
                    url="https://tile.openstreetmap.org/{z}/{x}/{y}.png"
                />
                <Marker position={[lat, lng]}>
                    <Popup>{label}</Popup>
                </Marker>
            </MapContainer>
        </div>
    );
}
```

**Key Logic/Responsibilities**:
- Render a simple static-focus map with one marker and popup.
- Use the exact OSM tile URL recommended by the tile usage policy.
- Keep the component deliberately small so a later tile-host swap only changes one location.
- Import React Leaflet types and components from `react-leaflet` package entry-points that preserve TypeScript support.

#### Task 3.1A: Add an SSR-Safe Client-Only Wrapper 🔴
**File**: `resources/js/features/gta-alerts/components/AlertLocationMap.tsx`
**File**: `resources/js/features/gta-alerts/components/AlertLocationMap.client.tsx` or equivalent split

```tsx
export function AlertLocationMap(props: AlertLocationMapProps) {
    const [isClient, setIsClient] = useState(false);

    useEffect(() => {
        setIsClient(true);
    }, []);

    if (!isClient) {
        return <AlertLocationMapSkeleton />;
    }

    return <AlertLocationMapClient {...props} />;
}
```

**Key Logic/Responsibilities**:
- Prevent SSR crashes or hydration mismatches caused by browser-only Leaflet APIs.
- Keep the server-rendered output deterministic and lightweight.
- Make the client transition intentional instead of relying on fragile runtime branching inside Leaflet internals.

#### Task 3.2: Define Shared Empty-State UI for Non-Mappable Alerts 🔴
**File**: `resources/js/features/gta-alerts/components/AlertLocationMap.tsx`
**File**: `resources/css/app.css` or local class usage in component

```tsx
export function AlertLocationUnavailable({ label }: { label: string }) {
    return (
        <div className="flex h-72 items-center justify-center rounded-lg border border-dashed border-white/10 bg-white/5 p-6 text-center">
            <div>
                <p className="text-sm font-semibold text-white">
                    Map unavailable for this alert
                </p>
                <p className="mt-2 text-xs text-text-secondary">
                    A location label is available, but this alert does not include coordinates yet.
                </p>
                <p className="mt-3 text-xs text-text-secondary">{label}</p>
            </div>
        </div>
    );
}
```

**Key Logic/Responsibilities**:
- Replace ambiguous fake loading UI with explicit product truth.
- Preserve the user-visible location label even when coordinates are missing.

---

### Phase 4: Integrate the Map into the Alert Details Experience 🔴

#### Task 4.1: Replace Fire Placeholder with Shared Map Section Logic 🔴
**File**: `resources/js/features/gta-alerts/components/AlertDetailsView.tsx`

```tsx
function renderLocationSection(alert: PresentationAlert): React.ReactNode {
    return (
        <div className="rounded-2xl border border-white/5 bg-surface-dark p-6">
            <h4 className="mb-4 flex items-center gap-2 text-xs font-bold text-primary uppercase">
                <Icon name="map" className="text-sm" />
                Location Map
            </h4>

            {alert.locationCoords ? (
                <AlertLocationMap
                    lat={alert.locationCoords.lat}
                    lng={alert.locationCoords.lng}
                    label={alert.location}
                />
            ) : (
                <AlertLocationUnavailable label={alert.location} />
            )}
        </div>
    );
}
```

**Key Logic/Responsibilities**:
- Remove the decorative fire-only placeholder.
- Use a single rendering path for map/fallback behavior.
- Keep the title and alert metadata flow unchanged.
- Preserve SSR-safe rendering by placing all browser-only map work behind the new client-only map boundary.

#### Task 4.2: Decide Placement Strategy in `AlertDetailsView` 🔴
**File**: `resources/js/features/gta-alerts/components/AlertDetailsView.tsx`

**Recommended Choice**:
- Render the map section immediately after the "Official Briefing" card for all alert types.
- Keep fire `SceneIntelTimeline` as fire-specific specialized content.
- Keep police/transit/GO advisories beneath the map section.

**Why this is preferred**:
- Simplest shared layout.
- Avoids having map logic duplicated inside every branch builder.
- Makes future expansion to transit/go coordinates automatic once data becomes available.

#### Task 4.3: Preserve Existing IDs and Add New Stable Map IDs 🔴
**File**: `resources/js/features/gta-alerts/components/AlertDetailsView.tsx`
**File**: `resources/js/features/gta-alerts/components/AlertLocationMap.tsx`

**Key Logic/Responsibilities**:
- Add deterministic IDs such as:
  - `gta-alerts-alert-details-${alert.id}-location-section`
  - `gta-alerts-alert-details-${alert.id}-leaflet-map`
  - `gta-alerts-alert-details-${alert.id}-location-unavailable`
- Keep current ID patterns consistent with the rest of the frontend.

---

### Phase 5: Testing and Verification 🔴

#### Task 5.1: Add Focused Unit Tests for the Map Component 🔴
**File**: `resources/js/features/gta-alerts/components/AlertLocationMap.test.tsx`

**Key Logic/Responsibilities**:
- Verify the component renders the expected container, attribution text contract, and fallback semantics.
- Prefer mocking `react-leaflet` primitives in unit tests rather than exercising the full Leaflet runtime in JSDOM.
- Assert no render occurs with invalid or partial coordinates if a guard wrapper is introduced.

#### Task 5.2: Expand `AlertDetailsView` Tests 🔴
**File**: `resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx`

**Test Cases**:
- Police alert with valid coordinates renders the location map section.
- Fire alert without coordinates renders "Map unavailable for this alert" instead of the old placeholder.
- Transit/GO alert with valid coordinates renders the map section if coordinates are present in the fixture.
- No branch renders the old "Interactive Map Loading..." text after the refactor.
- SSR-safe wrapper test renders the non-map placeholder before client mount if that wrapper strategy is used.

#### Task 5.3: Keep CSP and Header Behavior Covered 🔴
**File**: `tests/Feature/Security/SecurityHeadersTest.php`

**Key Logic/Responsibilities**:
- No mandatory header changes are expected for raster tiles loaded as images.
- Preserve current `img-src 'self' data: https:` behavior and document that it remains sufficient for the chosen tile strategy.
- No new `connect-src` allowance should be added for v1 unless implementation details change away from raster tiles.

#### Task 5.3A: Keep Partial Reload Contract Stable 🔴
**File**: `tests/Feature/GtaAlertsTest.php` or a new focused feature test

**Key Logic/Responsibilities**:
- Assert that the `alerts` prop still includes `location` data under normal page loads.
- If a controller test already covers the feed contract, extend it rather than introducing a redundant test file.
- Avoid adding a new Inertia prop for maps; the plan should verify the existing `alerts` prop remains the contract boundary.

#### Task 5.4: Run the Minimum Relevant Verification Commands 🔴
**Commands**:
- `vendor/bin/sail pnpm test -- AlertDetailsView.test.tsx AlertLocationMap.test.tsx mapDomainAlertToPresentation.test.ts`
- `vendor/bin/sail pnpm types`
- `vendor/bin/sail pnpm lint`

**Key Logic/Responsibilities**:
- Keep verification scoped to the changed frontend surface.
- Ensure TypeScript catches any presentation-type drift caused by the new coordinates field.

---

### Phase 6: Post-v1 Follow-Up (Explicitly Out of Scope for Initial Delivery) 🔴

#### Task 6.1: Expand Backend Coordinate Coverage 🔴
**Potential Files**:
- `app/Services/Alerts/Providers/FireAlertSelectProvider.php`
- `app/Services/Alerts/Providers/TransitAlertSelectProvider.php`
- `app/Services/Alerts/Providers/GoTransitAlertSelectProvider.php`

**Deferred Work**:
- Geocode fire intersections.
- Geocode TTC stops/stations and GO stations/corridors where feasible.
- Cache derived coordinates instead of geocoding on the request path.

**Reason for Deferral**:
- This is a separate data-quality project.
- It is not required to deliver the first working map to users because police alerts already provide coordinates.

---

## File Summary

| File | Action | Status |
| :--- | :--- | :--- |
| `package.json` | Modify | 🔴 |
| `resources/js/app.tsx` | Modify | 🔴 |
| `resources/js/features/gta-alerts/lib/leaflet.ts` | Create | 🔴 |
| `resources/js/features/gta-alerts/components/AlertLocationMap.tsx` | Create | 🔴 |
| `resources/js/features/gta-alerts/components/AlertLocationMap.client.tsx` | Create or Avoid via Wrapper Pattern | 🔴 |
| `resources/js/features/gta-alerts/components/AlertLocationMap.test.tsx` | Create | 🔴 |
| `resources/js/features/gta-alerts/components/AlertDetailsView.tsx` | Modify | 🔴 |
| `resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx` | Modify | 🔴 |
| `resources/js/features/gta-alerts/domain/alerts/view/types.ts` | Modify | 🔴 |
| `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.ts` | Modify | 🔴 |
| `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.test.ts` | Modify | 🔴 |
| `tests/Feature/GtaAlertsTest.php` | Optional Modify | 🔴 |
| `tests/Feature/Security/SecurityHeadersTest.php` | Optional Modify | 🔴 |

---

## Execution Order
1. **Add dependencies and global CSS import** — establish the frontend runtime inside the existing Vite pipeline.
2. **Create shared Leaflet asset helper** — prevent broken marker icons before UI wiring begins.
3. **Add SSR-safe client-only map boundary** — protect the server-rendered app before importing browser-only map behavior.
4. **Preserve coordinates in presentation mapping** — unblock map rendering from current domain data.
5. **Create reusable `AlertLocationMap` and fallback UI** — isolate map behavior behind one component boundary.
6. **Refactor `AlertDetailsView` to use the shared location section** — replace the existing placeholder path.
7. **Add and update Vitest / feature coverage** — lock in map-present, map-missing, and contract behavior.
8. **Run targeted frontend verification** — confirm no type, lint, or component regressions.

---

## Edge Cases to Handle

1. **Missing Coordinates**: Render an explicit unavailable state instead of an empty or fake-loading map. 🔴
2. **Partial Coordinates**: Treat `(lat != null, lng == null)` and `(lat == null, lng != null)` as invalid and show fallback. 🔴
3. **Zero Coordinates**: Accept `0,0` as technically valid numbers at the presentation boundary, but consider adding a future sanity guard if upstream data quality makes this a realistic issue. 🔴
4. **Broken Default Marker Icons**: Configure Leaflet marker asset URLs explicitly for Vite. 🔴
5. **Map Container Height Collapse**: Give the map wrapper an explicit fixed height so the Leaflet canvas always initializes visibly. 🔴
6. **SSR / Hydration Mismatch**: Keep Leaflet behind a client-only render gate because this app ships an SSR entry. 🔴
7. **StrictMode Double Mount**: Keep component setup idempotent and avoid custom imperative map initialization outside React Leaflet. 🔴
8. **Future Tile Provider Swap**: Keep the tile URL and attribution isolated in one component or config seam. 🔴

---

## Rollback Plan
1. Remove `leaflet`, `react-leaflet`, and `@types/leaflet` from `package.json`.
2. Delete the new shared Leaflet helper and `AlertLocationMap` component.
3. Revert the presentation-layer coordinate additions.
4. Restore the previous `AlertDetailsView` placeholder behavior if the release must be backed out.
5. Re-run targeted frontend tests to confirm the original UI state is restored.

---

## Success Criteria
- [ ] Alerts with valid `lat` and `lng` render a real map with a marker in the details view.
- [ ] Alerts without coordinates render an explicit unavailable-state card rather than a fake loading placeholder.
- [ ] Fire, police, TTC, and GO detail branches all use the same location section behavior.
- [ ] The frontend preserves coordinates from the API resource through the presentation boundary.
- [ ] The map implementation is safe under the app's current Inertia SSR configuration.
- [ ] Leaflet CSS and marker icons load correctly in the Vite/Inertia app.
- [ ] Targeted Vitest, typecheck, and lint verification pass.
- [ ] The implementation requires no backend schema changes and no immediate CSP expansion.

---

## Recommended Scope Cut for v1

To keep this delivery simple and low-risk, the initial implementation should deliberately stop at:

1. Rendering maps only when coordinates already exist.
2. Not introducing geocoding, reverse geocoding, or provider enrichment.
3. Not adding route overlays, multi-marker clustering, or custom tiles.
4. Not proxying tiles through Laravel.
5. Not adding a paid map provider.

This scope gives the project a working map feature quickly and creates a clean path to improve coordinate coverage later.

---

## Implementation Walkthrough
This document is a pre-implementation plan. Execution results will be added after the feature is built and verified.

### Initial Deliverable
| Item | Target |
| :--- | :--- |
| Map library | `leaflet` + `react-leaflet` |
| Tile source | OpenStreetMap raster tiles |
| First supported alert source | Police alerts with existing coordinates |
| Fallback behavior | Explicit unavailable state |

### Deferred Deliverable
| Item | Reason Deferred |
| :--- | :--- |
| Fire geocoding | Requires separate backend/data-quality work |
| TTC/GO geocoding | Requires source-specific location resolution strategy |
| Hosted tile provider | Not required for first functional release |
| Deep links / directions | Nice-to-have, not essential to replace the placeholder |
