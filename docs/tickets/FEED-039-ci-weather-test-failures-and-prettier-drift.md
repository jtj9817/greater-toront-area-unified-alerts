# FEED-039: CI Failure — Weather Test Timeouts and Prettier Formatting Drift

**Type:** Bug
**Priority:** P1
**Status:** Closed
**Component:** Frontend CI (Vitest · Prettier)
**Affects:** `master` — commit `21150db` and all subsequent commits

---

## Summary

Two distinct CI gates are currently red on `master`. The **linter** pipeline fails because four files have diverged from Prettier's expected output. The **tests** pipeline fails because four frontend tests deterministically time out at the `waitFor` default threshold of 1 000 ms.

The test failures are **pre-existing**: they were introduced in commit `fix(weather): add first-visit location onboarding prompt` (FEED-034) but were masked for two subsequent commits (`chore(conductor): add weather feature track`, `fix(security): remediate pnpm transitive vulnerability set`) because those runs failed earlier at the `Install Node Dependencies` step and never reached the test stage. The security remediation commit fixed the install step, revealing the latent failures.

---

## Failing CI Jobs

| Run ID | Job | Status |
|---|---|---|
| 23682298121 | linter — `Format Frontend (Check)` | ❌ failure |
| 23682298113 | tests — `Frontend Tests` | ❌ failure |

---

## Issue 1 — Prettier Formatting Drift (Linter)

### Failing Files

```
resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx
resources/js/features/gta-alerts/components/AlertDetailsView.tsx
resources/js/features/gta-alerts/components/AlertLocationMap.client.test.tsx
resources/js/features/gta-alerts/components/AlertLocationMap.test.tsx
```

### CI Error

```
[warn] Code style issues found in 4 files. Run Prettier with --write to fix.
ELIFECYCLE  Command failed with exit code 1.
```

### Root Cause

The four files were committed without running `pnpm run format`. The Prettier check in CI (`pnpm run format:check`) enforces the project's canonical style and exits non-zero on any diff.

### Fix

```bash
pnpm run format
git add <the four files>
git commit -m "style: apply prettier formatting to weather map components"
```

---

## Issue 2 — Frontend Test Timeouts (Tests)

### Failing Tests

| File | Test Name | Failure Line | Error |
|---|---|---|---|
| `useWeather.test.ts` | `aborts an in-flight request when switching to a different location` | `:338` | `expected undefined to be 'M4B'` |
| `useWeather.test.ts` | `ignores late success responses from a previously selected location` | `:397` | `expected undefined to be 'M4B'` |
| `useWeather.test.ts` | `sets error when API returns a non-ok response` | `:431` | `expected null not to be null` |
| `LocationPicker.test.tsx` | `does not search when input is shorter than 2 characters` | `:105` | timeout (1 007 ms) |

All four tests reach exactly the `waitFor` default timeout (1 000 ms), indicating the React state being asserted against **never reaches the expected value** within the polling window — not an intermittent flake.

### Confirmed Passing Tests (same suite run)

Notably, the following tests in `useWeather.test.ts` **pass** in the same run, which constrains the root cause:

- `fetches weather data when location is set`
- `keeps stale weather data visible while a background fetch is in-flight`
- `sets error when API returns malformed JSON`
- `clears error when a subsequent fetch succeeds`
- `sets isLoading true during initial fetch (no prior weather data)`

---

### Root Cause Analysis

#### Hypothesis A — React 18 Strict Mode effect double-invocation (primary)

`@testing-library/react` v14+ wraps `renderHook` in `React.StrictMode` by default. React 18 deliberately double-invokes effects in Strict Mode (mount → cleanup → mount) to surface side-effect bugs. This directly breaks two of the three `useWeather` tests:

**Test: "aborts an in-flight request" (`:338`)**

The `waitFor` at line 337–339 asserts:
```typescript
await waitFor(() => {
    expect(global.fetch).toHaveBeenCalledTimes(1);
});
```

The location-change `useEffect` in `useWeather.ts` (`:217–225`) calls `fetchWeather` on mount. With Strict Mode double-invocation, the effect fires **twice** per location state update, causing `fetch` to be called **twice** (0 → 2). The count jumps from 0 directly to 2, so `toHaveBeenCalledTimes(1)` is never satisfied, and `waitFor` polls until timeout.

**Test: "ignores late success responses" (`:397`)**

The test registers exactly two pending fetch promises via `.mockReturnValueOnce`:

```typescript
vi.spyOn(global, 'fetch')
    .mockReturnValueOnce(firstPending)   // consumed by effect run #1
    .mockReturnValueOnce(secondPending); // consumed by effect run #2
```

Strict Mode double-invocation causes both mock return values to be consumed by the **first** `setLocation(M5V)` call. When `setLocation(M4B)` is called, `fetch()` has no registered mock return and resolves to `undefined`, throwing a TypeError instead of returning the expected response. Weather data for `M4B` is never set, and `weather?.fsa` remains `undefined`.

#### Hypothesis B — `act()` / microtask flush ordering (secondary)

**Test: "sets error when API returns a non-ok response" (`:431`)**

