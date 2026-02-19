# Specification: [FEED-001] Server-Side Filters + Infinite Scroll (Cursor Pagination)

This spec is aligned to `docs/tickets/FEED-001-server-side-filters-infinite-scroll.md` (primary source of truth).

## 1. Overview

The alert feed currently paginates server-side (50 items/page), but applies category/source, search, and time/date-style filters client-side. This produces misleading results because the filters only apply to the currently loaded page rather than the full dataset.

This track moves all filtering into the backend unified feed query and replaces numbered pagination with cursor-based infinite scroll.

## 2. Goals

- **Server-authoritative filtering:** backend returns already-filtered results for the full dataset (not page-scoped filtering).
- **Cursor-based infinite scroll:** replace numbered pages with “load next batch” driven by a cursor keyed on a deterministic tuple.
- **URL as state:** active filters are reflected in query params for shareable/bookmarkable views.
- **Keep view-mode client-side:** Cards/Table is presentational only and remains local UI state.
- **Remove client-side feed filtering:** drop `AlertService.searchDomainAlerts()` usage for the live feed; frontend should render the server-provided list.

## 3. API / Query Parameters

The GTA Alerts endpoint (Inertia page) accepts the following query parameters:

| Filter | Param | Example | Notes |
|---|---|---|---|
| Status | `status` | `?status=active` | Existing behavior; `all` / `active` / `cleared`. |
| Source | `source` | `?source=fire` | Filters by unified alert `source` (e.g. `fire`, `police`, `transit`, `go_transit`). |
| Search | `q` | `?q=assault` | Case-insensitive text search across canonical fields (see §4.3). |
| Time range | `since` | `?since=30m` / `?since=3h` | Relative time window; see §4.4. |
| Cursor | `cursor` | `?cursor=…` | Used only for infinite scroll; cursor keyed to deterministic ordering. |

Notes:
- `perPage` stays at ~50 items per batch (consistent with current UX); no numbered `page` parameter in the infinite scroll flow.
- The infinite scroll “next batch” request must preserve all active filter params and only append results.

## 4. Backend Requirements

### 4.1 Affected Files (Backend)

- `app/Http/Controllers/GtaAlertsController.php` — validate and pass new filter params
- `app/Services/Alerts/DTOs/UnifiedAlertsCriteria.php` — expand criteria to include `source`, `q`, `since`, and cursor inputs
- `app/Services/Alerts/UnifiedAlertsQuery.php` — apply filtering in the unified UNION query and switch to cursor pagination

### 4.2 Deterministic Ordering Tuple

Cursor pagination requires a deterministic ordering so cursors are stable and do not skip/duplicate items.

Required order:
1. `timestamp` DESC
2. `id` DESC (where `id` is the unified alert identifier, e.g. `fire:FIRE-0001`)

This matches the ticket’s intent (“keyed on `(timestamp, id)`”) and avoids stale-page issues when new alerts arrive while the user is scrolling.

### 4.3 Search (`q`) + FULLTEXT Requirement

Backend search should match the “feed mental model” fields:
- `title`
- `location_name`
- any provider-specific text already included in the unified result row (where feasible without breaking sqlite/mysql compatibility)

Requirements:
- **MySQL:** search must use FULLTEXT indexes (and FULLTEXT queries) for acceptable performance at scale.
- **SQLite (dev/tests):** provide a compatible fallback (e.g., `LIKE`-based search) that is correctness-first, even if slower.

### 4.4 Relative Time Window (`since`)

`since` is a relative duration string (examples: `30m`, `1h`, `3h`, `6h`, `12h`).

Requirements:
- The backend computes a cutoff time (`now - duration`) and filters `timestamp >= cutoff`.
- Invalid `since` values should be rejected at validation time (or normalized to “no filter”).

### 4.5 Cross-Driver Compatibility

All added WHERE clauses and ordering must work on both sqlite and mysql (consistent with existing unified-select providers).

## 5. Frontend Requirements

### 5.1 Affected Files (Frontend)

- `resources/js/features/gta-alerts/components/FeedView.tsx` — remove client-side filtering and implement URL-driven filters + infinite scroll
- `resources/js/features/gta-alerts/services/AlertService.ts` — stop using `searchDomainAlerts()` for live feed filtering
- `resources/js/features/gta-alerts/App.tsx` — pass through server-provided feed list; remove local “search filters current page” behavior
- `resources/js/pages/gta-alerts.tsx` — Inertia page contract (props remain the source of truth)

### 5.2 URL State + Reset

- Changing filters updates query params (shareable link behavior).
- “Reset” clears query params and reloads the default feed view.

### 5.3 Search Debounce + Loading UX

- Search input is debounced (~300ms) before issuing a request.
- Infinite scroll shows:
  - bottom loading indicator while fetching next batch
  - empty state when no results
  - “no more results” handling when there is no next cursor

## 6. Acceptance Criteria (Aligned to Ticket)

- [ ] Search and source filters query the backend across the full dataset (not just the currently loaded batch).
- [ ] `since` correctly constrains results (e.g., `since=30m` returns only alerts in the last 30 minutes).
- [ ] Infinite scroll appends additional results using a cursor, without skipping/duplicating items as new alerts arrive.
- [ ] All active filters are reflected in the URL.
- [ ] Cards/Table view toggle remains client-side (presentational only).

## 7. Out of Scope (Per Ticket)

- Real-time push updates for filtered views (see `docs/tickets/FEED-002-real-time-push.md`).
- Advanced geospatial filtering (separate track).
