# Phase 4 Preflight: Cross-Driver (MySQL) Verification Audit

Date: 2026-02-04

This document captures audit notes and prep decisions for **Phase 4: Cross-Driver Verification** in `conductor/tracks/query_refinement_20260203/plan.md`.

## Goal (Phase 4)

Validate that the provider SQL branches and the unified UNION query behave correctly on **MySQL**, not just via SQL-string assertions and mocked driver checks.

## Current State (as of 2026-02-04)

### 1) Provider SQL branches exist, but MySQL execution is not currently exercised

`FireAlertSelectProvider` and `PoliceAlertSelectProvider` build different SQL based on `DB::getDriverName()`:

- sqlite branches: string concat via `||`, `json_object(...)`, and sqlite casts (`CAST(... AS TEXT)`).
- non-sqlite branches: `CONCAT(...)`, `CONCAT_WS(...)`, `JSON_OBJECT(...)`, and MySQL-friendly casts (`CAST(... AS CHAR)`).

Unit tests currently confirm “non-sqlite expressions” by mocking `DB::getDriverName()` and inspecting SQL strings, but do **not** run against a MySQL connection.

### 2) The test runner is pinned to sqlite

`phpunit.xml` sets:

- `DB_CONNECTION=sqlite`
- `DB_DATABASE=:memory:`

This means that even if tests are executed in a MySQL-capable environment (e.g. Sail), PHPUnit will still force sqlite unless an alternate configuration is used.

### 3) Sail is installed, but a compose file is not present in this repo

`vendor/bin/sail` exists, but this repository does not currently include a `docker-compose.yml`. Phase 4 setup will need to either:

- add a compose file (via Sail install) and document the workflow, or
- provide a non-Sail MySQL test harness (less preferred per plan).

## Phase 4 Prep: Recommended Approach

### A) Add a MySQL PHPUnit configuration (recommended)

Create a dedicated PHPUnit config (example name: `phpunit.mysql.xml`) that:

- sets `DB_CONNECTION=mysql`
- configures `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`
- keeps the rest of testing defaults identical to `phpunit.xml`

This keeps sqlite as the default test mode while enabling a deterministic MySQL mode.

### B) Ensure a MySQL runtime exists (Sail/Docker)

If using Sail:

- Install Sail in the repo (if needed) to generate `docker-compose.yml` with a MySQL service.
- Ensure `.env` (or a dedicated `.env.mysql-testing`) points to the MySQL container.

### C) Cross-driver execution target

At minimum for Phase 4, run these against MySQL:

- `tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php`
- `tests/Unit/Services/Alerts/Providers/*AlertSelectProviderTest.php` (or an integration variant)

## Driver-Specific Risk Checklist (MySQL)

1. **JSON meta projection**
   - Uses `JSON_OBJECT(...)` on MySQL.
   - Ensure the selected value returns as a JSON string that `UnifiedAlertMapper::decodeMeta()` can decode.
2. **String concatenation / location formatting**
   - Fire uses `NULLIF(CONCAT_WS(' / ', ...), '')` on MySQL.
   - Verify edge cases: both parts null => null; one part => that part; both => “A / B”.
3. **UNION type consistency**
   - Ensure `external_id` is explicitly a string for all providers (already fixed for Fire/Police).
   - Ensure `lat/lng` union works with Fire selecting `NULL` and Police selecting `DECIMAL(10,7)`.
4. **Deterministic ordering across collations**
   - Ordering tuple: `(timestamp desc, source asc, external_id desc)`.
   - MySQL collation could change sort behavior; if issues appear, consider collation-stable ordering (e.g. `COLLATE utf8mb4_bin`) as a Phase 4 fix.
5. **Pagination COUNT wrapping**
   - Laravel’s paginator issues a `COUNT(*)` query over the subquery.
   - Verify MySQL accepts the generated subquery/aliasing for UNIONs.

## Suggested Phase 4 Commands (once implemented)

Local (Sail):

- `./vendor/bin/sail up -d`
- `CI=true ./vendor/bin/sail php vendor/bin/pest -c phpunit.mysql.xml --filter UnifiedAlertsQueryTest`

CI/Docker:

- run `pest` with `-c phpunit.mysql.xml` and a MySQL service container

