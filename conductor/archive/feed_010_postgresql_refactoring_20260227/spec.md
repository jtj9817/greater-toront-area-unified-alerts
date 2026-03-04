# Specification: FEED-010 Abstract Database-Specific SQL Functions for PostgreSQL Compatibility

## Overview
The unified alerts feed is implemented as a `UNION ALL` query over four provider SELECTs. The providers currently contain raw SQL that assumes MySQL for:
- JSON construction (`JSON_OBJECT`, `JSON_ARRAYAGG`, `JSON_ARRAY`)
- Date formatting (`DATE_FORMAT`)
- String helpers (`CONCAT`, `CONCAT_WS`, `IF`, `IFNULL`)
- Full-text search (`MATCH(...) AGAINST(...)`)

Production uses PostgreSQL (`pgsql`), so these MySQL-only expressions must be replaced with Postgres equivalents while preserving:
- SQLite behavior for tests/dev (no native FTS; compatibility fallback is acceptable),
- MySQL behavior for local/dev (FTS + substring fallback),
- Frontend transport invariants (typed Zod boundary must continue to accept backend payloads).

## In-Scope
- `app/Services/Alerts/Providers/*AlertSelectProvider.php` (Fire/Police/Transit/GO)
- Full-text index migrations for Postgres (GIN-backed)
- Verification approach (tests + local reproducibility)
- Documentation updates required to remove “MySQL production” drift

## Out-of-Scope (for this track)
- Changing ingestion/ETL logic (commands/jobs/services)
- Changing the unified feed schema/columns
- Changing UI filtering semantics beyond “make them work” on pgsql

---

## System Contract (Provider → UNION → DTO → Resource → Frontend)

### Unified Row Contract
Every provider must return the same columns (names + compatible types) so `UNION ALL` works on all drivers:
- `id` (string)
- `source` (string)
- `external_id` (string)
- `is_active` (boolean-ish)
- `timestamp` (datetime/timestamp)
- `title` (string)
- `location_name` (string|null)
- `lat` (float|null)
- `lng` (float|null)
- `meta` (JSON object, encoded as string or native JSON depending on driver/PDO; both are accepted by `UnifiedAlertMapper::decodeMeta`)

### Frontend Typed Boundary Invariants (Fire meta)
Fire alerts embed Scene Intel summary in `meta`. The frontend expects:
- `meta.intel_summary` is an array (never `null`). If there are no updates it must be `[]` or the key must be omitted.
- `meta.intel_last_updated` is either `null`/missing, or an ISO-8601 datetime with offset (`Z` is acceptable).

This must hold on **all drivers**, including pgsql.

---

## Driver Behavior Matrix (Required)

### SQLite
- No provider-level fulltext search.
- Keep existing outer-query fallback in `UnifiedAlertsQuery` for `q`:
  - `LOWER(title) LIKE ? OR LOWER(location_name) LIKE ?`

### MySQL (and optionally MariaDB)
- Keep existing provider-level `MATCH(...) AGAINST(... IN NATURAL LANGUAGE MODE)` + `LIKE` fallback predicates.
- Existing fulltext migration remains valid for MySQL.

### PostgreSQL
- Providers must implement `q` filtering using:
  1) Postgres FTS (`to_tsvector(...) @@ plainto_tsquery(...)`), AND
  2) A substring fallback in addition to FTS (ticket requirement) using `ILIKE`/`LOWER(...) LIKE`.
- Fulltext indexes must exist in pgsql so FTS is performant.
- Provider SQL must not use MySQL-only functions.

---

## Full-Text Indexing (PostgreSQL)

### Key Finding to Address
Laravel’s Postgres `fullText()` compilation (as of Laravel 12) builds a GIN index on an expression like:
`to_tsvector('english', col1) || to_tsvector('english', col2) || ...`

Many of our indexed columns are nullable (e.g., `prime_street`, `cross_streets`, etc.). If any operand is `NULL`, the expression can become `NULL` and the row may not be indexed as expected, and queries that “coalesce” values may not match the index expression.

### Required Design Decision (This Spec Chooses One)
For pgsql, create explicit indexes using a single text normalization expression that is:
- robust to nullable columns, and
- easy to match exactly in the provider WHERE clause.

**Canonical pgsql index/search expression (per table):**
```
to_tsvector('simple', concat_ws(' ', <columns...>))
```

This avoids NULL-nullification and provides a stable expression for both:
- `CREATE INDEX CONCURRENTLY ... USING gin ((<expression>))`
- `WHERE (<expression>) @@ plainto_tsquery('simple', ?)`

**Required column sets (must match existing MySQL FULLTEXT intent):**
- `fire_incidents_fulltext`:
  - `to_tsvector('simple', concat_ws(' ', event_type, prime_street, cross_streets))`
- `police_calls_fulltext`:
  - `to_tsvector('simple', concat_ws(' ', call_type, cross_streets))`
- `transit_alerts_fulltext`:
  - `to_tsvector('simple', concat_ws(' ', title, description, stop_start, stop_end, route, route_type))`
- `go_transit_alerts_fulltext`:
  - `to_tsvector('simple', concat_ws(' ', message_subject, message_body, corridor_or_route, corridor_code, service_mode))`

