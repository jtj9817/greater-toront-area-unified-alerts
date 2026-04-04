# FEED-061: DRT Scraper Data Integrity — UTF-8 Body Text + Posted-Date Parser Hardening

## Meta

- **Issue Type:** Bug
- **Priority:** `P1`
- **Status:** Open
- **Labels:** `alerts`, `drt`, `scraping`, `data-integrity`, `backend`, `review`
- **Scope Note:** Production uses `pgsql`. MySQL-specific FULLTEXT concerns are out of scope for this ticket.

## Summary

Post-implementation review found two integrity issues in DRT scraping:

1. UTF-8 detail content is being persisted with mojibake in `body_text` for a subset of live rows.
2. The posted-date parser is stricter than the extraction regex and can silently drop alerts if upstream day formatting shifts.

## Findings (Priority Order)

### P1 — UTF-8 text corruption in persisted `body_text`

**Affected files:**

- `app/Services/DrtServiceAlertsFeedService.php`

**Finding:**

- `extractDetailBodyTextFromHtml()` parses HTML through `DOMDocument::loadHTML()` without explicit UTF-8 normalization.
- Live verification after running `drt:fetch-alerts` showed mojibake tokens in stored `body_text` values (examples: `â¢`, `â`).
- Spot-check query measured `5/9` active rows matching a mojibake signature (`LIKE '%â%'`).

**Impact:**

- User-visible text quality degradation in DRT details.
- Search quality degradation for punctuation/bullet-bearing content.
- Loss of canonical source fidelity in persisted alert body data.

**Required Fix Direction:**

- Normalize detail HTML to UTF-8 before DOM parsing (e.g., explicit encoding strategy before `loadHTML`).
- Add defensive normalization to avoid persisting common mojibake sequences when present.
- Add regression coverage with UTF-8 fixture content (bullets/apostrophes) asserting clean persisted output.

---

### P3 — Posted-date parser can reject valid non-zero-padded day values

**Affected files:**

- `app/Services/DrtServiceAlertsFeedService.php`

**Finding:**

- Extraction regex accepts `\d{1,2}` for day in `Posted on ...` lines.
- Parser currently uses a single format: `Carbon::createFromFormat('l, F d, Y h:i A', ...)`.
- If upstream emits a non-zero-padded day (e.g., `March 3, 2026`), parse can fail and that alert is skipped.

**Impact:**

- Potential silent ingestion loss when upstream date rendering changes.
- Inconsistent resilience between extraction and parsing stages.

**Required Fix Direction:**

- Support both padded and non-padded day formats in `parsePostedAt()`.
- Add tests that explicitly cover both:
  - `Tuesday, February 24, 2026 11:16 AM`
  - `Tuesday, February 9, 2026 09:14 AM`

## Acceptance Criteria

- [ ] DRT detail body text persists UTF-8 characters correctly (no mojibake tokens in normalized output).
- [ ] Date parsing supports both zero-padded and non-zero-padded day variants.
- [ ] New regression tests fail before fix and pass after fix for both issues.
- [ ] Existing DRT feed-service and command/provider suites remain green.

## Validation Notes

- Reproduction was observed during live fetch validation in local Sail environment via `drt:fetch-alerts`.
- Ticket scope intentionally excludes MySQL-specific FULLTEXT concerns due PostgreSQL production baseline.
