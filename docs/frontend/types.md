# Frontend Types

- Source directory: `resources/js/features/gta-alerts/domain/alerts/`

## UnifiedAlertResource (Backend Transport)

Transport shape is validated at the frontend boundary with Zod:
- Schema: `resources/js/features/gta-alerts/domain/alerts/resource.ts`
- Type: `UnifiedAlertResource`

`UnifiedAlertResource` is not rendered directly in components.

## DomainAlert (Typed Domain Union)

`DomainAlert` is the discriminated union used across GTA Alerts feature logic:
- `kind: 'fire'`
- `kind: 'police'`
- `kind: 'transit'` — TTC alerts
- `kind: 'go_transit'`
- `kind: 'miway'`
- `kind: 'yrt'`
- `kind: 'drt'`

Defined in: `resources/js/features/gta-alerts/domain/alerts/types.ts`

Source-specific schema modules validate and map each source into its domain type.

## MiWay Domain Types

- Schema: `resources/js/features/gta-alerts/domain/alerts/miway/schema.ts`
- Mapper: `resources/js/features/gta-alerts/domain/alerts/miway/mapper.ts`

### MiwayAlert

Domain type for MiWay (Mississauga Transit) GTFS-RT service alerts. `kind: 'miway'`.

Fields mirror the base domain shape plus a `meta: MiwayMeta` block. Location fields (`lat`, `lng`) are always `null` in v1 — MiWay GTFS-RT feeds do not include stop coordinates.

### MiwayMeta

GTFS-RT fields surfaced from `MiwayAlertSelectProvider`:

| Field | Type | Notes |
|---|---|---|
| `header_text` | `string \| null` | Short human-readable summary |
| `description_text` | `string \| null` | Full alert description |
| `cause` | `string \| null` | GTFS-RT cause enum (e.g. `CONSTRUCTION`) |
| `effect` | `string \| null` | GTFS-RT effect enum (e.g. `DETOUR`, `NO_SERVICE`) |
| `url` | `string \| null` | Optional link to service advisory page |
| `detour_pdf_url` | `string \| null` | Optional link to detour PDF map |
| `ends_at` | `string \| null` | ISO 8601 end time if known |
| `feed_updated_at` | `string \| null` | Feed-level timestamp from GTFS-RT header |

### MiWay Severity Rules

Derived in `deriveMiwaySeverity(meta)` in `transit/presentation.ts` (case-insensitive `effect` match):

- `NO_SERVICE` → `high`
- `REDUCED_SERVICE`, `SIGNIFICANT_DELAYS`, `DETOUR` → `medium`
- All other values or `null` → `low`

### MiWay Presentation Mapping

In `mapDomainAlertToPresentation`, MiWay alerts map to `type: 'transit'` (same visual treatment as TTC). Description is built from `effect` label + `cause` + `description_text` with a fallback chain: `header_text` → `title` → `'MiWay service alert.'`.

## YRT Domain Types

- Schema: `resources/js/features/gta-alerts/domain/alerts/transit/yrt/schema.ts`
- Mapper: `resources/js/features/gta-alerts/domain/alerts/transit/yrt/mapper.ts`

### YrtAlert

Domain type for YRT (York Region Transit) service advisories. `kind: 'yrt'`.

Fields mirror the base domain shape plus a `meta: YrtMeta` block. Location fields (`lat`, `lng`) are always `null` — YRT advisories carry no geographic coordinates.

### YrtMeta

Fields surfaced from `YrtAlertSelectProvider`:

| Field | Type | Notes |
|---|---|---|
| `details_url` | `string \| null` | Link to YRT advisory detail page |
| `description_excerpt` | `string \| null` | Short summary from feed |
| `body_text` | `string \| null` | Full advisory body from detail HTML |
| `route_text` | `string \| null` | Route numbers/names affected |
| `posted_at` | `string \| null` | ISO 8601 posted timestamp |
| `feed_updated_at` | `string \| null` | Feed-level sync timestamp |

### YRT Severity Rules

YRT alerts use shared transit presentation helpers. Severity defaults to `low` unless presentation logic derives otherwise.

### YRT Presentation Mapping

YRT alerts map to `type: 'transit'` (same visual treatment as TTC/GO Transit/MiWay). Description is built from `route_text` + `description_excerpt` + `body_text`.

## DRT Domain Types

- Schema: `resources/js/features/gta-alerts/domain/alerts/transit/drt/schema.ts`
- Mapper: `resources/js/features/gta-alerts/domain/alerts/transit/drt/mapper.ts`

### DrtAlert

Domain type for DRT (Durham Region Transit) service advisories. `kind: 'drt'`.

