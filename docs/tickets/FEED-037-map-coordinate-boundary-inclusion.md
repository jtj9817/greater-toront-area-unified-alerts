# FEED-037: Accept Edge Coordinates in Alert Presentation Mapping

## Issue Type
Bug Fix

## Priority
P3

## Status
Closed

## Summary
The coordinate normalization gate in the alert presentation mapper rejected coordinates exactly on the configured GTA bounds (`40`, `50`, `-90`, `-70`). This caused legitimate edge-case points to be dropped (`locationCoords = null`) and map pins to be omitted.

## Source Finding
1. [P3] Accept coordinates that fall exactly on the boundary — `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.ts:36-40`

## Root Cause
The range check used exclusive rejection at bounds (`<=` / `>=`) instead of rejecting only out-of-range values.

## Data Contract Check (Laravel -> Inertia -> React)
- No data shape changed across Laravel serialization, Inertia payloads, or TypeScript interfaces.
- Fix is confined to frontend coordinate range logic and tests.

## Changes Applied
1. Updated coordinate guard in `mapDomainAlertToPresentation.ts` to allow boundary values by changing comparisons from `<=`/`>=` to `<`/`>`.
2. Added regression coverage in `mapDomainAlertToPresentation.test.ts` asserting boundary min/max coordinates are preserved.
3. Ran `vendor/bin/sail bin pint` per required quality gate, which applied one formatting-only fix in a manual verification file (no runtime logic changes).

## Files Changed
- `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.ts`
- `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.test.ts`
- `tests/manual/verify_alert_location_map_phase_1_contract_guardrails_runtime_foundation.php` (formatting-only)
- `docs/tickets/FEED-037-map-coordinate-boundary-inclusion.md`

## Verification
### Targeted Tests
- `vendor/bin/sail pnpm exec vitest run resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.test.ts` -> PASS (`1 file`, `19 tests`)

### Full Suite
- `vendor/bin/sail composer test` -> PASS (`756 passed`, `7 skipped`)

### Required Formatting / Lint / Type Checks
- `vendor/bin/sail bin pint` -> PASS (`1 style issue fixed`)
- `vendor/bin/sail composer lint` -> PASS
- `vendor/bin/sail pnpm run lint` -> PASS
- `vendor/bin/sail pnpm run format` -> PASS
- `vendor/bin/sail pnpm run types` -> PASS

## Acceptance Criteria Check
- All findings are resolved and verified by tests: PASS
- Full test suite passes (`composer test`): PASS
- No new lint, format, or TypeScript errors: PASS
- No unintended behavior changes outside the scope of cited findings: PASS

## Closure
Resolved, verified, and closed.

These fixes are part of Phase 1: Contract Guardrails Runtime Foundation.
Validated against already-completed phase tasks via targeted mapper regression coverage and a full passing suite.
