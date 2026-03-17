# FEED-024: SavedView Review Findings (Correctness + Lint Gate)

## Summary
Fixes user-visible correctness regressions in `SavedView` (saved list not updating after un-save; fetch failures shown as an empty state) and restores `pnpm run lint` by resolving ESLint violations introduced during the saved-alerts refactor.

## Scope / Contract Check
- Laravel → Inertia → React data shapes: **no changes**
- All fixes are contained to React view logic and test/mocks used by the frontend suite.

## Findings (Priority Order)

### P1 — SavedView does not reflect `savedIds` after un-save (auth mode)
**Files**
- `resources/js/features/gta-alerts/components/SavedView.tsx`

**Problem**
- Authenticated mode rendered from `authSavedAlerts` / `missingIds` and hard-coded `isSaved={true}` on `AlertCard`.
- After a user removes an alert, the global saved state updates but the Saved view continues to render the removed item (and “Alert Unavailable” rows remain removable/re-savable because the view never re-derives visibility from `savedIds`).

**Fix**
- Derive the rendered authenticated list from current saved state:
  - Filter hydrated alerts by `isSaved(alert.id)`.
  - Filter `missingIds` by `isSaved(id)` so removed IDs stop rendering immediately.
  - Pass `isSaved(item.id)` into `AlertCard` instead of hard-coding `true`.

---

### P1 — `pnpm run lint` failures (no-unused-vars / no-explicit-any)
**Files**
- `resources/js/features/gta-alerts/components/SavedView.tsx`
- `resources/js/features/gta-alerts/components/FeedView.tsx`
- `resources/js/features/gta-alerts/App.test.tsx`
- `resources/js/features/gta-alerts/components/SavedView.test.tsx`

**Problems**
- Unused imports/vars in `SavedView.tsx` (and error state set but never rendered).
- Unused `useMemo` import in `FeedView.tsx`.
- Unused `ttcTransitResource` helper in `App.test.tsx`.
- `as any` casts and untyped mocks in `SavedView.test.tsx` tripping `@typescript-eslint/no-explicit-any`.

**Fixes**
- Remove unused imports/helpers and ensure all state is either rendered or removed.
- Replace `as any` casts with correctly typed `DomainAlert` mocks and use `vi.mocked(fetchSavedAlerts)` for typed mocking.

---

### P2 — Authenticated SavedView API failures render as empty state
**Files**
- `resources/js/features/gta-alerts/components/SavedView.tsx`

**Problem**
- Fetch failures set an `error` state but render ignored it, causing “No saved alerts” to appear on backend/API failures.

**Fix**
- Render an explicit error state when `error` is present (and ensure empty state only renders when `error === null`).

## Verification
**Targeted frontend tests**
- `pnpm exec vitest run resources/js/features/gta-alerts/components/SavedView.test.tsx`
- `pnpm exec vitest run resources/js/features/gta-alerts/components/FeedView.test.tsx`
- `pnpm exec vitest run resources/js/features/gta-alerts/App.test.tsx`
- `pnpm exec vitest run tests/e2e/design-revamp-phase-4.spec.ts`

**Full suite**
- `composer test`

**Quality gates**
- `./vendor/bin/pint`
- `composer lint`
- `pnpm run lint`
- `pnpm run format`
- `pnpm run types`

## Acceptance Criteria
- [x] Saved alerts list updates immediately after un-save (auth mode)
- [x] SavedView shows an error state on `/api/saved-alerts` failures (auth mode)
- [x] `pnpm run lint` passes with no new warnings/errors
- [x] Full suite passes (`composer test`)

These fixes are part of Phase 3: Frontend Saved Alert State (GTA-101).

