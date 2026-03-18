# FEED-025: Vitest 4 Fork Worker Hangs on Shutdown After All Tests Pass

**Status:** Open
**Component:** Test Infrastructure
**Affects:** `pnpm run quality:check` (Gate 2 of GTA-105)

---

## Summary

All 27 test files pass (211/215 tests) but the Vitest fork worker cannot shut down cleanly after the run completes. Vitest force-kills the fork via SIGKILL, which causes it to emit `Worker exited unexpectedly` and exit with code 1, failing the quality gate.

---

## Background

This issue was surfaced while executing Phase 5 quality gates (GTA-105). The Vitest 4 migration commit (`f59d386`) introduced a broken pool configuration, which caused an OOM crash mid-run. Fixing the OOM required applying the correct Vitest 4 migration path for `singleFork: true`. The OOM is now resolved; the shutdown hang is the remaining blocker.

---

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

---

## Error

```
[vitest-pool]: Timeout terminating forks worker for test files [all 27 files].
Error: Worker forks emitted error.
Caused by: Error: Worker exited unexpectedly
```

- Exit code: **1**
- All 27 test files: **pass**
- All 211 tests: **pass**

---

## Root Cause Investigation

### Step 1 — OOM diagnosis and fix

`f59d386` introduced an invalid Vitest 4 config (`threads: {}` as a top-level key — silently ignored). It also removed `NODE_OPTIONS` from `test:ci`. This left worker threads with the default ~1.5 GB V8 heap limit. Phase 4 (saved-alerts) added 7 new component test files that pushed total heap past the limit.

**Fix applied:** Correct Vitest 4 migration of `singleFork: true`:
- `pool: 'forks'`, `maxWorkers: 1`, `isolate: false`
- `NODE_OPTIONS='--expose-gc --max-old-space-size=4096'` restored to `test:ci` (inherited by fork child processes; not applicable to worker threads)
- `vi.resetModules()` in `beforeAll` per Vitest 4 migration guide

**Result:** OOM eliminated. All 27 files pass. Shutdown hang remains.

### Step 2 — GC hypothesis (disproved)

Hypothesis: the `afterAll(() => gc?.())` call was synchronously blocking the fork's event loop during Vitest's graceful shutdown IPC handshake, causing `teardownTimeout` to elapse.

**Test:** Removed `gc()`. Total run duration increased from ~157 s to ~224 s (the GC was benefiting test speed by reclaiming heap between files). The shutdown hang was identical.

**Conclusion:** `gc()` was not the cause.

### Step 3 — Open handle diagnostic

Added `process._getActiveHandles()` / `process._getActiveTimers()` logging in `afterAll`. Result after **every** test file, from file 1 to file 27:

```
[setup] open handles: 3 | active timers: 0
  handle[0]: Pipe
  handle[1]: Socket
  handle[2]: Socket
```

**Interpretation:** These three handles are Vitest's own IPC infrastructure:
- `Pipe` — stdout/stderr pipe from fork to parent (`stdio: 'pipe'` in the `fork()` call)
- `Socket` × 2 — IPC channel used by Vitest's internal RPC protocol

They are present from process start, do not accumulate, and are not created by test code. Test code is **not** responsible for the dangling handle.

### Step 4 — Vitest 4 source analysis

Reviewed `node_modules/.pnpm/vitest@4.0.18/dist/chunks/cli-api.B7PN_QUv.js`:

- `teardownTimeout` (default `1e4` ms, currently `60000`) controls when the `[vitest-pool]: Timeout terminating` log message fires.
- `SIGKILL_TIMEOUT = 500` ms — after SIGTERM, the fork is force-killed within 500 ms regardless.
- The `sharedRunners` code path (line ~8060) is activated when `isolate: false` and the next queued task also has `isolate: false`. After the **last** file, the queue is empty, so `runner.stop()` is correctly reached.
- However, `runner.stop()` does not complete within `teardownTimeout`. The graceful shutdown IPC handshake between Vitest and the fork worker (the step before `ForksPoolWorker.stop()` → SIGTERM → SIGKILL) does not complete.

**Working hypothesis:** This is a Vitest 4 bug in the `isolate: false` shared-runner shutdown code path. With `isolate: true`, each file triggers an individual `runner.stop()` (SIGTERM → SIGKILL) via the normal per-file teardown path. With `isolate: false`, all 27 files share one runner, and the single final `runner.stop()` goes through a different code path that does not complete.

---

## Proposed Next Steps (in order)

1. **Try `isolate: true` with `maxWorkers: 1`.**
   Per the Vitest 4 source, `isolate: true` routes through the per-file teardown path (SIGTERM → SIGKILL after each file), bypassing the shared-runner shutdown. Each file would get a truly fresh fork, eliminating both accumulation and the broken shutdown path. The `vi.resetModules()` in `beforeAll` would become redundant (but harmless). Risk: unknown whether heap per single file stays under 4 GB with fresh-fork overhead.

2. **File a Vitest upstream issue** against `vitest@4.0.18` for `pool: forks, isolate: false, maxWorkers: 1` shared-runner shutdown hang (`runner.stop()` not resolving). Reference: `cli-api.B7PN_QUv.js` line 8060, `sharedRunners` path.

3. **Workaround via `globalSetup` teardown** — add a Vitest `globalSetup` file that calls `process.exit(0)` in its `teardown()` hook. This forces the fork to exit after the main process signals completion. Invasive, but deterministic.

---

## Files Modified (current WIP state)

| File | Change |
|---|---|
| `vite.config.ts` | `pool: forks`, `maxWorkers: 1`, `isolate: false`, `teardownTimeout: 60000` |
| `package.json` | `test:ci` restored `NODE_OPTIONS='--expose-gc --max-old-space-size=4096'`, removed CLI `--pool=forks` override |
| `resources/js/tests/setup.ts` | Added `vi.resetModules()` in `beforeAll`; added `_getActiveHandles` diagnostic in `afterAll`; restored `gc()` in `afterAll` |
