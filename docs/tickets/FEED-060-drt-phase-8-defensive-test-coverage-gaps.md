# FEED-060: DRT Phase 8 Defensive Test Coverage Gaps (`a97252e`)

## Meta

- **Issue Type:** Bug
- **Priority:** `P2`
- **Status:** Open
- **Labels:** `alerts`, `drt`, `review`, `backend`, `tests`, `defensive-paths`
- **Reviewed Commit:** `a97252e`

## Summary

The new DRT defensive-path tests do not currently execute the main failure/skip behaviors they claim to cover. This creates false confidence because the tests can pass even when those defensive branches regress.

## Findings (Priority Order)

### P2 — Detail-failure tests do not force detail fetch

**Affected file:**

- `tests/Feature/DrtServiceAlertsFeedServiceTest.php:417`

**Finding:**

- The tests seed an existing alert with unchanged `list_hash` and a fresh `details_fetched_at` (`subHours(2)` while refresh is `24` hours).
- Under this fixture, `shouldFetchDetails()` returns `false`, so detail HTTP is skipped.
- As a result, the HTTP-500/null-detail defensive branches are not exercised.

**Impact:**

- Regressions in detail-failure handling can slip through while tests remain green.

**Required fix direction:**

- Make fixtures stale (or otherwise force detail refresh) in these tests.
- Assert that the detail URL request is attempted before asserting fallback behavior.

### P2 — Null posted/external-id test lacks malformed rows

**Affected file:**

- `tests/Feature/DrtServiceAlertsFeedServiceTest.php:471`

**Finding:**

- The test named for skipping invalid records uses a fixture containing only one valid alert.
- It asserts the valid record is returned, but never provides malformed entries (`null posted_at`, missing/invalid external id) to prove skip behavior.

**Impact:**

- Filtering regressions for malformed list entries can go undetected.

**Required fix direction:**

- Add explicit malformed rows to the fixture for this test.
- Assert malformed rows are excluded and only valid rows survive normalization.

## Acceptance Criteria

- [ ] Detail-failure tests force detail fetch and assert detail request execution.
- [ ] HTTP-500 and null-detail fallback paths are directly exercised by assertions.
- [ ] Skip-invalid-records test includes malformed fixtures for `posted_at` and `external_id`.
- [ ] Skip-invalid-records test proves malformed entries are dropped.
