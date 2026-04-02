# Unified Alerts Design Choices (Q&A)

## What problem does this solve?

A single timeline-style feed across `fire`, `police`, `transit`, `go_transit`, `miway`, and `yrt`, with server-authoritative filtering and stable cursor pagination.

## Why UNION at read-time?

- Avoids maintaining a second projection table write-path.
- Keeps source ownership inside source tables/commands.
- Fits current scale while preserving deterministic ordering.

## Why include active and cleared by default?

Default `status=all` preserves feed continuity so alerts do not disappear after they clear.

## How is pagination kept stable?

Deterministic ordering tuple:
1. `timestamp` DESC
2. `id` DESC

## How do we add a new source?

1. Add source model + ingestion command/job/table.
2. Add `AlertSource` enum case.
3. Implement `AlertSelectProvider` with unified columns.
4. Tag provider in `AppServiceProvider`.
5. Include source in controller `latestFeedUpdatedAt()` aggregation.
6. Extend frontend mapping in `AlertService` if source-specific UX is needed.

## Current residual risks

- **`q` search performance:** GIN indexes on four of six alert tables (added by FEED-010 PostgreSQL refactoring, migration `2026_02_26_000001_add_pgsql_fulltext_indexes_to_alert_tables.php`) substantially mitigate the cost of FTS queries on PostgreSQL. `miway_alerts` and `yrt_alerts` fall back to `ILIKE` on PostgreSQL. Very short or highly common terms (e.g., single-character queries) still lack index selectivity and may fall back to broader scans. The `ILIKE` substring fallback applied alongside FTS adds additional per-row work for those cases.
- **`meta` overload:** If too many source-specific fields accumulate in unstructured `meta` JSON, querying and mapping become harder to maintain. New sources should use typed schema additions rather than ad-hoc keys where possible.

> **Historical note:** Before FEED-010 (archived 2026-02-27), the system targeted MySQL as the production database driver. The residual risk around FULLTEXT performance was more significant at that time because MySQL FULLTEXT has weaker planner integration than PostgreSQL GIN. The current production target is PostgreSQL.
