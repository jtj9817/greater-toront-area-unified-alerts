# FEED-057: YRT Phase 8 QA Test Logic Review Findings (00e2b686)

## Meta
- **Issue Type:** Bug
- **Priority:** `P1`
- **Status:** Open
- **Labels:** `alerts`, `yrt`, `backend`, `review`, `tests`, `qa`, `regression-risk`
- **Reviewed Commit:** `00e2b686dd7443b4faa752684feeffc2e1b677d2`

## Summary
Review of commit `00e2b686` found three test-quality issues in
`YrtServiceAdvisoriesFeedServiceTest`: one inaccurate branch claim, one incomplete
assertion that can pass for the wrong reason, and one duplicate case that should
be replaced by a distinct edge condition.

## Findings (Priority Order)

### P1 — Fallback extraction test does not prove fallback branch
**Finding:**
- Test `it falls back to document text content when no semantic container exists`
  claims to validate the fallback branch.
- Service extraction includes `//body` as a primary candidate, so this test can
  pass without executing true document-level fallback logic.

**Evidence:**
- `tests/Feature/YrtServiceAdvisoriesFeedServiceTest.php:539`
- `app/Services/YrtServiceAdvisoriesFeedService.php:254`
- `app/Services/YrtServiceAdvisoriesFeedService.php:275`

**Impact:**
- The fallback branch can regress while this test remains green.

**Required fix direction:**
- Update the test fixture and assertions to force `//main|//article|//body`
  candidate extraction to yield no normalized text, then assert document-level
  fallback content is returned.

### P2 — URL slug parsing coverage is incomplete
**Finding:**
- Test `it skips items with unparseable url slug` asserts only that
  `'not-a-url'` is absent from IDs.
- `'not-a-url'` is rejected by URL validation before slug parsing, so this does
  not conclusively validate root-path slug rejection behavior.

**Evidence:**
- `tests/Feature/YrtServiceAdvisoriesFeedServiceTest.php:339`
- `app/Services/YrtServiceAdvisoriesFeedService.php:356`
- `app/Services/YrtServiceAdvisoriesFeedService.php:282`

**Impact:**
- Slug extraction behavior for valid-but-unparseable paths can regress unnoticed.

**Required fix direction:**
- Tighten assertions to prove only the valid advisory survives (e.g., exact
  alert count + expected ID set).

### P3 — Duplicate route-from-body tests reduce distinct branch value
**Finding:**
- Two tests validate essentially the same `Routes affected:` extraction path from
  body text.
- One should be replaced with a distinct edge case covering a different pattern
  variant in the same parser branch.

**Evidence:**
- `tests/Feature/YrtServiceAdvisoriesFeedServiceTest.php:397`
- `tests/Feature/YrtServiceAdvisoriesFeedServiceTest.php:444`
- `app/Services/YrtServiceAdvisoriesFeedService.php:332`

**Impact:**
- Redundant coverage leaves more meaningful route-pattern edge cases untested.

**Required fix direction:**
- Replace one duplicate with a singular-pattern and delimiter-truncation case
  (`Route affected: ...; ...`) to verify parser behavior beyond the existing
  plural path.

## Acceptance Criteria
- [ ] P1 test is updated to prove true document-level fallback behavior.
- [ ] P2 test verifies slug-parse skip behavior with stronger assertions.
- [ ] P3 duplicate case is replaced with a distinct parser edge case.
- [ ] Targeted YRT feed-service test suite passes.
- [ ] Full backend and frontend quality gates pass with no new lint/format/type errors.
- [ ] No Laravel→Inertia→React data-shape changes are introduced.
