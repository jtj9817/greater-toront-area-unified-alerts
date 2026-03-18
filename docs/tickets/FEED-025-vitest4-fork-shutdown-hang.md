# FEED-025: Apparent Vitest 4 Fork Shutdown Hang Was a Hook Test Infinite Render Loop

**Status:** Resolved
**Component:** Test Infrastructure
**Resolved On:** 2026-03-18
**Affects:** `pnpm run test:ci` / `pnpm run quality:check` (Gate 2 of GTA-105)

---

## Resolution Summary

This was not a Vitest worker shutdown bug.

The actual blocker was an infinite rerender loop in `resources/js/features/gta-alerts/hooks/useInfiniteScroll.test.ts`. One test rendered the hook with inline array/object literals, so every state update created new `initialAlerts` / `filters` references. The hook's reset effect depends on `initialAlerts`, so it re-ran after every render and never settled. Vitest surfaced the symptom as a long-running final test file, which was misread as a fork-shutdown hang.

After stabilizing the test inputs and removing temporary open-handle diagnostics, the suite progresses through all 28 files normally. The previous "hang after all tests pass" no longer reproduces.

---

## Actual Root Cause

Problematic test pattern:

```ts
renderHook(() =>
    useInfiniteScroll({
        initialAlerts: [fireResource(...)],
        filters: { ... },
        ...
    }),
);
```

Why this hung:

- `renderHook` re-executes the callback on every state update
- the callback above creates fresh `initialAlerts` and `filters` objects every render
- `useInfiniteScroll` has a reset effect keyed by `initialAlerts`
- each render therefore triggered another reset, which triggered another render
- the test never completed, so the final file appeared stuck

---

## Fix Applied

1. Rewrote the failing `useInfiniteScroll` test to pass stable `initialProps` into `renderHook(...)`.
2. Removed the temporary active-handle logging from `resources/js/tests/setup.ts`.

## Current Configuration

`vite.config.ts`:
```ts
test: {
    pool: 'forks',
    maxWorkers: 1,
    isolate: false,
    teardownTimeout: 60000,
}
```

`package.json test:ci`:
```json
"NODE_OPTIONS='--expose-gc --max-old-space-size=4096' LARAVEL_BYPASS_ENV_CHECK=1 vitest run"
```

`resources/js/tests/setup.ts` additions:
- `beforeAll(() => vi.resetModules())` — clears module cache before each file; required per Vitest 4 migration guide when using `isolate: false` to prevent Inertia `usePage` mock state from bleeding between files.
- `afterAll(() => gc?.())` — periodic GC hint; reduces heap accumulation across files in the single long-lived fork.

## Validation

- `pnpm exec vitest run resources/js/features/gta-alerts/hooks/useInfiniteScroll.test.ts --reporter=verbose` now passes all 4 tests.
- `pnpm run test:ci` now reaches the end of the Vitest run without the previous hang.
- Current `test:ci` failures are unrelated pre-existing `SettingsView` assertions in `resources/js/features/gta-alerts/components/SettingsView.test.tsx`.

---

## Files Modified

| File | Change |
|---|---|
| `resources/js/features/gta-alerts/hooks/useInfiniteScroll.test.ts` | Replaced unstable inline hook props with stable `initialProps` in the ascending-sort test |
| `resources/js/tests/setup.ts` | Removed temporary active-handle diagnostics; kept `vi.resetModules()` and `gc()` hooks |