### Migration Strategy (Backfill-Safe)
Editing the existing MySQL-only migration is not sufficient if it has already been applied (or logged) on a pgsql environment.

**Therefore:**
- Add a new migration that runs only when the driver is `pgsql`.
- Because building a GIN index on a live production table can block writes, the migration MUST use `CREATE INDEX CONCURRENTLY IF NOT EXISTS` with the existing index names:
  - `fire_incidents_fulltext`
  - `police_calls_fulltext`
  - `transit_alerts_fulltext`
  - `go_transit_alerts_fulltext`
- Ensure the migration class sets `public $withinTransaction = false;` so `CONCURRENTLY` can run.
- Use `DROP INDEX CONCURRENTLY IF EXISTS` in `down()`.

---

## Provider SQL Requirements (PostgreSQL)

### Common SQL substitutions
- **String concatenation**
  - MySQL: `CONCAT(a, b)` / `CONCAT_WS(' / ', ...)`
  - PostgreSQL: `a || b` and/or `concat_ws(' / ', ...)`
  - Important: cast numeric IDs to text before concatenating.
- **Untyped NULLs in UNION**
  - PostgreSQL: Ensure `NULL` columns (like `lat`, `lng`) are explicitly cast to numeric types (e.g., `CAST(NULL AS double precision)` or `NULL::float`) to prevent type coercion ambiguity in `UNION ALL`.
- **JSON and UNION Types**
  - MySQL: `JSON_OBJECT`, `JSON_ARRAYAGG`, `JSON_ARRAY()`
  - PostgreSQL: `json_build_object`, `json_agg`, `COALESCE(json_agg(...), '[]'::json)`
  - Important: The resulting `meta` column MUST have exactly the same type across all `UNION ALL` arms. Explicitly cast the final JSON expression to `::jsonb` (or `::text`) across all providers.
- **Date/timestamp formatting for embedded JSON fields**
  - MySQL: `DATE_FORMAT(ts, '%Y-%m-%dT%TZ')` (literal `Z`)
  - PostgreSQL: `to_char(ts, 'YYYY-MM-DD"T"HH24:MI:SS"Z"')` (literal `Z`)
  - SQLite: `strftime('%Y-%m-%dT%H:%M:%SZ', ts)`

### FireAlertSelectProvider (Scene Intel embedding)
Postgres implementation MUST ensure:
- `intel_summary` is always a JSON array (`[]` when none)
- `intel_summary` items are ordered most-recent-first deterministically
- `intel_last_updated` uses ISO-8601 with offset and matches frontend schema expectations. The aggregated `MAX(created_at)` subquery for `intel_last_updated` MUST also be explicitly formatted to ISO-8601 with offset in the pgsql branch, not just the raw timestamp.

### Search (`q`) semantics (pgsql)
Each provider must implement pgsql search with:
- FTS predicate using the **same expression** as the pgsql index migration, and
- A substring fallback that preserves expected UX for partial matches:
  - Example fallback pattern: `coalesce(col, '') ILIKE ?`

The combined WHERE should be structured to avoid runtime errors on nulls and to remain parameterized (no string interpolation of user input).

---

## Maintainability Requirements
- Driver branching must be explicit and fail-closed: pgsql must not fall through to MySQL SQL.
- Repetition is acceptable if it preserves performance; a helper/macro is allowed but not required.
- Provider output types must remain UNION-compatible on all supported drivers (avoid mixing `json` vs `text` vs `null` types in ways that cause pgsql union failures).

---

## Verification Requirements

### Automated
- SQLite test suite remains the default baseline (`phpunit.xml`).
- MySQL test suite continues to run (`phpunit.mysql.xml`).

### PostgreSQL (currently missing infrastructure)
To satisfy “works on pgsql” in a repeatable way, this track must provide one of:
1) A Postgres service in local docker (`compose.yaml`) + a `phpunit.pgsql.xml`, or  
2) A documented external Postgres dev DB + a `phpunit.pgsql.xml`.

Minimum pgsql assertions to verify:
- Feed endpoints load without `QueryException`.
- `q` filtering reduces the result set (provider-level filtering is active).
- Fire meta invariants hold when incident updates exist (`intel_summary` array, `intel_last_updated` ISO offset).
- Index existence + query plan plausibly uses the GIN index (`EXPLAIN` on a representative query with `q`).

---

## Acceptance Criteria
- [ ] All four providers explicitly handle `$driver === 'pgsql'` without MySQL-only SQL usage.
- [ ] `/` and `/api/feed` load on pgsql without `QueryException` (with and without `q`).
- [ ] `q` filtering works on pgsql using FTS + substring fallback (`ILIKE`/`LIKE`) and is parameterized.
- [ ] Postgres fulltext indexes exist with the agreed names and index expressions.
- [ ] Fire meta invariants hold cross-driver:
  - [ ] `meta.intel_summary` is never `null` (array or omitted)
  - [ ] `meta.intel_last_updated` is ISO-8601 with offset when present
- [ ] SQLite and MySQL behavior remains functional and existing tests pass.
- [ ] Documentation no longer claims “MySQL production” where pgsql production is the target.
