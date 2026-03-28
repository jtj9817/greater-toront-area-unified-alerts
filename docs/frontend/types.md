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
- `kind: 'transit'`
- `kind: 'go_transit'`

Source-specific schema modules validate and map each source into its domain type.

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
