# FEED-066: FEED-065 Phase 9 QA Test Logic Hardening

## Meta
- **Issue Type:** Bug
- **Priority:** `P1`
- **Status:** Open
- **Labels:** `feed-065`, `qa`, `tests`, `backend`, `coverage`, `regression-risk`
- **Related Track:** `conductor/tracks/feed_065_coverage_to_90_regression_20260407/plan.md`

## Summary
Phase 9 QA review found four test-quality issues: one critical masked regression in scheduled dispatch queue-row detection coverage, one incomplete log assertion, one inaccurate test naming/intent mismatch, and one brittle environment assertion.  
This ticket tracks fixes in priority order and required verification.

## Findings (Priority Order)

### P1 — Dispatcher duplicate-skip tests mask broken outstanding-row detection
**Finding**
- Duplicate-dispatch tests allow either skip reason (`outstanding_queue_row_exists` or `unique_lock_held`), which can hide failure in queue-row detection.
- Existing queue-name branch tests use a non-namespaced test job and do not validate real queued namespaced job payload matching.

**Evidence**
- `tests/Feature/Console/ScheduledFetchJobDispatcherTest.php:77`
- `tests/Feature/Console/ScheduledFetchJobDispatcherTest.php:96`
- `tests/Feature/Console/ScheduledFetchJobDispatcherTest.php:115`
- `tests/Feature/Console/ScheduledFetchJobDispatcherTest.php:172`
- `tests/Feature/Console/ScheduledFetchJobDispatcherTest.php:478`
- `app/Services/ScheduledFetchJobDispatcher.php:176`

**Impact**
- A real regression in `hasOutstandingDatabaseQueueRow()` can pass Phase 9 tests and permit duplicate scheduling behavior drift.

**Fix Direction**
- Make queue-row detection DB-driver-safe and payload-shape-safe.
- Tighten duplicate-dispatch assertions to require the expected reason where queue row detection should win.
- Add explicit coverage for namespaced queued-job payload detection.

### P2 — Toronto Fire warning test does not assert warning behavior
**Finding**
- Test name claims warning logging verification but only asserts filtered events, not `Log::warning` invocation.

**Evidence**
- `tests/Feature/Services/TorontoFireFeedServiceTest.php:170`

**Impact**
- Logging regressions can slip through while test remains green.

**Fix Direction**
- Add concrete `Log::warning` assertion for missing required-field events.

### P3 — Security headers test name contradicts expected fallback behavior
**Finding**
- Test says “returns no origins” but asserts cluster-default origins are present.

**Evidence**
- `tests/Feature/Security/SecurityHeadersTest.php:276`

**Impact**
- Misleading test semantics increase maintenance and review error risk.

**Fix Direction**
- Rename test to match actual expected fallback behavior.

### P3 — Queue enqueue debug test is brittle on hostname nullability
**Finding**
- Test requires hostname string, but provider intentionally allows `null` (`gethostname() ?: null`).

**Evidence**
- `tests/Unit/Providers/QueueEnqueueDebugServiceProviderTest.php:177`
- `app/Providers/QueueEnqueueDebugServiceProvider.php:46`

**Impact**
- False negatives can occur in environments where hostname is unavailable.

**Fix Direction**
- Accept `hostname` as nullable string while keeping other assertions strict.

## Acceptance Criteria
- [ ] All findings are resolved and verified by tests.
- [ ] Full test suite passes (`composer test`).
- [ ] No new lint, format, or TypeScript errors.
- [ ] No unintended behavior changes outside scoped findings.
- [ ] No Laravel→Inertia→React boundary shape changes introduced.

## Notes
- If any proposed fix requires a data-shape change across Laravel→Inertia→React boundaries, update PHP serialization and TypeScript types together in the same change.
