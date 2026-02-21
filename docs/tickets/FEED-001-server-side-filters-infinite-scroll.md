# [FEED-001] Server-Side Filters + Infinite Scroll for Alert Feed

**Date:** 2026-02-18
**Status:** Closed (Implemented)
**Priority:** High
**Components:** Backend, Frontend, UX

## Problem (Historical)

The alert feed currently uses a hybrid filtering architecture that produces misleading results. Server-side pagination delivers 50 items per page, but category, search, date, and time filters are applied **client-side only** — meaning they filter the current page's 50 items, not the full dataset (6,400+ alerts).

### Observed Issues

1. **Search is page-scoped:** Searching for "ASSAULT" only finds matches within the current 50 items, not across all alerts.
2. **Category filter is page-scoped:** Filtering to "Fire" shows only fire alerts that happen to be on the current page, not all fire alerts system-wide.
3. **Time/date filters are page-scoped:** "Last 30m" filters the current page rather than querying the backend for recent alerts.
4. **Pagination style mismatch:** Numbered page navigation doesn't match the mental model of a live incident feed/timeline. Users scan for relevant incidents — they don't navigate to "page 47."

### Current Architecture (Historical, Pre-FEED-001)

```
Backend (server-side)                    Frontend (client-side)
─────────────────────                    ───────────────────────
- Status filter (all/active/cleared)     - Category (fire/police/transit/hazard)
- Pagination (page number, 50/page)      - Search (title, description, location)
- Sort by timestamp DESC                 - Date scope (today/yesterday/all)
                                         - Time limit (30m, 1h, 3h, 6h, 12h)
```

### Affected Files (Historical Scope)

**Backend:**
- `app/Http/Controllers/GtaAlertsController.php` — currently only validates `status` param
- `app/Services/Alerts/DTOs/UnifiedAlertsCriteria.php` — criteria DTO, only has status/perPage/page
- `app/Services/Alerts/UnifiedAlertsQuery.php` — UNION ALL query builder

**Frontend:**
- `resources/js/features/gta-alerts/components/FeedView.tsx` — filter UI + client-side filter state
- `resources/js/features/gta-alerts/services/AlertService.ts` — `searchDomainAlerts()` client-side filtering
- `resources/js/features/gta-alerts/App.tsx` — passes pagination props, manages search query state
- `resources/js/pages/gta-alerts.tsx` — Inertia page component

## Proposed Solution

### 1. Move All Filters Server-Side

Add query parameters to the backend endpoint so the UNION ALL query handles filtering at the database level:

| Filter | Parameter | Example |
|--------|-----------|---------|
| Status | `status` | `?status=active` (already exists) |
| Category/Source | `source` | `?source=fire` or `?source=transit` |
| Search | `q` | `?q=assault` |
| Time range | `since` | `?since=30m` or `?since=3h` |

This means `UnifiedAlertsCriteria` gains new fields, `UnifiedAlertsQuery` applies them in the SQL, and the frontend filter controls become links/params instead of local state.

### 2. Replace Page-Based Pagination with Cursor-Based Infinite Scroll

- Use **cursor-based pagination** keyed on `(timestamp, id)` to avoid stale-page issues when new alerts arrive mid-browsing.
- Frontend loads ~50 items initially, then fetches the next batch on scroll (intersection observer or scroll threshold).
- Drop `AlertService.searchDomainAlerts()` client-side filtering entirely — all filtering is server-authoritative.

### 3. Retain Client-Side View Mode Toggle

The Cards/Table toggle is purely presentational and should remain client-side.

## Design Considerations

- **Search debounce:** Frontend should debounce search input (~300ms) before issuing server requests to avoid excessive queries.
- **URL state:** All active filters should be reflected in the URL (query params) so filtered views are shareable/bookmarkable.
- **Filter reset:** The "Reset" button should clear all query params and reload.
- **Loading states:** Infinite scroll needs a loading indicator at the bottom and graceful handling of "no more results."
- **SQLite compatibility:** Any new WHERE clauses need dual-driver support (sqlite vs mysql) per project convention.
- **Existing tests:** `UnifiedAlertsQuery` has test coverage that will need updating for new filter parameters.

## Related Tickets

| Ticket | Description | Priority |
|--------|-------------|----------|
| [FEED-002](./FEED-002-real-time-push.md) | Real-time push updates for the alert feed via WebSocket/SSE | Medium |
| [FEED-003](./FEED-003-saved-filter-presets.md) | Saved filter presets for quick-access filter combinations | Low |
| [FEED-004](./FEED-004-sort-direction-toggle.md) | Sort direction toggle (newest/oldest first) | Low |

All three depend on this ticket being completed first.

## Resolution (Implemented 2026-02-21)

FEED-001 is fully shipped.

- Live feed filtering is server-authoritative (`status`, `source`, `q`, `since`) on both `/` and `/api/feed`.
- Infinite scroll uses cursor pagination keyed by `(timestamp DESC, id DESC)` and returns `{ data, next_cursor }`.
- Client-side live-feed filtering was removed; the frontend renders server-provided batches and appends via `/api/feed`.
- URL query params are the source of truth for active filters.
- Reset behavior is split: header `Reset` clears `source` + `since`, while empty-state `Reset All Filters` clears all params.

Primary implementation references:

- `app/Http/Controllers/GtaAlertsController.php`
- `app/Http/Controllers/Api/FeedController.php`
- `app/Services/Alerts/DTOs/UnifiedAlertsCriteria.php`
- `app/Services/Alerts/UnifiedAlertsQuery.php`
- `resources/js/features/gta-alerts/hooks/useInfiniteScroll.ts`
- `resources/js/features/gta-alerts/components/FeedView.tsx`
