# FEED-049: Review Fix — Move MiWay Phase 4 Manual Scheduler Verification Script

## Summary

Resolves a review finding by moving the MiWay Phase 4 manual scheduler verification script out of `tests/` so it cannot be accidentally executed by tooling that scans or loads PHP under `tests/**`.

These fixes are part of **Phase 4: Scheduler (Job Wrapper + Registration)**.

## Component

- Manual verification scripts
- Developer tooling — `scripts/run-manual-test.sh`

---

## Findings

### P3 — Manual verification script lives under `tests/`

**Reviewed file:** `tests/manual/verify_miway_phase_4_scheduler.php:1`

**Issue:** The script bootstraps the full Laravel app; if future tooling expands to scan or load `tests/**`, it risks side effects (DB/cache writes) and flaky failures.

**Fix:** Move the script to `scripts/manual_tests/` and update `scripts/run-manual-test.sh` to allow running scripts from either `tests/manual/` or `scripts/manual_tests/`.

---

## Verification

- `vendor/bin/sail artisan test --compact tests/Feature/Console/ScheduledFetchJobDispatcherTest.php`
- `vendor/bin/sail artisan test --compact tests/Feature/Jobs/FetchMiwayAlertsJobTest.php`
- `vendor/bin/sail composer test`
- `vendor/bin/sail bin pint --format agent`
- `vendor/bin/sail composer lint`
- `vendor/bin/sail pnpm run lint`
- `vendor/bin/sail pnpm run format`
- `vendor/bin/sail pnpm run types`

## Acceptance Criteria

- [x] `verify_miway_phase_4_scheduler.php` is no longer located under `tests/`.
- [x] `scripts/run-manual-test.sh` supports running scripts from `scripts/manual_tests/`.
- [x] Targeted tests pass for scheduler/dispatcher/job.
- [x] Full test suite passes (`composer test`).
- [x] No new Pint, ESLint, Prettier, or TypeScript errors.

## Status

**CLOSED**
