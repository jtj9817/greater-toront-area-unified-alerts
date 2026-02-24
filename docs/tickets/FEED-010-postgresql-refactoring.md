---
ticket_id: FEED-010
title: "[Refactor] Abstract Database-Specific SQL Functions for PostgreSQL Compatibility"
status: Open
priority: High
assignee: Unassigned
created_at: 2026-02-24
tags: [refactor, backend, database, postgresql, deployment]
related_files:
  - app/Services/Alerts/Providers/FireAlertSelectProvider.php
  - app/Services/Alerts/Providers/PoliceAlertSelectProvider.php
  - app/Services/Alerts/Providers/TransitAlertSelectProvider.php
  - app/Services/Alerts/Providers/GoTransitAlertSelectProvider.php
  - app/Services/Alerts/UnifiedAlertsQuery.php
  - database/migrations/2026_02_19_120000_add_fulltext_indexes_to_alert_tables.php
---

## Summary

During a recent deployment to the Hetzner VPS managed by Laravel Forge, an internal server error was encountered on the dashboard. The application threw a `QueryException` because the database connection on the production server is configured for PostgreSQL (`pgsql`), but the codebase relies on MySQL-specific SQL functions. Specifically, the error flagged `DATE_FORMAT()`, which does not exist in PostgreSQL.

While the original deployment plan (`docs/plans/hetzner-forge-deployment-preflight.md`) assumed a MySQL instance would be used on Forge, architectural constraints and the current server setup require us to adapt the application to run on PostgreSQL in production. Meanwhile, we need to maintain compatibility with the local development database (which uses SQLite for tests and potentially MySQL locally) to save and export existing local data before the full migration.

This ticket outlines the necessary refactoring to abstract database-specific SQL functions (`DATE_FORMAT`, `JSON_OBJECT`, `JSON_ARRAYAGG`, `MATCH() AGAINST()`, etc.) so that the application seamlessly supports PostgreSQL in production without breaking local development workflows.

## Problem Analysis

### The Current Error
```text
SQLSTATE[42883]: Undefined function: 7 ERROR:  function date_format(timestamp without time zone, unknown) does not exist
LINE 35:                     'timestamp', DATE_FORMAT(t.created_at, '...
```

The unified alerts query uses highly optimized, raw SQL expressions within the select providers to combine and format data from four different tables. The current `mysql` branches in these providers use:
- `DATE_FORMAT()` instead of Postgres's `TO_CHAR()`
- `JSON_OBJECT()` instead of Postgres's `json_build_object()`
- `JSON_ARRAYAGG()` instead of Postgres's `json_agg()`
- `MATCH() AGAINST()` for full-text search, whereas Postgres uses `to_tsvector()` and `@@ to_tsquery()`

Because the application checks `$driver = DB::getDriverName()` and currently only handles `'sqlite'` and `'mysql'` (falling back to MySQL syntax for anything else), it fundamentally breaks when the driver is `'pgsql'`.

### Context of the Current Session
We successfully deployed the application via Forge and set up the Queue Workers and Scheduler as outlined in the pre-flight checklist. However, the production environment was provisioned with PostgreSQL. We decided to refactor the codebase to support Postgres rather than forcing a database engine switch on the server, ensuring broader compatibility and robustness. We also need to make sure we can still operate locally to save the existing data first.

## Fix Specification

### 1. Refactor Alert Select Providers
We need to introduce a third branch in the driver checks within the following files:
- `FireAlertSelectProvider.php`
- `PoliceAlertSelectProvider.php`
- `TransitAlertSelectProvider.php`
- `GoTransitAlertSelectProvider.php`

For `$driver === 'pgsql'`, implement the PostgreSQL equivalents for the raw SQL strings.

**Example for JSON and Date formatting (`FireAlertSelectProvider.php`):**
```php
// MySQL
"JSON_OBJECT('alarm_level', alarm_level, ...)"
"DATE_FORMAT(t.created_at, '%Y-%m-%dT%TZ')"

// PostgreSQL
"json_build_object('alarm_level', alarm_level, ...)"
"to_char(t.created_at, 'YYYY-MM-DD"T"HH24:MI:SS"Z"')"
```

**Example for String Concatenation:**
Ensure string concatenation uses `||` or `CONCAT()` appropriately for Postgres. Postgres supports `||` natively, similar to SQLite.

### 2. Refactor Full-Text Search
In the providers, the `MATCH() AGAINST()` queries need a PostgreSQL equivalent.

**MySQL:**
```php
$where->whereRaw('MATCH(event_type, prime_street, cross_streets) AGAINST (? IN NATURAL LANGUAGE MODE)', [$criteria->query])
```

**PostgreSQL:**
```php
$where->whereRaw("to_tsvector('english', coalesce(event_type,'') || ' ' || coalesce(prime_street,'') || ' ' || coalesce(cross_streets,'')) @@ plainto_tsquery('english', ?)", [$criteria->query])
```
*Note: A cleaner approach might be to utilize Laravel's built-in full-text search capabilities if possible, or abstract this into a macro/helper.*

### 3. Database Migrations Update
Review `2026_02_19_120000_add_fulltext_indexes_to_alert_tables.php`. Laravel's `$table->fullText()` generally translates correctly to GIN indexes in Postgres, but this must be verified. If it fails or performs poorly, we may need to adjust the migration to define explicit raw GIN indexes for Postgres.

## Acceptance Criteria

- [ ] `DB::getDriverName() === 'pgsql'` is explicitly handled in all four `AlertSelectProvider` classes.
- [ ] `DATE_FORMAT` is replaced with `TO_CHAR` for Postgres connections.
- [ ] `JSON_OBJECT` is replaced with `json_build_object` for Postgres connections.
- [ ] `JSON_ARRAYAGG` is replaced with `json_agg` for Postgres connections.
- [ ] Full-text search gracefully falls back to Postgres `to_tsvector` / `to_tsquery` or `ILIKE` if GIN indexes are not fully utilized.
- [ ] The dashboard loads successfully without SQL errors on a PostgreSQL database.
- [ ] Local development on SQLite and MySQL remains fully functional.
- [ ] Existing PHPUnit/Pest tests pass across all supported database drivers.
