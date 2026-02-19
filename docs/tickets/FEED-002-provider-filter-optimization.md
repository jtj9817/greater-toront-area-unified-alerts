# FEED-002: Performance Optimization - Push Down Filters to Alert Providers

## Description
The current implementation of `AlertSelectProvider` (Fire, Police, Transit, Go) ignores the `status`, `source`, and `since` criteria in the `select()` method (except for `q` in MySQL). This results in fetching all records from the database tables, unioning them, and then filtering in the outer query. This is inefficient and will scale poorly.

Additionally, `UnifiedAlertsQuery` instantiates and unions queries from all providers, even when a specific `source` is requested.

## Requirements

### 1. Push Down Filters to Providers
Modify each `AlertSelectProvider` implementation (`FireAlertSelectProvider`, `PoliceAlertSelectProvider`, `TransitAlertSelectProvider`, `GoTransitAlertSelectProvider`) to apply the following filters directly in their query builder:

- **Status:** If `$criteria->status` is provided (`active` or `cleared`), apply the corresponding `where` clause on the `is_active` column.
- **Since:** If `$criteria->sinceCutoff` is provided, apply a `where` clause on the provider's specific timestamp column (e.g., `dispatch_time`, `occurrence_time`, `active_period_start`).
- **Source:** If `$criteria->source` is provided and does **not** match the provider's source type, the provider should return a query that produces no results (e.g., `whereRaw('1 = 0')`), or `UnifiedAlertsQuery` should be updated to skip calling it (see below).

### 2. Optimize Unified Query Source Selection
Update `App\Services\Alerts\UnifiedAlertsQuery::unionSelect` to only include providers that match the requested `$criteria->source` (if set). This prevents unnecessary overhead of constructing and executing queries for irrelevant sources.

## Technical Implementation Notes

- **Timestamp Columns:**
    - Fire: `dispatch_time`
    - Police: `occurrence_time`
    - Transit: `COALESCE(active_period_start, created_at)` (Note: filtering on COALESCE might miss index usage; verify performance or prefer `active_period_start` if reasonable).
    - GoTransit: `timestamp`

- **Safety:**
    - The outer filters in `UnifiedAlertsQuery::baseQuery` can remain as a defensive measure, or be removed if we are confident all providers handle the criteria correctly.

## Acceptance Criteria
- [x] `FireAlertSelectProvider` applies `is_active` and timestamp filters in the generated SQL.
- [x] `PoliceAlertSelectProvider` applies `is_active` and timestamp filters in the generated SQL.
- [x] `TransitAlertSelectProvider` applies `is_active` and timestamp filters in the generated SQL.
- [x] `GoTransitAlertSelectProvider` applies `is_active` and timestamp filters in the generated SQL.
- [x] Requests with `source=fire` do not execute subqueries against police or transit tables (verified via query log assertions in tests).
- [x] existing tests pass.

## Implementation Notes (Done)
- Added `source(): string` to `AlertSelectProvider` so `UnifiedAlertsQuery::unionSelect()` can skip irrelevant providers when `source` is specified.
- Pushed down `status`, `sinceCutoff`, and defensive `source` mismatch handling into each provider query.
    - Transit `sinceCutoff` uses a split predicate (`active_period_start` when present, else `created_at`) to avoid filtering on a `COALESCE(...)` expression.
- Kept outer filters in `UnifiedAlertsQuery::baseQuery()` as a defensive layer.

## Verification
- `php artisan test` (sqlite, in-memory) including unified feed + provider suites.
