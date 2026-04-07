# FEED-063: Saved Filter Presets Review Regressions (FEED-003 Follow-up)

## Meta

- **Issue Type:** Bug
- **Priority:** `P2`
- **Status:** Closed
- **Labels:** `alerts`, `frontend`, `inertia`, `review`, `regression`, `filter-presets`
- **Parent Ticket:** [FEED-003: Saved Filter Presets](./FEED-003-saved-filter-presets.md)
- **Relationship Note:** This ticket is part of `docs/tickets/FEED-003-saved-filter-presets.md` and is related to fixes for that ticket.

## Summary

Code review identified three user-visible regressions in the saved preset implementation:

- Preset application performs navigation from inside a React state updater.
- Applying presets drops active sort order from the URL.
- Preset row visibility bypasses minimal-mode hidden section flags.

## Findings (Priority Order)

### P2 — Move navigation side effects out of state updater

**Affected File**

- `resources/js/features/gta-alerts/hooks/useFilterPresets.ts` (`applyPreset`, around lines 261-267)

**Finding**

`applyPreset` triggers `router.get` inside a `setPresets` functional updater. Updater functions must remain pure and can be invoked more than once (including under React StrictMode), which can lead to duplicate Inertia navigations for a single user action.

**Required Fix Direction**

- Resolve the target preset from current state before calling `setPresets`.
- Move `router.get` invocation outside the functional updater.

### P2 — Preserve sort order when applying a preset

**Affected File**

- `resources/js/features/gta-alerts/hooks/useFilterPresets.ts` (`applyPreset`, around lines 268-276)

**Finding**

When applying a preset, the rebuilt query omits the `sort` parameter. Users viewing non-default order (for example `sort=asc`) are reset back to default order after clicking a preset.

**Required Fix Direction**

- Preserve current `sort` when composing preset-applied query parameters.
- Ensure preset application behavior is consistent with other filter controls that retain sort state.

### P3 — Honor minimal-mode hidden flags for preset row

**Affected File**

- `resources/js/features/gta-alerts/components/FeedView.tsx` (preset row render condition, around lines 344-347)

**Finding**

Preset chips render whenever presets exist or filters are non-default, without checking `hiddenSections`. In minimal mode, this exposes filter controls even when filter/status/category rows are collapsed.

**Required Fix Direction**

- Gate preset row visibility using the same hidden section logic used by other feed-control rows.
- Keep minimal mode behavior consistent across all filter-related UI rows.

## Acceptance Criteria

- [x] `applyPreset` no longer performs navigation side effects inside `setState` updater callbacks.
- [x] Clicking a preset preserves existing `sort` query state unless an explicit sort override is intended.
- [x] Preset row visibility respects minimal-mode hidden flags and does not leak hidden controls.
- [x] Add regression coverage for: single navigation call on preset apply, sort retention, and minimal-mode preset-row hiding.

## Resolution

- `useFilterPresets.applyPreset` now resolves presets from state and performs `router.get` outside state-updater callbacks.
- Preset application now preserves current sort direction (`sort=asc` retained when active).
- `FeedView` now hides the preset row when minimal-mode filter section is hidden.
- Regression tests were added/updated in:
  - `resources/js/features/gta-alerts/hooks/useFilterPresets.test.ts`
  - `resources/js/features/gta-alerts/components/FeedView.test.tsx`

These fixes are part of FEED-003 follow-up work.
