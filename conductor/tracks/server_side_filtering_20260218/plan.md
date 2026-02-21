# Implementation Plan - [FEED-001] Server-Side Filters + Infinite Scroll

This plan is aligned to `docs/tickets/FEED-001-server-side-filters-infinite-scroll.md` (primary source of truth).

## Phase 1: Backend Filters + Cursor Pagination

**Goal:** Make filtering server-authoritative and enable cursor pagination keyed on `(timestamp, id)` for stable infinite scroll.

- [x] Task: Contract - Confirm URL Params + UI Mapping
    - [x] Confirm canonical filter params: `status`, `source`, `q`, `since`, plus `cursor` for infinite scroll.
    - [ ] Clarify param contracts (backed by current backend validation + normalization):
        - [x] `status`: `all|active|cleared` (UI toggle maps 1:1).
        - [x] `source`: unified sources only (`fire|police|transit|go_transit`); do not include `hazard`.
            - [x] Note: `ttc_accessibility` is notification-only and not a feed `source` filter.
        - [x] `q`: trimmed string; whitespace-only behaves as unset; sqlite fallback targets `title` + `location_name`, MySQL FULLTEXT uses provider-specific fields.
        - [x] `since`: time window presets only (`30m|1h|3h|6h|12h`), interpreted relative to "now".
        - [x] `cursor`: opaque base64url `(timestamp,id)`; trim before decode; whitespace-only behaves as unset.
    - [x] Decide what to do with the existing “Today / Yesterday / All Dates” UI:
        - [x] Replace with `since` presets (no client-side date filtering).

- [x] Task: TDD - Define Criteria + Query Contract (tests first)
    - [x] Request validation + criteria normalization tests:
        - [x] `status` accepts `all|active|cleared`; rejects invalid enum value.
        - [x] `source` accepts known sources; rejects unknown sources.
        - [x] `q` trims whitespace; empty string behaves as “no search”.
        - [x] `since` accepts `30m|1h|3h|6h|12h` (or validated pattern); rejects invalid values.
        - [x] `cursor` rejects malformed/invalid cursor payload (tampered/invalid base64/invalid tuple).
    - [x] Query behavior tests (red → green):
        - [x] filters by `source` across full dataset (not page-scoped).
        - [x] filters by `since` cutoff using `Carbon::setTestNow()`.
        - [x] filters by `q` and matches expected fields (at least `title`, `location_name`).
        - [x] combines filters (`status + source + since + q`) and returns correct intersection.
        - [x] cursor pagination returns deterministic batches with no duplicates across cursors.

- [x] Task: Controller - Validate + Pass Filter Params
    - [x] Update `app/Http/Controllers/GtaAlertsController.php` validation rules to include:
        - [x] `status` (existing)
        - [x] `source` (enum-backed; should match unified alert sources)
        - [x] `q` (nullable string; trim; max length)
        - [x] `since` (nullable; validated duration string)
        - [x] `cursor` (nullable; opaque cursor string)
    - [x] Ensure the `filters` Inertia prop echoes the active values (`status`, `source`, `q`, `since`) so the UI rehydrates from URL state.

- [x] Task: Criteria DTO - Expand + Normalize
    - [x] Expand `app/Services/Alerts/DTOs/UnifiedAlertsCriteria.php` to include:
        - [x] `?string $source`
        - [x] `?string $query` (maps to `q`)
        - [x] `?string $since` (raw) and/or a normalized cutoff timestamp value
        - [x] cursor fields (e.g., `?CarbonImmutable $cursorTimestamp`, `?string $cursorId`) OR an encoded cursor string with decoder
    - [x] Add normalization helpers:
        - [x] normalize `source` (`null` for empty)
        - [x] normalize `q` (trim; `null` for empty)
        - [x] normalize `since` (validate + compute cutoff relative to now)
        - [x] normalize cursor tuple (validate timestamp + id format)

- [x] Task: Cursor - Define + Lock Down Format
    - [x] Define an opaque cursor encoding for `(timestamp, id)` (e.g., base64url of JSON `{ ts, id }`).
    - [x] Implement encode/decode helpers and cover with tests:
        - [x] round-trip encode/decode
        - [x] invalid payloads fail closed

- [x] Task: Query - Apply Filters + Cursor Seek Pagination
    - [x] Update `app/Services/Alerts/UnifiedAlertsQuery.php` to:
        - [x] apply `status` (existing behavior)
        - [x] apply `source` (`WHERE source = ?`)
        - [x] apply `since` cutoff (`WHERE timestamp >= cutoff`)
        - [x] apply `q` search (see FULLTEXT requirement below)
        - [x] order by deterministic tuple `(timestamp DESC, id DESC)`
        - [x] apply cursor “seek” condition for DESC order:
            - [x] `timestamp < cursor_ts` OR (`timestamp = cursor_ts` AND `id < cursor_id`)
        - [x] return a cursor-paginated result (batch + `next_cursor`) while preserving filter params
    - [x] FULLTEXT requirement (explicit):
        - [x] **MySQL:** add FULLTEXT indexes on the underlying source tables’ searched columns and use MATCH...AGAINST predicates (not `LIKE`) for `q`.
        - [x] **SQLite (dev/tests):** implement a compatibility fallback for `q` (e.g., `LIKE` across selected fields) so tests run under sqlite.
        - [x] Add/extend MySQL coverage to ensure the FULLTEXT path is exercised.

