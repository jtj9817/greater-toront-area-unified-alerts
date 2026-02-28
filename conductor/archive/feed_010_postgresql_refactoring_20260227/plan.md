# Implementation Plan: FEED-010 PostgreSQL Compatibility Refactor

This track removes MySQL-only SQL from the unified feed providers so the app runs on PostgreSQL in production while keeping SQLite (tests) and MySQL (local/dev) working.

---

## Phase 0: Preflight Decisions (Don’t Code Yet)
- [x] Task: Confirm supported DB drivers and expectations.
    - [x] Sub-task: Treat SQLite as “test/dev fallback” (no native FTS); MySQL as “FTS + LIKE fallback”; PostgreSQL as “FTS + ILIKE fallback”.
    - [x] Sub-task: Explicitly document whether `mariadb` should be treated as MySQL-family (optional but recommended, since `config/database.php` defines it).
    - [x] Notes: `conductor/tracks/feed_010_postgresql_refactoring/phase_0_preflight_decisions.md`
- [x] Task: Lock down transport invariants used by the frontend typed boundary.
    - [x] Sub-task: `meta.intel_summary` must be a JSON array (never `null`); default should be `[]`.
    - [x] Sub-task: `meta.intel_last_updated` must be `null` or an ISO-8601 timestamp with timezone offset (`Z` or `+00:00`), matching `resources/js/features/gta-alerts/domain/alerts/fire/schema.ts`.
    - [x] Notes: `conductor/tracks/feed_010_postgresql_refactoring/phase_0_preflight_decisions.md`

---

## Phase 1: Database Indexing & Migrations (FTS)
- [x] (67dd036) Task: Add Postgres full-text indexes in a **new** migration (recommended).
    - [x] (67dd036) Why: Editing `database/migrations/2026_02_19_120000_add_fulltext_indexes_to_alert_tables.php` is not sufficient if it has already been recorded as “ran” on a Postgres environment (it currently returns early on non-MySQL, but still may have been logged).
    - [x] (67dd036) Sub-task: Create a new migration that runs only when `Schema::getConnection()->getDriverName() === 'pgsql'`.
    - [x] (67dd036) Sub-task: Use `CREATE INDEX CONCURRENTLY IF NOT EXISTS` to avoid blocking live writes during deployment.
    - [x] (67dd036) Sub-task: Set `public $withinTransaction = false;` in the migration class so `CONCURRENTLY` works.
    - [x] (67dd036) Sub-task: Create the four indexes with names that match the existing convention:
        - `fire_incidents_fulltext`
        - `police_calls_fulltext`
        - `transit_alerts_fulltext`
        - `go_transit_alerts_fulltext`
    - [x] (67dd036) Sub-task: Use a tsvector expression that is:
        - robust to nullable columns, and
        - matchable by the provider WHERE clause for index usage.
      **Recommended pattern:** `to_tsvector('simple', concat_ws(' ', col1, col2, ...))` (so `NULL` inputs don’t null-out the whole vector. Using `'simple'` instead of `'english'` avoids stemming, which is often better for proper nouns like street names. Note: FTS still tokenizes; substring/prefix matching is handled by the required `ILIKE` fallback in Phase 3, not by `to_tsvector` alone).
    - [x] (67dd036) Sub-task: Add `down()` logic that drops indexes safely (`DROP INDEX CONCURRENTLY IF EXISTS ...`).
- [x] (67dd036) Task: Decide what to do with the existing MySQL-only migration.
    - [ ] Sub-task: Option A (safe): leave it MySQL-only and rely on the new pgsql migration.
    - [x] (67dd036) Sub-task: Option B (cleanup): broaden it to `mysql`/`mariadb` while keeping pgsql handled by the new migration.
- [x] (67dd036) Task: Verification of index presence and usage.
    - [x] (67dd036) Sub-task: Add a short runbook step (or dev note) to confirm index existence (`pg_indexes`) and confirm planner usage via `EXPLAIN` on representative provider queries with `q`.
    - [x] (67dd036) Notes: `conductor/tracks/feed_010_postgresql_refactoring/phase_1_index_verification.md`

---

## Phase 2: Provider Refactors (Core Compatibility)
> Files:  
> - `app/Services/Alerts/Providers/FireAlertSelectProvider.php`  
> - `app/Services/Alerts/Providers/PoliceAlertSelectProvider.php`  
> - `app/Services/Alerts/Providers/TransitAlertSelectProvider.php`  
> - `app/Services/Alerts/Providers/GoTransitAlertSelectProvider.php`

