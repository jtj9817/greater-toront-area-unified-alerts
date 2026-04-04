# FEED-059: DRT Phase 7 Frontend Review Findings (`131e658`)

## Meta
- **Issue Type:** Bug
- **Priority:** `P1`
- **Status:** Open
- **Labels:** `alerts`, `drt`, `frontend`, `review`, `runtime-error`, `ui-regression`
- **Reviewed Commit:** `131e658`

## Summary
DRT alerts are now accepted in frontend domain mapping, but two downstream UI paths are incomplete:
- Details view does not handle `drt`, causing a runtime error when opening a DRT alert.
- Card/table source-label switches do not include `drt`, causing blank source labels.

## Findings (Priority Order)

### P1 — Missing `drt` branch in `AlertDetailsView` causes runtime failure
**Finding:**
- `fromResource` now maps `source: "drt"` into domain alerts:
  - `resources/js/features/gta-alerts/domain/alerts/fromResource.ts`
- `AlertDetailsView` `switch (alert.kind)` has no `case 'drt'` and no safe default:
  - `resources/js/features/gta-alerts/components/AlertDetailsView.tsx`

**Impact:**
- Opening a DRT alert details panel can dereference `sections` when undefined and throw at runtime.

**Required fix direction:**
- Add a `drt` details-section branch (or a defensive default branch) so `sections` is always defined.
- Add a focused component test for the DRT details rendering path.

### P2 — Missing DRT source labels in card/table metadata
**Finding:**
- `DomainAlert` now includes `DrtAlert`:
  - `resources/js/features/gta-alerts/domain/alerts/types.ts`
- `getSourceLabel` in both UI surfaces has no `drt` case:
  - `resources/js/features/gta-alerts/components/AlertCard.tsx`
  - `resources/js/features/gta-alerts/components/AlertTableView.tsx`

**Impact:**
- DRT rows render with missing/blank source label in list and table views.

**Required fix direction:**
- Add explicit `drt` label handling (e.g., `DRT`) in both label switch functions.
- Add regression assertions for DRT source labels in both component test suites.

## Acceptance Criteria
- [ ] Opening details for a DRT alert does not throw and renders a valid details layout.
- [ ] `AlertDetailsView` has a `drt` handling path or safe fallback guaranteeing `sections` is defined.
- [ ] DRT source label renders in `AlertCard`.
- [ ] DRT source label renders in `AlertTableView`.
- [ ] Targeted frontend tests cover DRT details and source-label branches and pass.