- [x] Task: Testing - Phase 1 Verification
    - [x] Ensure sqlite test suite passes for filter + cursor behavior.
    - [x] Ensure MySQL driver test suite exercises FULLTEXT-backed search.

- [x] Task: Conductor - User Manual Verification 'Phase 1: Backend Filters + Cursor Pagination' (Protocol in workflow.md; script: `tests/manual/verify_feed_001_phase_1_backend_filters_cursor_pagination.php`)

## Phase 2: Frontend URL Filters + UX (No Client Feed Filtering) [checkpoint: 16775bb]

**Goal:** Make the live feed render the server-provided list and drive filters via URL params (shareable/bookmarkable), with good loading UX.

- [x] Task: Update Alert Feed Component Structure [commit: 945dd56]
    - [x] Refactor `resources/js/features/gta-alerts/components/FeedView.tsx` (and/or `resources/js/features/gta-alerts/App.tsx`) to treat filters as server-driven props from Inertia (URL → controller → props → UI).
    - [x] Remove the "filter current page items" mental model:
        - [x] Stop using `AlertService.searchDomainAlerts()` for the live feed.
        - [x] Remove page-scoped filter state that changes the rendered feed list (`activeCategory`, date/time scoped filtering).
    - [x] Keep Cards/Table toggle client-side (presentational only).

- [x] Task: Frontend - URL Synchronization (Inertia) [commit: b299aba]
    - [x] Use Inertia navigation (`router.get` / `useForm().get`) to update query params on filter changes.
    - [x] Ensure browser back/forward restores the filter state from the URL.
    - [x] Ensure the "Reset" action clears query params and reloads the default feed.

- [x] Task: Implement Filter UI Components [commit: 945dd56]
    - [x] Add/Update Source selector (Dropdown or Tabs) to trigger server reload on change (`source=fire|police|transit|go_transit`).
        - [x] Note: `hazard` is not a unified alert source; do not model it as a server-side `source` filter.
    - [x] Add/Update Search Bar to trigger server reload (with ~300ms debounce) via `q`.
    - [x] Add/Update Time window selector to update `since` (e.g., `30m`, `1h`, `3h`, `6h`, `12h`).
    - [x] Quick filters:
        - [x] Implement as `since` presets only (do not reintroduce client-side "today/yesterday" filtering).
    - [x] Out of scope reminder:
        - [x] Do not add `start_date/end_date` or `start_time/end_time` pickers for FEED-001 unless the ticket scope expands beyond `since`.

- [x] Task: Implement "Active/Resolved" Toggle (Status) [commit: 945dd56]
    - [x] Ensure the existing Status UI cleanly maps to backend `status` (`all|active|cleared`).
    - [x] Preserve other active query params when toggling status.

- [x] Task: Pagination Integration (URL + State Preservation) [commit: 945dd56]
    - [x] Ensure any remaining navigation links preserve active query parameters (`preserveState`, `preserveScroll` where appropriate).
    - [x] Verify filter changes do not accidentally drop other params (e.g., changing `source` keeps `status`, `q`, `since`).
    - [x] Note: "Load More" / infinite scroll is implemented in Phase 3; Phase 2 focuses on URL-driven filters.

- [x] Task: Loading States & Feedback [commit: 945dd56]
    - [x] Implement visual loading indicators (Inertia progress + spinner/skeleton loader as needed).
    - [x] Disable/dim the feed while loading to make state changes clear.
    - [x] Keep/confirm "No Results" empty state includes a "Clear Filters" action.

- [x] Task: Frontend - Partial Reloads (Performance) [commit: 945dd56]
    - [x] Use Inertia partial reloads (where appropriate) to refresh only the `alerts` prop and keep the rest of the page stable.

- [x] Task: Testing - Phase 2 Verification [commit: 945dd56]
    - [x] Verify URL param changes always round-trip (UI state ↔ URL ↔ Inertia props).
    - [x] Verify status/source/since/q combinations return expected results (no page-scoped filtering).

- [x] Task: Conductor - User Manual Verification 'Phase 2: Frontend URL Filters + UX' (Protocol in workflow.md) [commit: 945dd56]
      Manual verification summary (via `scripts/run-manual-test.sh`): dataset prep + URL echo checks completed, invalid params rejected, cleanup completed; log `storage/logs/manual_tests/feed_001_phase_2_frontend_url_filters_ux_2026_02_21_013435.log`.

## Phase 3: Infinite Scroll (Cursor-Based) [checkpoint: TBD]

**Goal:** Replace numbered paging with cursor-based infinite scroll that appends batches deterministically without skips/duplicates.