Fields mirror the base domain shape plus a `meta: DrtMeta` block. Location fields (`lat`, `lng`) are always `null` — DRT advisories carry no geographic coordinates.

### DrtMeta

Fields surfaced from `DrtAlertSelectProvider`:

| Field | Type | Notes |
|---|---|---|
| `details_url` | `string \| null` | Link to DRT advisory detail page |
| `when_text` | `string \| null` | Human-readable schedule/when info |
| `route_text` | `string \| null` | Route numbers/names affected |
| `body_text` | `string \| null` | Full advisory body from detail HTML |
| `feed_updated_at` | `string \| null` | Feed-level sync timestamp |
| `posted_at` | `string \| null` | ISO 8601 posted timestamp |

### DRT Severity Rules

Derived in `deriveDrtSeverity(meta)` in `transit/presentation.ts`:

- Keywords `CANCEL`, `SUSPEND`, `NO SERVICE`, `CLOSED` → `high`
- Keywords `DETOUR`, `DELAY`, `REDUCED SERVICE` → `medium`
- All others → `low`

### DRT Presentation Mapping

DRT alerts map to `type: 'transit'` (same visual treatment as TTC/GO Transit/MiWay/YRT). Description is built from `when_text` + `route_text` + `body_text`.

## GO Transit Severity Rules

Derived in `deriveGoTransitSeverity(meta)` in `transit/presentation.ts`:

- `sub_category === 'BCANCEL'` → `high`
- `sub_category in ['TDELAY', 'BDETOUR']` → `medium`
- `alert_type === 'saag'` → `medium`
- Otherwise → `low`

GO Transit alerts use `type: 'go_transit'` (dedicated train icon, green accent family).

## AlertPresentation (UI View Model)

`AlertPresentation` is the derived presentation model used by card/table/details renderers:
- Type: `resources/js/features/gta-alerts/domain/alerts/view/types.ts`
- Mapper: `mapDomainAlertToPresentation(...)` in `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.ts`

Presentation-only categories (`hazard`, `medical`) are derived here and are not `DomainAlert.kind` values.

### AlertPresentationCoordinates

Added to `AlertPresentation` as `locationCoords: AlertPresentationCoordinates | null`.

- Type: `resources/js/features/gta-alerts/domain/alerts/view/types.ts`
- `AlertPresentationCoordinates { lat: number; lng: number }`
- Computed at the presentation boundary in `mapDomainAlertToPresentation.ts`
- Valid only when both coordinates are finite numbers within the GTA bounding box (lat 40–50, lng −90 to −70)
- `null` when coordinates are absent, partial, non-finite, out-of-range, or `0,0`
- UI components consume `locationCoords` exclusively; they never inspect raw `alert.location.lat/lng`

## Scene Intel Types

Scene Intel provides real-time operational updates for fire incidents.

### SceneIntelItem (Transport)

Transport shape from `/api/incidents/{eventNum}/intel` endpoint:
- Schema: `resources/js/features/gta-alerts/domain/alerts/fire/scene-intel.ts`
- Type: `SceneIntelItem`

Fields:
- `id: number` - Unique update ID
- `update_type: 'milestone' | 'resource_status' | 'alarm_change' | 'phase_change' | 'manual_note'`
- `content: string` - Human-readable update text
- `source: 'synthetic' | 'manual'`
- `created_at: string` - ISO 8601 timestamp
- `metadata: Record<string, unknown> | null` - Optional structured data

### Embedded Intel Summary

Fire alerts include optional embedded intel in `meta.intel_summary`:
- Array of latest 3 updates (if available)
- Reduces blank state before timeline fetch
- Accompanied by `meta.intel_last_updated` timestamp

### Hook: useSceneIntel

Custom React hook for fetching and polling scene intel:
- File: `resources/js/features/gta-alerts/hooks/useSceneIntel.ts`
- Polling interval: 30 seconds for active incidents
- Validates responses with `SceneIntelResponseSchema`
- Returns: `{ data, isLoading, error, refresh }`

### Component: SceneIntelTimeline

Timeline component for displaying incident updates:
- File: `resources/js/features/gta-alerts/components/SceneIntelTimeline.tsx`
- Distinct icons/styles for each `update_type`
- "Live" indicator for polling freshness
- Integrated into `AlertDetailsView` for fire incidents

## Boundary Contract

Canonical boundary entrypoint:
- `fromResource(resource): DomainAlert | null`
- File: `resources/js/features/gta-alerts/domain/alerts/fromResource.ts`

Behavior:
- Valid resources map to typed `DomainAlert`.
- Invalid resources are caught, logged (`[DomainAlert] ...`), and discarded (`null`).
- UI rendering must never crash due to malformed backend items.
