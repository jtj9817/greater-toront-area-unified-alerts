# Ticket: SCHED-002 - Scheduler Resilience Phase 2 Review Findings

**Status:** Closed
**Priority:** High
**Assignee:** Unassigned
**Labels:** scheduler, resilience, bug, technical-debt
**Verified on codebase (2026-02-18):** Fetch jobs use `releaseAfter(30)`, DB exceptions are re-thrown in command persistence paths, and job middleware tests assert `releaseAfter` behavior.

## Summary
Critical configuration issues in Job middleware preventing retries and broad exception handling masking infrastructure failures were identified during the Phase 2 code review (commit `a83e653`).

## Description

### 1. Middleware `dontRelease()` prevents job retries (High)
**Location:** `app/Jobs/FetchFireIncidentsJob.php`, `app/Jobs/FetchGoTransitAlertsJob.php`, `app/Jobs/FetchPoliceCallsJob.php`, `app/Jobs/FetchTransitAlertsJob.php`

The `dontRelease()` method is currently used in the `WithoutOverlapping` middleware.
```php
(new WithoutOverlapping('key'))
    ->dontRelease() // <--- PROBLEM
    ->expireAfter(10 * 60)
```
**Impact:** If a job fails (e.g., API timeout or runtime exception), the lock remains held for the full 10-minute duration. The queued retry (scheduled for 30s later via `$backoff`) will fail to acquire the lock and be skipped/delayed until the lock expires.
**Resolution:** Remove `->dontRelease()` so the lock is released on failure, allowing the immediate retry to acquire it.

### 2. Broad Exception Handling Swallows Infrastructure Failures (High)
**Location:**
*   `app/Console/Commands/FetchPoliceCallsCommand.php`
*   `app/Console/Commands/FetchTransitAlertsCommand.php`

**Impact:** The `try-catch (Throwable $e)` blocks around database persistence (`updateOrCreate`) catch critical infrastructure errors (like `QueryException` when the database connection is lost). The command logs a warning, continues processing, and eventually returns `SUCCESS` (0). Consequently, the wrapping Job sees a success exit code and **does not retry**, defeating the purpose of the migration to Jobs.
**Resolution:** Explicitly catch or re-throw `Illuminate\Database\QueryException` (or check `instanceof` inside the catch block) to ensure the command fails with a non-zero exit code, triggering the job's retry logic.

### 3. Tests Validate Incorrect Configuration (Low)
**Location:** `tests/Feature/Jobs/FetchFireIncidentsJobTest.php`, `tests/Feature/Jobs/FetchGoTransitAlertsJobTest.php`, `tests/Feature/Jobs/FetchPoliceCallsJobTest.php`, `tests/Feature/Jobs/FetchTransitAlertsJobTest.php`

**Impact:** The tests explicitly assert that the middleware has `releaseAfter` set to null, which verifies that `dontRelease()` is present.
**Resolution:** Update tests to stop asserting `releaseAfter` is null (or assert the opposite if applicable) to align with the fix in Issue #1.

## Acceptance Criteria
- [ ] `dontRelease()` removed from all 4 Fetch Jobs.
- [ ] `FetchPoliceCallsCommand` re-throws DB exceptions.
- [ ] `FetchTransitAlertsCommand` re-throws DB exceptions.
- [ ] Job tests updated to remove assertions for `releaseAfter` being null.
