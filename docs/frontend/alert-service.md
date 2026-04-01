# AlertService (Frontend)

- File: `resources/js/features/gta-alerts/services/AlertService.ts`

`AlertService` is a thin static facade over the typed domain boundary. It maps backend transport objects into `DomainAlert` values for rendering and infinite-scroll accumulation.

## Current Responsibilities

- Map a single transport `UnifiedAlertResource` to a `DomainAlert` via `AlertService.mapUnifiedAlertToDomainAlert(alert)`.
- Map an array of transport resources to `DomainAlert[]` via `AlertService.mapUnifiedAlertsToDomainAlerts(alerts)`, silently discarding any that fail validation.
- Delegates all validation and mapping to `fromResource(...)` from `domain/alerts/fromResource.ts`.

## Live Feed Filtering Contract

- Live feed filtering is server-authoritative and URL-driven (`status`, `source`, `q`, `since`, `cursor`).
- `AlertService` performs no query filtering of the live feed.
- Infinite scroll fetches are performed by `useInfiniteScroll` against `/api/feed` and append mapped `DomainAlert` batches.
- Filter changes trigger Inertia navigation, replacing initial feed props and resetting local infinite-scroll state.

## Source Handling

- Source-specific validation and mapping is handled in domain mapper modules under `resources/js/features/gta-alerts/domain/alerts/*`.
- Sources dispatched by `fromResource`: `fire`, `police`, `transit` (TTC), `go_transit`, `miway`.
- Presentation derivation (severity/icon/description/metadata) is handled by `mapDomainAlertToPresentation(...)` in `domain/alerts/view/mapDomainAlertToPresentation.ts`.
- See `docs/frontend/types.md` for severity rules per source.