- [x] Task: Data Fetch Strategy [commit: TBD]
    - [x] Decision: Use Option B - dedicated JSON endpoint (`/api/feed`) for feed batches
    - [x] Created `FeedController` in `app/Http/Controllers/Api/FeedController.php`
    - [x] Added route with rate limiting (120 req/min)
    - [x] Reuses `UnifiedAlertsQuery::cursorPaginate()` for consistent behavior

- [x] Task: Backend - Update GtaAlertsController [commit: TBD]
    - [x] Switched from `paginate()` to `cursorPaginate()` for Inertia props
    - [x] Updated alerts prop structure: `{ data: [], next_cursor: string|null }`
    - [x] Removed traditional pagination metadata (links, meta)

- [x] Task: Frontend - Implement Infinite Scroll [commit: TBD]
    - [x] Created `useInfiniteScroll` hook in `resources/js/features/gta-alerts/hooks/useInfiniteScroll.ts`
    - [x] Features:
        - IntersectionObserver with configurable rootMargin (default: 300px)
        - Accumulates alerts from multiple batches
        - Deduplicates by alert ID
        - AbortController for request cancellation
        - Prevents concurrent fetch requests (isFetching ref guard)
        - Handles stale responses when filters change mid-request
        - Resets list when filters change (via useEffect dependency)
    - [x] Updated `FeedView.tsx` to use infinite scroll hook
    - [x] Added sentinel element for intersection detection
    - [x] Shows "Loading more..." spinner during fetch
    - [x] Shows "No more alerts" when `next_cursor` is null
    - [x] Shows error message with reload option on fetch failure
    - [x] Updated `App.tsx` to pass `initialAlerts` and `initialNextCursor` props
    - [x] Updated `gta-alerts.tsx` page component with new prop types

- [x] Task: Edge Cases (Scroll + Filtering) [commit: TBD]
    - [x] Concurrent request prevention: `isFetchingRef` guards against double-fetch
    - [x] Stale response handling: Compares filter state before/after request, discards if changed
    - [x] Request cancellation: AbortController aborts in-flight requests on filter change
    - [x] Deduplication: Filters out alerts with existing IDs before appending
    - [x] Filter reset: `useEffect` watches `initialAlerts`/`initialNextCursor`, resets state

- [x] Task: Testing - Phase 3 Verification [commit: TBD]
    - [x] Created `tests/Feature/Api/FeedControllerTest.php` with 14 tests:
        - API returns alerts with next_cursor structure
        - Respects status, source, since filters
        - Supports cursor pagination (no duplicates across pages)
        - Returns next_cursor when more results available
        - Fetches next page using cursor correctly
        - Validates cursor parameter (rejects invalid)
        - Rejects invalid status, source, since values
        - Handles empty results
        - Combines multiple filters
        - Rate limiting middleware applied
    - [x] Updated `tests/Feature/GtaAlertsTest.php` for new alerts structure
    - [x] All 461 tests passing (4 MySQL-specific tests skipped in SQLite)

- [x] Task: Conductor - User Manual Verification 'Phase 3: Infinite Scroll (Cursor-Based)' [commit: TBD]
    - [x] Created `tests/manual/verify_feed_001_phase_3_infinite_scroll.php`
    - [x] Automated checks:
        - Seeds 70 test incidents for pagination testing
        - Verifies 3 pages of cursor pagination (25 + 25 + 20 = 70)
        - Verifies no duplicates between pages
        - Verifies cursor stability (new alerts don't affect existing cursors)
        - Verifies filter change resets result set
    - [x] Manual verification steps documented for browser testing

## Phase 4: Regression & Quality Gate

**Goal:** Lock in correctness with a regression pass before writing final documentation.

- [ ] Task: Coverage and Regression Verification
    - [ ] Execute `composer test` and resolve FEED-001-related failures only.
    - [ ] Execute `pnpm run lint`.
    - [ ] Execute `pnpm run format` (only if touched files in `resources/` need formatting).

- [ ] Task: Regression Checklist (Feature Behaviors)
    - [ ] Verify server-side filters are authoritative (no page-scoped filtering):
        - [ ] `status` + `source` + `since` + `q` combinations behave as expected.
    - [ ] Verify infinite scroll behavior:
        - [ ] appends batches deterministically with no duplicates
        - [ ] handles “no more results”
        - [ ] resets list + cursor when any filter changes
    - [ ] Verify URL state is shareable and back/forward restores filters.
    - [ ] Verify Cards/Table toggle remains client-side only.

- [ ] Task: Conductor - User Manual Verification 'Phase 4: Regression & Quality Gate' (Protocol in workflow.md)

## Phase 5: Documentation

**Goal:** Document the shipped feature (backend params, frontend behavior, and operational notes).

- [ ] Task: Documentation Update
    - [ ] Document the feed query params (`status`, `source`, `q`, `since`, `cursor`) and examples.
    - [ ] Document infinite scroll behavior (cursor semantics, “no more results”, reset behavior on filter change).
    - [ ] Document FULLTEXT requirement and sqlite fallback expectations for local/dev.
    - [ ] Update any GTA Alerts UI docs to reflect removal of client-side filtering for the live feed.

- [ ] Task: Conductor - User Manual Verification 'Phase 5: Documentation' (Protocol in workflow.md)
