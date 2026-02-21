# [FEED-004] Sort Direction Toggle

**Date:** 2026-02-18
**Status:** Open
**Priority:** Low
**Components:** Backend, Frontend
**Depends On:** [FEED-001](./FEED-001-server-side-filters-infinite-scroll.md)

## Problem

The feed is hardcoded to newest-first ordering. Users investigating historical patterns or reviewing a sequence of events chronologically cannot reverse the sort order.

## Current State

- `UnifiedAlertsQuery` sorts by `timestamp DESC` with tie-breaker `id DESC`
- No query parameter or UI control exists to change sort direction
- Newest-first is the correct default for a live incident feed

## Proposed Solution

Add a `sort` query parameter (`desc` default, `asc` optional) that controls the `ORDER BY` direction in `UnifiedAlertsQuery`.

### Backend Changes

- Add `sort` field to `UnifiedAlertsCriteria` — validated as `asc` or `desc`, defaults to `desc`
- Apply direction in `UnifiedAlertsQuery::paginate()` `ORDER BY` clause
- Cursor-based pagination (from FEED-001) must account for direction when determining the next page cursor

### Frontend Changes

- Small toggle button near the existing filter controls (e.g., an arrow icon that flips between up/down)
- Reflects as `?sort=asc` or `?sort=desc` in the URL
- Default (newest-first) should not add the param to keep URLs clean

### Key Considerations

- **Cursor direction:** Cursor-based pagination logic needs to reverse its comparison operator (`>` vs `<`) depending on sort direction
- **Infinite scroll UX:** In ascending mode, new items would logically appear at the bottom; the "new alerts" indicator (from FEED-002) should adapt accordingly
- **Low priority:** Newest-first covers the vast majority of use cases; this is a convenience feature for power users

## Out of Scope

- Sort by fields other than timestamp (e.g., sort by source, severity)
- Multi-column sorting
