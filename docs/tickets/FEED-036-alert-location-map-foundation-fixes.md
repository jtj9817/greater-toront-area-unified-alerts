# FEED-036: Alert Location Map Foundation Fixes

## Issue Type
Bug Fix

## Priority
P2

## Status
Closed

## Summary
The new Leaflet foundation had two runtime issues: lazy loading did not provide a real runtime default component to `React.lazy()`, and the rendered `MapContainer` had no guaranteed visible height. This made map mounting brittle and could render an invisible map.

## Source Findings
1. [P2] Return a real default export from the lazy-loaded map module — `resources/js/features/gta-alerts/components/AlertLocationMap.tsx:3-10`
2. [P2] Give the Leaflet container an explicit height — `resources/js/features/gta-alerts/components/AlertLocationMap.client.tsx:23-31`

## Root Cause
1. `React.lazy()` expects a promise resolving to `{ default: Component }`, but the implementation only type-cast a module with named exports.
2. `MapContainer` used `height: 100%` without an explicit parent height/aspect constraint, so the map region could collapse.

## Data Contract Check (Laravel -> Inertia -> React)
- No Laravel, Inertia payload, or TypeScript resource shape changed.
- Fixes are isolated to frontend component runtime behavior and presentation sizing.

## Changes Applied
1. Updated lazy loader in `AlertLocationMap.tsx` to map the named `AlertLocationMapClient` export into a real runtime `{ default: ... }` object.
2. Added `aspect-video` to the map wrapper in `AlertLocationMap.client.tsx` so the Leaflet container has explicit layout height.
3. Added targeted frontend tests covering lazy-load runtime resolution and map wrapper sizing.

## Files Changed
- `resources/js/features/gta-alerts/components/AlertLocationMap.tsx`
- `resources/js/features/gta-alerts/components/AlertLocationMap.client.tsx`
- `resources/js/features/gta-alerts/components/AlertLocationMap.test.tsx`
- `resources/js/features/gta-alerts/components/AlertLocationMap.client.test.tsx`

## Verification
### Targeted Tests
- `pnpm exec vitest run resources/js/features/gta-alerts/components/AlertLocationMap.test.tsx --pool=forks`
  - Result: PASS (`1 file`, `1 test`)
- `pnpm exec vitest run resources/js/features/gta-alerts/components/AlertLocationMap.client.test.tsx --pool=forks`
  - Result: PASS (`1 file`, `2 tests`)

### Full Suite
- `composer test`
  - Result: PASS (`756 passed`, `7 skipped`)

### Required Formatting / Lint / Type Checks
- `./vendor/bin/pint` -> PASS
- `composer lint` -> PASS
- `pnpm run lint` -> PASS
- `pnpm run format` -> PASS
- `pnpm run types` -> PASS

## Acceptance Criteria Check
- All findings are resolved and verified by tests: ✅
- Full test suite passes (`composer test`): ✅
- No new lint, format, or TypeScript errors: ✅
- No unintended behavior changes outside the scope of cited findings: ✅

## Closure
Resolved, verified, and closed.
