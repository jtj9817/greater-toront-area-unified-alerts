# Implementation Plan: PostgreSQL Compatibility Refactor

## Phase 1: Database Migration Updates
- [ ] Task: Update Full-Text Search Migration (`database/migrations/2026_02_19_120000_add_fulltext_indexes_to_alert_tables.php`).
    - [ ] Sub-task: Modify the `up()` and `down()` methods to allow `pgsql` driver. Laravel's `$table->fullText()` natively supports PostgreSQL by creating a `to_tsvector` GIN index. Change the driver check `Schema::getConnection()->getDriverName() !== 'mysql'` to `!in_array(Schema::getConnection()->getDriverName(), ['mysql', 'pgsql'])`.

## Phase 2: Select Providers Refactoring (Fire & Police)
- [ ] Task: Refactor `app/Services/Alerts/Providers/FireAlertSelectProvider.php` for PostgreSQL compatibility.
    - [ ] Sub-task: Add a `pgsql` branch for `idExpression`, `externalIdExpression`, `locationExpression`, and `metaExpression`.
    - [ ] Sub-task: In `getSummarySubquery`, use `json_build_object`, `json_agg`, and `to_char(t.created_at, 'YYYY-MM-DD"T"HH24:MI:SS"Z"')` for the `pgsql` driver.
    - [ ] Sub-task: In the search filter, if driver is `pgsql`, use `to_tsvector('english', ...) @@ plainto_tsquery('english', ?)` for full-text search.
- [ ] Task: Refactor `app/Services/Alerts/Providers/PoliceAlertSelectProvider.php` for PostgreSQL compatibility.
    - [ ] Sub-task: Implement the same `pgsql` branches for JSON functions, date formatting, string concatenation (`||`), and full-text search.

## Phase 3: Select Providers Refactoring (Transit & GO)
- [ ] Task: Refactor `app/Services/Alerts/Providers/TransitAlertSelectProvider.php` for PostgreSQL compatibility.
    - [ ] Sub-task: Implement the `pgsql` branches for JSON functions, date formatting, string concatenation (`||`), and full-text search.
- [ ] Task: Refactor `app/Services/Alerts/Providers/GoTransitAlertSelectProvider.php` for PostgreSQL compatibility.
    - [ ] Sub-task: Implement the `pgsql` branches for JSON functions, date formatting, string concatenation (`||`), and full-text search.

## Phase 4: Quality & Verification
- [ ] Task: Verify test coverage and cross-database compatibility.
    - [ ] Sub-task: Run all tests using SQLite (`./vendor/bin/sail artisan test`).
    - [ ] Sub-task: Run all tests using MySQL to ensure no regressions.
    - [ ] Sub-task: Run all tests using PostgreSQL to verify the new implementations.
- [ ] Task: Update Technical Documentation.
    - [ ] Sub-task: Update `tech-stack.md` and related documentation to reflect PostgreSQL production support.
- [ ] Task: Close track in registry.
    - [ ] Sub-task: Archive track and update `conductor/tracks.md`.
