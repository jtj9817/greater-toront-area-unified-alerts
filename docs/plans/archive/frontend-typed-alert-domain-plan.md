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

Proceed with a Functional Programming (FP) refactor using **Zod** for runtime validation and **Discriminated Unions** for domain modeling.

### Key Architectural Decisions

1.  **Zod for Runtime Validation:**
    -   Because the `meta` column is unstructured JSON derived from 3rd-party XML feeds, TypeScript interfaces alone are insufficient.
    -   We will use Zod schemas to parse and validate `meta` data at the application boundary (Service layer). This ensures "Fail Fast" behavior if external feeds change, rather than silent UI failures.

2.  **Discriminated Unions (FP) > Classes (OOP):**
    -   We will model the domain using Discriminated Unions (e.g., `kind: 'transit'`) rather than Class hierarchies.
    -   Logic will be co-located as module-scoped pure functions (e.g., `isCritical(alert)`), promoting better tree-shaking and testability.

3.  **Composition > Inheritance in UI:**
    -   We will refactor `AlertDetailsView` to use React Composition instead of the current Class-based inheritance (`extends AlertDetailTemplate`), which is rigid and less idiomatic in modern React.

## Incremental Refactor Plan

1.  **Define Zod Schemas & Domain Types**
    -   Create `resources/js/features/gta-alerts/domain/alerts/schemas.ts` (or individual files per source).
    -   Define strict Zod schemas for `FireMeta`, `PoliceMeta`, `TransitMeta`.
    -   Export strict TypeScript types inferred from Zod: `type TransitAlert = { kind: 'transit', metadata: z.infer<typeof TransitMetaSchema>, ... }`.

2.  **Create "Smart" Mappers**
    -   Implement `fromResource(resource: UnifiedAlertResource): DomainAlert`.
    -   This function *must* use `Schema.parse()` to guarantee data integrity.

3.  **Refactor `AlertService` to Facade**
    -   Move business logic (severity calculation, icon selection) into domain modules as pure functions.
    -   `AlertService` becomes a thin facade that fetches data and orchestrates the mapping.

4.  **Refactor `AlertItem` to Discriminated Union**
    -   Deprecated the "kitchen sink" `AlertItem` interface.
    -   Update the UI components to consume the Discriminated Union (`DomainAlert`) directly, or a specialized `ViewAlert` union if presentation needs diverge significantly from domain data.

5.  **Modernize `AlertDetailsView`**
    -   Refactor to a Functional Component using a `Layout` wrapper.
    -   Use pattern matching (switch on `alert.kind`) to render specific content.

## Scope Guidance

-   **Immediate:** Install Zod. Create Schemas.
-   **Short term:** Refactor `AlertService` to use Zod and return typed Domain objects.
-   **Medium term:** Update `App.tsx` and `FeedView` to handle the new Discriminated Unions.
-   **Long term:** Delete the legacy `AlertItem` interface and Class-based View components.
