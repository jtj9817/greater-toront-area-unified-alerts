# AlertService (Frontend)

- File: `resources/js/features/gta-alerts/services/AlertService.ts`

`AlertService` is a thin facade over the typed domain boundary. It maps backend transport objects into `DomainAlert` values and performs search/filtering over domain alerts via derived presentation fields.

## Current Responsibilities

- Map transport `UnifiedAlertResource` values into `DomainAlert` using `fromResource(...)`.
- Discard invalid resources via hard enforcement (`catch/log/discard` at boundary).
- Keep GO Transit alerts visible under the transit filter (`categoryAliases.transit = ['transit', 'go_transit']`).
- Search and filter `DomainAlert[]` by category, time window, date scope, and query.

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