- [x] (eb6d2a3) Task: Add explicit `$driver === 'pgsql'` branches (do not fall through to MySQL SQL).
    - [x] (eb6d2a3) Sub-task: Ensure `id` and `external_id` are **text** across all providers on pgsql (cast numeric identifiers like `object_id`).
    - [x] (eb6d2a3) Sub-task: Ensure `lat` and `lng` are explicitly cast if they are `NULL` (e.g., `CAST(NULL AS double precision)`) to prevent `UNION ALL` coercion edge cases.
    - [x] (eb6d2a3) Sub-task: Replace MySQL-only string helpers (`CONCAT`, `CONCAT_WS`, `IF`, `IFNULL`) with pgsql-safe equivalents (`||`, `concat_ws`, `CASE`, `coalesce`).
    - [x] (eb6d2a3) Sub-task: Replace JSON constructors with pgsql equivalents (`json_build_object`, `json_agg`). Crucially, explicitly cast the final `meta` expression to `::jsonb` (or `::text`) across all providers so `UNION ALL` has strictly matching types.
- [x] Task: Conductor - User Manual Verification 'Phase 2: Provider Refactors (Core Compatibility)' (Protocol in workflow.md; script: `tests/manual/verify_feed_010_phase_2_provider_refactors_core_compatibility.php`)
    - [x] Notes: PASS log `storage/logs/manual_tests/feed_010_phase_2_provider_refactors_core_compatibility_2026_02_26_042535.log`
    - [x] (eb6d2a3) Sub-task: Add manual verification script `tests/manual/verify_feed_010_phase_2_provider_refactors_core_compatibility.php`.
    - [x] Sub-task: Verify all 4 providers execute without `QueryException` on pgsql (`UNION ALL` type consistency, no MySQL-only functions in the pgsql branch).
    - [x] Sub-task: Verify `id` and `external_id` return string values for all providers (e.g., Police `object_id` is cast from numeric).
    - [x] Sub-task: Verify `meta` column parses as valid JSON for every row returned by each provider.
    - [x] Sub-task: Verify `lat`/`lng` columns are `null` or numeric (no type coercion errors from the `UNION ALL`).

---

## Phase 3: Provider Refactors (Search + Scene Intel Meta)
- [x] (0e3ced4) Task: PostgreSQL `q` filtering must be implemented at the provider layer.
    - [x] (0e3ced4) Sub-task: Use Postgres FTS (`to_tsvector(...) @@ plainto_tsquery(...)`) with an expression that matches the migration’s index expression.
    - [x] (0e3ced4) Sub-task: Add a substring fallback **in addition** to FTS (ticket requires this) using `ILIKE`/`LOWER(...) LIKE` across the same columns used for FTS.
    - [x] (0e3ced4) Sub-task: Ensure nullable columns do not break search (wrap with `coalesce(col, '')` where needed).
- [x] (0e3ced4) Task: Fire provider Scene Intel embedding must be pgsql-safe and frontend-valid.
    - [x] (0e3ced4) Sub-task: Implement a pgsql version of `getSummarySubquery()` using `json_build_object` + `json_agg`.
    - [x] (0e3ced4) Sub-task: Ensure deterministic ordering of the summary items (most recent first) via `ORDER BY` inside the aggregate (or equivalent safe ordering approach).
    - [x] (0e3ced4) Sub-task: Ensure `intel_summary` is always a JSON array (`COALESCE(..., '[]'::json)`), never `null`.
    - [x] (0e3ced4) Sub-task: Format both per-item `timestamp` **and** the overall `intel_last_updated` (from `getLastUpdatedSubquery()`) to ISO-8601 with offset (`...Z` is acceptable) so the frontend Fire schema does not discard alerts.
- [x] Task: Conductor - User Manual Verification 'Phase 3: Provider Refactors (Search + Scene Intel Meta)' (Protocol in workflow.md; script: `tests/manual/verify_feed_010_phase_3_provider_refactors_search_scene_intel_meta.php`)
    - [x] Notes: PASS log `storage/logs/manual_tests/feed_010_phase_3_provider_refactors_search_scene_intel_meta_2026_02_26_173438.log`
    - [x] (0e3ced4) Sub-task: Add manual verification script `tests/manual/verify_feed_010_phase_3_provider_refactors_search_scene_intel_meta.php`.
    - [x] Sub-task: Seed known data and verify `q=<term>` returns matching results via pgsql FTS (`to_tsvector @@ plainto_tsquery`), and that results are absent when the term does not match.
    - [x] Sub-task: Verify the `ILIKE` substring fallback also matches the same seeded data (confirms combined FTS + `ILIKE` path is functional on pgsql).
    - [x] Sub-task: Verify `meta->intel_summary` is always a JSON array (never `null`) for all fire alert rows, including incidents with zero updates.
    - [x] Sub-task: Verify `meta->intel_last_updated` is `null` for incidents with no updates, and a valid ISO-8601 timestamp with timezone offset for incidents that have updates.

