# Implementation Plan: FEED-010 PostgreSQL Compatibility Refactor

This track removes MySQL-only SQL from the unified feed providers so the app runs on PostgreSQL in production while keeping SQLite (tests) and MySQL (local/dev) working.

---

## Phase 0: Preflight Decisions (Don’t Code Yet)
- [ ] Task: Confirm supported DB drivers and expectations.
    - [ ] Sub-task: Treat SQLite as “test/dev fallback” (no native FTS); MySQL as “FTS + LIKE fallback”; PostgreSQL as “FTS + ILIKE fallback”.
    - [ ] Sub-task: Explicitly document whether `mariadb` should be treated as MySQL-family (optional but recommended, since `config/database.php` defines it).
- [ ] Task: Lock down transport invariants used by the frontend typed boundary.
    - [ ] Sub-task: `meta.intel_summary` must be a JSON array (never `null`); default should be `[]`.
    - [ ] Sub-task: `meta.intel_last_updated` must be `null` or an ISO-8601 timestamp with timezone offset (`Z` or `+00:00`), matching `resources/js/features/gta-alerts/domain/alerts/fire/schema.ts`.

---

## Phase 1: Database Indexing & Migrations (FTS)
- [ ] Task: Add Postgres full-text indexes in a **new** migration (recommended).
    - [ ] Why: Editing `database/migrations/2026_02_19_120000_add_fulltext_indexes_to_alert_tables.php` is not sufficient if it has already been recorded as “ran” on a Postgres environment (it currently returns early on non-MySQL, but still may have been logged).
    - [ ] Sub-task: Create a new migration that runs only when `Schema::getConnection()->getDriverName() === 'pgsql'`.
    - [ ] Sub-task: Create the four indexes with names that match the existing convention:
        - `fire_incidents_fulltext`
        - `police_calls_fulltext`
        - `transit_alerts_fulltext`
        - `go_transit_alerts_fulltext`
    - [ ] Sub-task: Use a tsvector expression that is:
        - robust to nullable columns, and
        - matchable by the provider WHERE clause for index usage.
      **Recommended pattern:** `to_tsvector('english', concat_ws(' ', col1, col2, ...))` (so `NULL` inputs don’t null-out the whole vector).
    - [ ] Sub-task: Add `down()` logic that drops indexes safely (`DROP INDEX IF EXISTS ...`).
- [ ] Task: Decide what to do with the existing MySQL-only migration.
    - [ ] Sub-task: Option A (safe): leave it MySQL-only and rely on the new pgsql migration.
    - [ ] Sub-task: Option B (cleanup): broaden it to `mysql`/`mariadb` while keeping pgsql handled by the new migration.
- [ ] Task: Verification of index presence and usage.
    - [ ] Sub-task: Add a short runbook step (or dev note) to confirm index existence (`pg_indexes`) and confirm planner usage via `EXPLAIN` on representative provider queries with `q`.

---

## Phase 2: Provider Refactors (Core Compatibility)
> Files:  
> - `app/Services/Alerts/Providers/FireAlertSelectProvider.php`  
> - `app/Services/Alerts/Providers/PoliceAlertSelectProvider.php`  
> - `app/Services/Alerts/Providers/TransitAlertSelectProvider.php`  
> - `app/Services/Alerts/Providers/GoTransitAlertSelectProvider.php`

- [ ] Task: Add explicit `$driver === 'pgsql'` branches (do not fall through to MySQL SQL).
    - [ ] Sub-task: Ensure `id` and `external_id` are **text** across all providers on pgsql (cast numeric identifiers like `object_id`).
    - [ ] Sub-task: Replace MySQL-only string helpers (`CONCAT`, `CONCAT_WS`, `IF`, `IFNULL`) with pgsql-safe equivalents (`||`, `concat_ws`, `CASE`, `coalesce`).
    - [ ] Sub-task: Replace JSON constructors with pgsql equivalents (`json_build_object`, `json_agg`) and ensure unions do not type-mismatch the `meta` column.

---

## Phase 3: Provider Refactors (Search + Scene Intel Meta)
- [ ] Task: PostgreSQL `q` filtering must be implemented at the provider layer.
    - [ ] Sub-task: Use Postgres FTS (`to_tsvector(...) @@ plainto_tsquery(...)`) with an expression that matches the migration’s index expression.
    - [ ] Sub-task: Add a substring fallback **in addition** to FTS (ticket requires this) using `ILIKE`/`LOWER(...) LIKE` across the same columns used for FTS.
    - [ ] Sub-task: Ensure nullable columns do not break search (wrap with `coalesce(col, '')` where needed).
- [ ] Task: Fire provider Scene Intel embedding must be pgsql-safe and frontend-valid.
    - [ ] Sub-task: Implement a pgsql version of `getSummarySubquery()` using `json_build_object` + `json_agg`.
    - [ ] Sub-task: Ensure deterministic ordering of the summary items (most recent first) via `ORDER BY` inside the aggregate (or equivalent safe ordering approach).
    - [ ] Sub-task: Ensure `intel_summary` is always a JSON array (`COALESCE(..., '[]'::json)`), never `null`.
    - [ ] Sub-task: Format both per-item `timestamp` **and** `intel_last_updated` to ISO-8601 with offset (`...Z` is acceptable) so the frontend Fire schema does not discard alerts.

---

## Phase 4: End-to-End Verification (SQLite + MySQL + PostgreSQL)
- [ ] Task: Keep existing test baselines green.
    - [ ] Sub-task: Run test suite on SQLite (default `phpunit.xml`).
    - [ ] Sub-task: Run test suite on MySQL (`phpunit.mysql.xml`) to ensure no regressions.
- [ ] Task: Add a repeatable Postgres verification path (currently missing).
    - [ ] Sub-task: Add a Postgres service to `compose.yaml` (or document an external Postgres dev DB) so contributors can actually run pgsql checks locally.
    - [ ] Sub-task: Add a `phpunit.pgsql.xml` (or equivalent) config and environment variables for a pgsql test database.
    - [ ] Sub-task: Add/extend a small number of tests to cover pgsql-only invariants:
        - `meta.intel_summary` array (never null)
        - `meta.intel_last_updated` ISO-8601 offset formatting when incident updates exist
        - `q` filtering reduces result set on pgsql (at least one provider)
- [ ] Task: Smoke test on a pgsql environment.
    - [ ] Sub-task: Load `/` and `/api/feed` with and without `q`, ensure no `QueryException`.
    - [ ] Sub-task: Confirm filters (`status`, `source`, `since`, `cursor`) still behave deterministically.

---

## Phase 5: Documentation + Registry Hygiene
- [ ] Task: Update docs that currently state “MySQL production”.
    - [ ] Sub-task: Update `README.md` (root), `conductor/tech-stack.md`, `docs/backend/unified-alerts-system.md`, and `docs/plans/hetzner-forge-deployment-preflight.md` to reflect PostgreSQL production support (and the cross-driver search behavior).
    - [ ] Sub-task: Document how to run pgsql verification locally (docker/service + test config).
- [ ] Task: Close track in registry.
    - [ ] Sub-task: Archive this track folder and update `conductor/tracks.md` when complete.
