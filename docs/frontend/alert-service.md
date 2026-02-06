# AlertService (Frontend)

- File: `resources/js/features/gta-alerts/services/AlertService.ts`

`AlertService` maps backend transport objects (`UnifiedAlertResource`) into display-ready `AlertItem` records and performs client-side filtering/search.

## Current Responsibilities

- Map source-specific metadata into unified UI fields (`description`, `metadata`, `severity`, icons/colors).
- Keep GO Transit alerts visible under the transit filter (`categoryAliases.transit = ['transit', 'go_transit']`).
- Format relative times via `formatTimeAgo()`.
- Search and filter alerts by category, time window, date scope, and query.

## Source Handling

- `fire`: alarm-level-driven severity + fire metadata.
- `police`: title keyword severity + division/code metadata.
- `transit`: TTC-specific route/effect/source-feed metadata.
- `go_transit`: Metrolinx notification/SAAG metadata and GO severity mapping.

## GO Transit Severity Rules

- `sub_category === BCANCEL` -> `high`
- `sub_category in [TDELAY, BDETOUR]` -> `medium`
- `alert_type === saag` -> `medium`
- Otherwise -> `low`

## Visual Mapping

- GO Transit uses dedicated type/color/icon mapping (`type = 'go_transit'`, train icon, green accent family).
- High severity overrides category color with the shared high-severity palette.
