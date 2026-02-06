# Unified Alerts Design Choices (Q&A)

## What problem does this solve?

A single timeline-style feed across `fire`, `police`, `transit`, and `go_transit`, with stable server-side pagination and optional status filtering.

## Why UNION at read-time?

- Avoids maintaining a second projection table write-path.
- Keeps source ownership inside source tables/commands.
- Fits current scale while preserving deterministic ordering.

## Why include active and cleared by default?

Default `status=all` preserves feed continuity so alerts do not disappear after they clear.

## How is pagination kept stable?

Deterministic ordering tuple:
1. `timestamp` DESC
2. `source` ASC
3. `external_id` DESC

## How do we add a new source?

1. Add source model + ingestion command/job/table.
2. Add `AlertSource` enum case.
3. Implement `AlertSelectProvider` with unified columns.
4. Tag provider in `AppServiceProvider`.
5. Include source in controller `latestFeedUpdatedAt()` aggregation.
6. Extend frontend mapping in `AlertService` if source-specific UX is needed.

## Current residual risks

- Deep offset pagination can degrade with very large history.
- `meta` can become overloaded if too many fields stay unstructured.
