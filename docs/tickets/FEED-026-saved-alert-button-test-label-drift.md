# FEED-026: Saved Alert Button Tests Failing — aria-label and Class Assertions Stale After Visual Indicator Improvements

**Status:** Resolved
**Component:** Frontend — Test Suite
**Affects:** `pnpm run test` / `pnpm run quality:check`
**Introduced by:** `5881710` (`feat(components): improve saved alert visual indicators and add tooltips`)

---

## Summary

6 tests across 5 files fail because their `aria-label` and CSS class assertions were not updated when the save button was redesigned in `5881710`. The production components are correct; the tests are stale.

---

## Failing Tests

| # | File | Test | Error |
|---|------|------|-------|
| 1 | `AlertCard.test.tsx:68` | `shows saved state correctly` | `getByLabelText('Remove alert')` — no match |
| 2 | `AlertCard.test.tsx:74` | `shows loading state when isPending is true` | `getByLabelText('Save alert')` — no match (pending state uses a different label) |
| 3 | `App.test.tsx:281` | `shows saved state on alerts when initialSavedAlertIds are provided` | `getByLabelText(/Remove alert/i)` — no match |
| 4 | `FeedView.test.tsx:231` | `passes saved state to AlertCard` | `getByLabelText(/Remove alert/i)` — no match |
| 5 | `SavedView.test.tsx:125` | `calls onToggleSave when removing an alert` | `getByLabelText(/Remove alert/i)` — no match |
| 6 | `AlertDetailsView.test.tsx:200` | `handles save toggle and displays saved state` | `toHaveClass('bg-white/10')` — button now uses `bg-primary` in saved state |

---

## Root Cause

### Group A — `aria-label` drift (tests 1–5)

`AlertCard.tsx` was updated so the button's `aria-label` reflects actual state:

```ts
// Current component (AlertCard.tsx ~L137)
aria-label={
    isPending
        ? 'Processing…'
        : isSaved
          ? 'Remove from saved'   // ← was: 'Remove alert'
          : 'Save alert'
}
```

Tests still query the old labels:

| Test file | Stale query | Current label |
|-----------|-------------|---------------|
| `AlertCard.test.tsx:68` | `'Remove alert'` | `'Remove from saved'` |
| `AlertCard.test.tsx:74` | `'Save alert'` (with `isPending={true}`) | `'Processing…'` |
| `App.test.tsx:281` | `/Remove alert/i` | `'Remove from saved'` |
| `FeedView.test.tsx:231` | `/Remove alert/i` | `'Remove from saved'` |
| `SavedView.test.tsx:125` | `/Remove alert/i` | `'Remove from saved'` |

### Group B — CSS class drift (test 6)

`AlertDetailsView.tsx` was updated so the saved state uses an orange primary style:

```tsx
// Current component (AlertDetailsView.tsx ~L147)
className={`... ${
    isSaved
        ? 'border-primary bg-primary text-black shadow-lg hover:border-red-500 ...'
        : 'border-white/10 text-white hover:border-white/20 hover:bg-white/10'
}`}
```

The test still asserts the unsaved-state class (`bg-white/10`) for the saved-state button:

```ts
// AlertDetailsView.test.tsx:200 (stale)
expect(screen.getByRole('button', { name: /Saved/i })).toHaveClass('bg-white/10');
```

---

## Fix

Update the 5 test files to match the current component contracts. No production code changes required.

### `AlertCard.test.tsx`
- Line 68: `'Remove alert'` → `'Remove from saved'`
- Line 74: `'Save alert'` → `'Processing…'`

### `App.test.tsx`
- Line 281: `/Remove alert/i` → `/Remove from saved/i`

### `FeedView.test.tsx`
- Line 231: `/Remove alert/i` → `/Remove from saved/i`

### `SavedView.test.tsx`
- Line 125: `/Remove alert/i` → `/Remove from saved/i`

### `AlertDetailsView.test.tsx`
- Line 200–202: `toHaveClass('bg-white/10')` → `toHaveClass('bg-primary')`

---

## Files Modified

| File | Change |
|------|--------|
| `resources/js/features/gta-alerts/components/AlertCard.test.tsx` | Update 2 stale `aria-label` queries |
| `resources/js/features/gta-alerts/App.test.tsx` | Update 1 stale `aria-label` query |
| `resources/js/features/gta-alerts/components/FeedView.test.tsx` | Update 1 stale `aria-label` query |
| `resources/js/features/gta-alerts/components/SavedView.test.tsx` | Update 1 stale `aria-label` query |
| `resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx` | Update 1 stale CSS class assertion |
