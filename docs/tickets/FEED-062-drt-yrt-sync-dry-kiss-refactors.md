# FEED-062: DRT/YRT Sync + Feed Service — DRY/KISS Refactors and Laravel Optimizations

## Meta

- **Issue Type:** Refactor / Tech Debt
- **Priority:** `P2`
- **Status:** Closed
- **Labels:** `alerts`, `drt`, `yrt`, `backend`, `performance`, `dry`, `kiss`, `laravel`
- **Scope Note:** No Laravel→Inertia→React contract shape changes intended.

## Summary

Post-implementation review of the **DRT Service Alerts Scraping Integration** identified a few low-risk refactors that simplify code, reduce duplication, and improve query efficiency while keeping behavior the same.

## Findings (Priority Order)

### P1 — Remove per-row existing lookups in DRT/YRT sync commands (avoid N+1 queries)

**Affected files:**

- `app/Console/Commands/FetchDrtAlertsCommand.php:35`
- `app/Console/Commands/FetchYrtAlertsCommand.php:35`

**Finding:**

- Both commands run `->first()` per alert inside the sync loop to determine whether an alert is new/reactivated.
  - `FetchDrtAlertsCommand` does this at `app/Console/Commands/FetchDrtAlertsCommand.php:40`.
  - `FetchYrtAlertsCommand` does this at `app/Console/Commands/FetchYrtAlertsCommand.php:40`.

**Impact:**

- Scales as `O(N)` additional queries per run.
- Adds noise to otherwise simple transactional sync logic.

**Required Fix Direction:**

- Replace per-alert `first()` calls with either:
  - a single preload query keyed by `external_id`, **or**
  - post-persist detection using `$model->wasRecentlyCreated` / `$model->wasChanged('is_active')`.

---

### P2 — Reduce row payload in DRT detail-hydration query (select only needed columns)

**Affected files:**

- `app/Services/DrtServiceAlertsFeedService.php:225`

**Finding:**

- `hydrateDetails()` loads full `DrtAlert` rows via `->get()` and only uses `list_hash`, `body_text`, and `details_fetched_at`.
  - Query lives at `app/Services/DrtServiceAlertsFeedService.php:231`.

**Impact:**

- Unnecessary IO + memory usage (especially if `body_text` grows).

**Required Fix Direction:**

- Select only the required columns (`external_id`, `list_hash`, `body_text`, `details_fetched_at`) in the preload query.

---

### P3 — DRY the duplicated “service advisories sync” transaction between DRT and YRT commands

**Affected files:**

- `app/Console/Commands/FetchDrtAlertsCommand.php:35`
- `app/Console/Commands/FetchYrtAlertsCommand.php:35`

**Finding:**

- The transaction structure and deactivation logic are copy/paste between the DRT and YRT commands.

**Impact:**

- Increased maintenance surface (changes must be mirrored in two places).

**Required Fix Direction:**

- Extract a small shared helper (service or concern) to handle:
  - upsert loop
  - inactive deactivation pass
  - returning synced count, deactivated count, and new/reactivated models

## Acceptance Criteria

- [x] No per-row `first()` queries remain in DRT/YRT sync loops.
- [x] `DrtServiceAlertsFeedService::hydrateDetails()` only selects required columns.
- [x] DRT and YRT sync commands share one simple sync helper (no over-abstraction).
- [x] All existing command/feed tests remain green, including:
  - `tests/Feature/Commands/FetchDrtAlertsCommandTest.php`
  - `tests/Feature/Commands/FetchYrtAlertsCommandTest.php`
  - `tests/Feature/DrtServiceAlertsFeedServiceTest.php`

## Resolution

- Extracted shared sync transaction logic into `ServiceAdvisoriesSyncService` and updated both DRT/YRT commands to use it.
- Removed per-alert “existing row” lookups (`first()`) by using post-persist model state (`wasRecentlyCreated` / `wasChanged('is_active')`) to detect new/reactivated records.
- Reduced the `DrtServiceAlertsFeedService::hydrateDetails()` preload query to only required columns.

## Validation Notes

- Targeted:
  - `./vendor/bin/sail artisan test --compact tests/Feature/Commands/FetchDrtAlertsCommandTest.php`
  - `./vendor/bin/sail artisan test --compact tests/Feature/Commands/FetchYrtAlertsCommandTest.php`
  - `./vendor/bin/sail artisan test --compact tests/Feature/DrtServiceAlertsFeedServiceTest.php`
- Quality gates:
  - `./vendor/bin/sail bin pint --dirty --format agent`
  - `./vendor/bin/sail composer lint`
  - `./vendor/bin/sail pnpm run lint`
  - `./vendor/bin/sail pnpm run format`
  - `./vendor/bin/sail pnpm run types`
- Full suite:
  - `./vendor/bin/sail composer test`
