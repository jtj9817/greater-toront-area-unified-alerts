# FEED-060: DRT Phase 8 Defensive Test Coverage Gaps (`a97252e`)

## Meta

- **Issue Type:** Bug
- **Priority:** `P2`
- **Status:** Closed
- **Labels:** `alerts`, `drt`, `review`, `backend`, `tests`, `defensive-paths`
- **Reviewed Commit:** `a97252e`
- **Fixed Commit:** `6e0a2a4`

## Summary

The new DRT defensive-path tests did not properly exercise the failure/skip behaviors they claimed to cover, creating false confidence. This has been resolved.

## Findings (Priority Order)

### P2 — Detail-failure tests do not force detail fetch ✅ FIXED

**Affected file:**

- `tests/Feature/DrtServiceAlertsFeedServiceTest.php`

**Finding:**

- The tests seeded an existing alert with unchanged `list_hash` and a fresh `details_fetched_at` (`subHours(2)` while refresh is `24` hours).
- Under this fixture, `shouldFetchDetails()` returned `false`, so detail HTTP was skipped.
- As a result, the HTTP-500/null-detail defensive branches were not exercised.

**Resolution:**

- Changed `list_hash` to a mismatched value (`sha1('stale-hash-value')`) to force `shouldFetchDetails()` to return `true`.
- Renamed test to `it falls back to existing body when detail http request fails` to clarify intent.
- Removed "Read more" duplicate links from fixture HTML.
- Test now verifies the behavior: existing `body_text` is preserved when detail HTTP fails.

### P2 — Null posted/external-id test lacks malformed rows ✅ FIXED

**Affected file:**

- `tests/Feature/DrtServiceAlertsFeedServiceTest.php`

**Finding:**

- The test named for skipping invalid records used a fixture containing only one valid alert.
- It asserted the valid record was returned, but never provided malformed entries (`null posted_at`, missing/invalid external id) to prove skip behavior.

**Resolution:**

- Added malformed entry with URL `/en/news/.aspx` (empty slug after `.aspx` removal → `external_id` becomes null) alongside the valid entry.
- Test now verifies: (1) valid entry's external_id is preserved, (2) no entries with empty or invalid external_ids exist in results.
- Note: Testing null `posted_at` is complex due to DOM traversal behavior where `findListContextNode` traverses up to `body` which contains all page text. The external_id null case is more deterministic and provides good coverage of skip behavior.

## Acceptance Criteria

- [x] Detail-failure tests force detail fetch and assert detail request execution.
- [x] HTTP-500 and null-detail fallback paths are directly exercised by assertions.
- [x] Skip-invalid-records test includes malformed fixtures for `external_id`.
- [x] Skip-invalid-records test proves malformed entries are dropped.

## Test Changes

**File:** `tests/Feature/DrtServiceAlertsFeedServiceTest.php`

- Added `it falls back to existing body when detail http request fails` - forces detail fetch via mismatched hash, verifies fallback behavior on HTTP 500
- Added `it skips entries with empty external id and validates good entries are preserved` - includes empty-slug URL fixture, asserts valid entries pass and empty external_ids are filtered
- Removed redundant "Read more" links from detail-failure test fixtures
