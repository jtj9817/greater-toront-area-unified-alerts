# FEED-053: YRT Phase 2 Feed Service Review Findings (baf2145f)

## Meta

- **Issue Type:** Bug
- **Priority:** Mixed (`P1`/`P3`)
- **Status:** Closed
- **Labels:** `alerts`, `yrt`, `review`, `backend`, `feed-service`
- **Reviewed Commit:** `baf2145f4e738a5245480acc88e9716a29e02360`
- **Context Source:** `20260401_yrt_service_scraping_audit.md` (untracked local audit file)
- **Verification:** `php artisan test --filter=YrtServiceAdvisoriesFeedServiceTest --compact` (13 passed, 38 assertions), `php artisan test --filter=FetchYrtAlertsCommandTest --compact` (5 passed, 27 assertions), `composer test`, `composer lint`, `pnpm run lint`, `pnpm run format`, `pnpm run types`

## Summary

`YrtServiceAdvisoriesFeedService` is broadly consistent with the project’s feed-ingestion patterns (defensive list normalization, conditional detail fetch, and circuit breaker integration). Tests cover key decision branches and resilience behaviors.

This review found one high-severity integration risk where transient detail-fetch failure can erase previously persisted `body_text` once Phase 3 sync persists feed output. A few smaller hardening/maintainability nits are also noted.

## Findings (Priority Order)

### P1 — Detail fetch failure can wipe previously persisted `body_text` for existing alerts

**Affected files:**

- `app/Services/YrtServiceAdvisoriesFeedService.php:119` (by-reference loop)
- `app/Services/YrtServiceAdvisoriesFeedService.php:122` (assigns `body_text` from detail fetch)
- `app/Services/YrtServiceAdvisoriesFeedService.php:209` (detail fetch returns `null` on failure)
- `app/Console/Commands/FetchYrtAlertsCommand.php:47` (consumer persists the returned array via `updateOrCreate`)

**Problem:**

- In the detail-fetch path, the service sets:
  - `$alert['body_text'] = $this->fetchDetailBodyText(...)`
  - `$alert['details_fetched_at'] = $now`
- `fetchDetailBodyText()` returns `null` on any failure (non-2xx, exception, parse failure).
- When this service output is later persisted by a sync command using `updateOrCreate([...], $alertData)`, a transient detail-fetch failure will overwrite a previously non-null `yrt_alerts.body_text` with `NULL`.

**Impact:**

- Loses previously captured advisory detail content (and potentially route-derived information if it is derived from body text).
- Makes the system “forget” details during upstream flakiness, which is the opposite of a resilience goal.

**Repro sketch:**

1. Existing `YrtAlert` row has non-null `body_text`.
2. List JSON changes (hash changes) or refresh becomes stale, forcing detail refresh.
3. Detail request fails (timeout/5xx/HTML parse failure).
4. Service returns `body_text: null`.
5. Sync command persists `body_text: null`.

**Required fix direction:**

- In the detail-fetch path, if the detail fetch yields `null` and there is an existing record, preserve the existing `body_text` (and likely preserve `details_fetched_at` rather than setting it to “now”).
- Add a focused test asserting “existing body + forced refresh + failed detail fetch => body is preserved”.

---

### P3 — `foreach` by-reference should `unset($alert)` after loop

**Affected file:**

- `app/Services/YrtServiceAdvisoriesFeedService.php:119`

**Problem:**

- `foreach ($normalized as &$alert)` leaves `$alert` as a reference to the last element after the loop ends, which is a classic PHP footgun during later refactors.

**Impact:**

- Low today (no reuse of `$alert` after the loop), but easy to accidentally introduce subtle bugs later.

**Required fix direction:**

- Add `unset($alert);` immediately after the loop.

---

### P3 — Route text segment splitting is correct but hard to read

**Affected file:**

- `app/Services/YrtServiceAdvisoriesFeedService.php:328`

**Problem:**

- `preg_split('/[\.|;|]/', $segment)` uses a character class that includes `.` `|` `;`. It works, but it reads like alternation to most readers.

**Impact:**

- Maintainability/readability.

**Required fix direction:**

- Prefer a clearer pattern like `preg_split('/[.;|]/', $segment, 2)`.

---

### P3 — HTTP client hardening nits

**Affected file:**

- `app/Services/YrtServiceAdvisoriesFeedService.php:66`

**Notes:**

- Consider adding `connectTimeout(...)` for external calls.
- `Accept-Language` currently uses `en-US`; if the intent is Canadian English, consider `en-CA` (not required for correctness if YRT ignores it).

## Resolution

- `P1` fixed in `YrtServiceAdvisoriesFeedService`: failed detail refresh now preserves existing `body_text` and `details_fetched_at` instead of overwriting with `null`.
- Regression coverage added: stale refresh + failed detail request now asserts details are preserved.
- `P3` fixed: added `unset($alert)` after by-reference loop.
- `P3` fixed: route segment split pattern updated to `preg_split('/[.;|]/', ..., 2)` for readability.
- `P3` fixed: HTTP client now sets `connectTimeout(5)` and uses `Accept-Language: en-CA,en;q=0.9`.

These fixes are part of Phase 2 - Feed Service (List JSON + Conditional Detail HTML).
