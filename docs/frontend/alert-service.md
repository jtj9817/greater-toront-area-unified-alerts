# AlertService (Frontend)

- File: `resources/js/features/gta-alerts/services/AlertService.ts`

`AlertService` is a thin facade over the typed domain boundary. It maps backend transport objects into `DomainAlert` values for rendering and infinite-scroll accumulation.

## Current Responsibilities

- Map transport `UnifiedAlertResource` values into `DomainAlert` using `fromResource(...)`.
- Discard invalid resources via hard enforcement (`catch/log/discard` at boundary).
- Keep GO Transit alerts visible under the transit filter (`categoryAliases.transit = ['transit', 'go_transit']`).
- Perform legacy in-memory filtering helpers for non-live/local-only scenarios.

## Live Feed Filtering Contract

- Live feed filtering is server-authoritative and URL-driven (`status`, `source`, `q`, `since`, `cursor`).
- `AlertService.searchDomainAlerts()` is not used for the live feed request cycle.
- Infinite scroll fetches are performed by `useInfiniteScroll` against `/api/feed` and append mapped `DomainAlert` batches.
- Filter changes trigger Inertia navigation, replacing initial feed props and resetting local infinite-scroll state.

## Source Handling

- Source-specific validation and mapping is handled in domain mapper modules under `resources/js/features/gta-alerts/domain/alerts/*`.
- Presentation derivation (severity/icon/description/metadata) is handled by `mapDomainAlertToPresentation(...)`.

## GO Transit Severity Rules

- `sub_category === BCANCEL` -> `high`
- `sub_category in [TDELAY, BDETOUR]` -> `medium`
- `alert_type === saag` -> `medium`
- Otherwise -> `low`

## Visual Mapping

- GO Transit uses dedicated type/color/icon mapping (`type = 'go_transit'`, train icon, green accent family).
- High severity overrides category color with the shared high-severity palette.