This test uses `await act(async () => { setLocation(...) })` and `mockResolvedValue` (unlimited calls), so exhausted mock values are not the issue. The error state is never set within the `waitFor` window.

The `useWeather` hook calls `abortControllerRef.current?.abort()` in two places: synchronously inside `setLocation` and as a cleanup in the location `useEffect`. With Strict Mode the cleanup runs between the two effect invocations, aborting the in-flight controller. The second effect invocation creates a new controller and starts a fresh fetch. However, in the Vitest + jsdom environment, there is a known edge case where the abort + re-fetch cycle inside `await act(async () => {...})` can cause the final `setError` call to land outside the current render batch, leaving `error` as `null` when `waitFor` begins polling. This needs verification with a focused reproduction.

#### Hypothesis C — Test isolation with `isolate: false` (LocationPicker)

**Test: "does not search when input is shorter than 2 characters"**

`LocationPicker.handleQueryChange` explicitly guards against fetching for queries shorter than 2 characters (`:143–147`). The component has no mount effects that call `search`. Despite this, `fetchSpy` registers calls, causing `expect(fetchSpy).not.toHaveBeenCalled()` to fail for the full 1 000 ms.

The vitest configuration uses `pool: 'forks'` with `isolate: false` (set as part of the FEED-025 OOM fix). With `isolate: false`, the module registry is shared across tests within the same fork. `vi.resetModules()` is called in `beforeAll` — once per file, not once per test. If a preceding test in the same file leaves unrestored module-level state that affects `fetch` routing, it could bleed into this test despite `vi.restoreAllMocks()` in `beforeEach`. The exact bleed path has not been traced.

---

### Relevant Code Locations

| Location | Relevance |
|---|---|
| `resources/js/features/gta-alerts/hooks/useWeather.ts:217–225` | Location effect that calls `fetchWeather` (aborted by Strict Mode cleanup) |
| `resources/js/features/gta-alerts/hooks/useWeather.ts:169–209` | `fetchWeather` — creates AbortController, checks `locationRef.current?.fsa` |
| `resources/js/features/gta-alerts/hooks/useWeather.ts:159–161` | `locationRef` sync effect |
| `resources/js/features/gta-alerts/hooks/useWeather.ts:239–255` | `setLocation` — aborts in-flight, clears error, sets new location state |
| `resources/js/features/gta-alerts/hooks/useWeather.test.ts:316–357` | Test: abort signal |
| `resources/js/features/gta-alerts/hooks/useWeather.test.ts:359–415` | Test: late success race |
| `resources/js/features/gta-alerts/hooks/useWeather.test.ts:421–436` | Test: 503 error state |
| `resources/js/features/gta-alerts/components/LocationPicker.tsx:138–152` | `handleQueryChange` — length guard |
| `resources/js/features/gta-alerts/components/LocationPicker.test.tsx:94–107` | Test: no search for < 2 chars |
| `vite.config.ts:27–48` | Vitest config (`pool: forks`, `isolate: false`, `environment: jsdom`) |
| `resources/js/tests/setup.ts` | Test setup (`vi.resetModules` in `beforeAll`, `cleanup` in `afterEach`) |

---

### Recommended Fixes

#### `useWeather.test.ts` — Tests 1 & 2 (Strict Mode double-invocation)

Pass `{ wrapper: ({ children }) => children }` (no StrictMode) to `renderHook` in the two affected tests. Alternatively, switch the fixed-count spy patterns to open-ended mocks (`mockResolvedValue` / `mockImplementation`) that can tolerate being called more than once, and restructure the abort test to assert on the *abort signal state* rather than an exact call count.

#### `useWeather.test.ts` — Test 3 (503 error)

Add a focused reproduction: render the hook outside `act`, call `setLocation`, then advance timers / flush promises manually to confirm the `setError` timing. If the flush ordering is the cause, wrapping both the `setLocation` call and the `waitFor` in a single `act` boundary, or using `act` with explicit promise flushing, should resolve it.

#### `LocationPicker.test.tsx` — "does not search" test

Trace whether `isolate: false` + `vi.resetModules()` is leaking a prior fetch mock into this test. A safe interim fix is to add an explicit `vi.clearAllMocks()` at the start of the test body, or to move to `isolate: true` for this file and monitor for OOM regressions.

---

## History

| Commit | What happened |
|---|---|
| `fix(weather): add first-visit location onboarding prompt` | Test failures introduced here (FEED-034) |
| `chore(conductor): add weather feature track` | CI failed at `Install Node Dependencies`; tests never ran |
| `fix(security): remediate pnpm transitive vulnerability set` | Install step fixed; latent test failures surfaced |

---

## Acceptance Criteria

- [x] `pnpm run format:check` exits 0 on `master`.
- [x] All 17 tests in `useWeather.test.ts` pass in CI.
- [x] All 16 tests in `LocationPicker.test.tsx` pass in CI.
- [x] The fix does not re-introduce the vitest OOM failure documented in FEED-025.
- [x] `composer run test` and `pnpm run test` both exit 0 locally.
