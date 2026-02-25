# Specification: Abstract Database-Specific SQL Functions for PostgreSQL Compatibility

## Overview
This track adapts the existing raw SQL within `AlertSelectProvider` implementations to be compatible with PostgreSQL. The application currently utilizes raw MySQL functions (`DATE_FORMAT`, `JSON_OBJECT`, `JSON_ARRAYAGG`, `MATCH() AGAINST()`) to highly optimize the unified alerts feed. Since the production server is using PostgreSQL, we must abstract these driver-specific raw queries.

## Functional Requirements
- **Migration Updates**
    - The `2026_02_19_120000_add_fulltext_indexes_to_alert_tables` migration must execute for `pgsql` connections as well as `mysql`, generating GIN full-text indices.
- **Provider Refactoring**
    - `FireAlertSelectProvider`, `PoliceAlertSelectProvider`, `TransitAlertSelectProvider`, and `GoTransitAlertSelectProvider` must explicitly handle `$driver === 'pgsql'`.
    - PostgreSQL SQL Equivalents:
        - `DATE_FORMAT(..., '%Y-%m-%dT%TZ')` -> `to_char(..., 'YYYY-MM-DD"T"HH24:MI:SS"Z"')`
        - `JSON_OBJECT(...)` -> `json_build_object(...)`
        - `JSON_ARRAYAGG(...)` -> `coalesce(json_agg(...), '[]'::json)` (or `json_agg`)
        - `CONCAT(...)` -> `... || ...` (or native `CONCAT` where appropriate)
        - `MATCH(...) AGAINST(...)` -> `to_tsvector('english', ...) @@ plainto_tsquery('english', ...)` or equivalent native syntax depending on the index layout.
- **Backwards Compatibility**
    - Must not break `mysql` or `sqlite` driver implementations.
    - Tests must pass for all supported drivers to ensure local development and testing parity.

## Non-Functional Requirements
- **Performance:** Ensure the use of Postgres text search takes advantage of GIN indices natively provided by Laravel's `$table->fullText()`.
- **Maintainability:** Abstract away chaos by branching driver logic clearly or refactoring into a macro/helper if it reduces repetition without losing performance.

## Acceptance Criteria
- [ ] `DB::getDriverName() === 'pgsql'` is explicitly handled in all four `AlertSelectProvider` classes.
- [ ] Application loads without `QueryException` on Postgres environments.
- [ ] Full-text search leverages proper Postgres mechanisms.
- [ ] Local development on SQLite and MySQL remains fully functional.
- [ ] Existing PHPUnit/Pest tests pass across all supported database drivers.
