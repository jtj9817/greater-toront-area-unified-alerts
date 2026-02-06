# Frontend Typed Alert Domain Plan

## Purpose

Document the **current** frontend alert-domain state and define the remaining work to reach a fully typed domain layer.

## Current State (As Implemented)

### 1. Backend Boundary Is Unified and Typed in Frontend Inputs

- `resources/js/features/gta-alerts/types.ts` defines `UnifiedAlertResource` as the API input shape.
- Frontend currently handles these sources directly: `'fire' | 'police' | 'transit' | 'go_transit'`.
- `App.tsx` receives `alerts.data: UnifiedAlertResource[]` and maps each item through `AlertService.mapUnifiedAlertToAlertItem(...)`.

### 2. Frontend Uses a Typed Presentation Model (`AlertItem`)

- `AlertItem` is the UI-facing type used across feed cards, table rows, and details views.
- `AlertItem.type` is a union: `'fire' | 'police' | 'transit' | 'go_transit' | 'hazard' | 'medical'`.
- `AlertItem.metadata` includes shared fields plus optional transit/GO-specific fields (`routeType`, `effect`, `direction`, `estimatedDelay`, `sourceFeed`, `shuttleInfo`).

### 3. Domain Mapping Logic Exists but Is Centralized in One Service

- `resources/js/features/gta-alerts/services/AlertService.ts` currently performs:
  - source/type mapping (`getAlertItemType`, `normalizeType`)
  - severity mapping (`getSeverity`, `getTransitSeverity`, `getGoTransitSeverity`)
  - source-specific description + metadata mapping (`getDescriptionAndMetadata`)
  - icon/color mapping (`getIconForType`, `getAccentColorForType`, `getIconColorForType`)
  - search/filter logic (`search`) including category aliasing (`transit` includes `go_transit`)

### 4. Test Coverage Reflects This Architecture

- `resources/js/features/gta-alerts/services/AlertService.test.ts` covers:
  - fire/police/transit/GO mapping behavior
  - transit severity and icon mapping from metadata
  - transit category filter including GO alerts
  - query/category filtering behavior
- Component tests use mapped `AlertItem` values (e.g., `AlertCard.test.tsx`, `FeedView.test.tsx`).

## What Changed vs Original Plan

The previous version of this document stated this work should be deferred. That is no longer accurate.

What is now true:

- Typed source-aware mapping is already implemented.
- GO Transit is integrated in frontend types and mapping.
- Source-specific behavior for transit metadata/severity/icon rules is already in production code.

What is still not done:

- There is no dedicated `domain/alerts` module.
- There is no explicit discriminated union domain model (`DomainAlert = FireAlert | PoliceAlert | TransitAlert | GoTransitAlert`).
- Source parsing/mapping remains concentrated in one large service class.

## Remaining Gaps

1. **No explicit domain-layer union model**
   - UI operates on `AlertItem` only, not a strongly modeled domain union.

2. **Mapping responsibilities are overloaded in `AlertService`**
   - Data translation, presentation mapping, and filtering are mixed together.

3. **Weak metadata typing**
   - Input `meta` is `Record<string, unknown>` and is narrowed ad hoc in many places.

4. **Detail rendering does not explicitly model GO Transit**
   - `AlertDetailsView` branches on `alert.type === 'transit'`; `go_transit` currently falls back to transit rendering.

## Updated Recommendation

Proceed with an incremental refactor to formalize the typed domain layer, while preserving current behavior.

## Incremental Refactor Plan

1. Create `resources/js/features/gta-alerts/domain/alerts/` with:
   - domain types (`FireAlert`, `PoliceAlert`, `TransitAlert`, `GoTransitAlert`, `DomainAlert`)
   - metadata parsers/guards for each source

2. Add a boundary mapper:
   - `toDomainAlert(resource: UnifiedAlertResource): DomainAlert`

3. Add a presentation mapper:
   - `toAlertItem(domainAlert: DomainAlert): AlertItem`

4. Move source-specific logic out of `AlertService`:
   - severity/icon/description rules into source modules
   - keep `AlertService.search(...)` focused on filtering/sorting only

5. Update details rendering to explicitly handle `go_transit`:
   - either dedicated GO detail renderer or clearly intentional shared transit renderer path

6. Expand tests around:
   - parser validation/narrowing of source metadata
   - exhaustive handling on `DomainAlert['source']`
   - parity tests to prevent UI regressions during refactor

## Scope Guidance

- **Short term:** keep existing `AlertItem` API for UI components to minimize churn.
- **Medium term:** introduce domain union + mappers behind the existing component interfaces.
- **Long term:** make domain union the primary internal model and keep `AlertItem` as presentation-only.