---

## Phase 4: End-to-End Verification (SQLite + MySQL + PostgreSQL)
- [x] Task: Keep existing test baselines green.
    - [x] Sub-task: Run test suite on SQLite (default `phpunit.xml`).
    - [x] Sub-task: Run test suite on MySQL (`phpunit.mysql.xml`) to ensure no regressions.
- [x] Task: Add a repeatable Postgres verification path (currently missing).
    - [x] Sub-task: Add a Postgres service to `compose.yaml` (or document an external Postgres dev DB) so contributors can actually run pgsql checks locally.
    - [x] Sub-task: Add a `phpunit.pgsql.xml` (or equivalent) config and environment variables for a pgsql test database.
    - [x] Sub-task: Add/extend a small number of tests to cover pgsql-only invariants:
        - `meta.intel_summary` array (never null)
        - `meta.intel_last_updated` ISO-8601 offset formatting when incident updates exist
        - `q` filtering reduces result set on pgsql (at least one provider)
- [x] Task: Smoke test on a pgsql environment.
    - [x] Sub-task: Load `/` and `/api/feed` with and without `q`, ensure no `QueryException`.
    - [x] Sub-task: Confirm filters (`status`, `source`, `since`, `cursor`) still behave deterministically.
- [x] Task: Conductor - User Manual Verification 'Phase 4: End-to-End Verification (SQLite + MySQL + PostgreSQL)' (Protocol in workflow.md; script: `tests/manual/verify_feed_010_phase_4_end_to_end_verification.php`)
    - [x] Notes: PASS log `storage/logs/manual_tests/feed_010_phase_4_end_to_end_verification_2026_02_27_033422.log`
    - [x] Sub-task: Add manual verification script `tests/manual/verify_feed_010_phase_4_end_to_end_verification.php`.
    - [x] Sub-task: Confirm `/api/feed` responds without errors on pgsql for all filter combinations (`status`, `source`, `since`, `q`); no `QueryException` should be raised.
    - [x] Sub-task: Confirm `meta.intel_summary` invariant holds end-to-end (always a JSON array, never `null`) across the full pgsql feed response.
    - [x] Sub-task: Confirm `meta.intel_last_updated` is ISO-8601 with offset in the full feed response for fire incidents that have updates.
    - [x] Sub-task: Confirm `q` filter reduces the result set on pgsql (at minimum one provider returns fewer rows when a known search term is applied vs. no `q`).

---

## Phase 5: Documentation + Registry Hygiene
- [x] Task: Update docs that currently state “MySQL production”.
    - [x] Sub-task: Update `README.md` (root), `conductor/tech-stack.md`, `docs/backend/unified-alerts-system.md`, and `docs/plans/hetzner-forge-deployment-preflight.md` to reflect PostgreSQL production support (and the cross-driver search behavior).
    - [x] Sub-task: Document how to run pgsql verification locally (docker/service + test config).
- [x] Task: Close track in registry.
    - [x] Sub-task: Archive this track folder and update `conductor/tracks.md` when complete.
- [x] Task: Conductor - User Manual Verification 'Phase 5: Documentation + Registry Hygiene' (Protocol in workflow.md; script: `tests/manual/verify_feed_010_phase_5_documentation_registry_hygiene.php`)
    - [x] Notes: PASS log `storage/logs/manual_tests/feed_010_phase_5_documentation_registry_hygiene_2026_02_28_023227.log`
    - [x] Sub-task: Add manual verification script `tests/manual/verify_feed_010_phase_5_documentation_registry_hygiene.php`.
    - [x] Sub-task: Verify all documentation files updated in this phase exist and contain a section covering PostgreSQL production support and cross-driver search behaviour.
    - [x] Sub-task: Verify `phpunit.pgsql.xml` exists and defines the correct pgsql environment variable keys (DB driver, host, database, credentials).
    - [x] Sub-task: Verify `conductor/tracks.md` reflects this track as archived/completed.
