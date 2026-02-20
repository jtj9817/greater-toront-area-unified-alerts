---
ticket_id: FEED-003
title: "[Review] Phase 2 Frontend Filters & UX Refinements"
status: Open
priority: Medium
assignee: Unassigned
created_at: 2026-02-20
tags: [frontend, ux, review, feed-001]
related_files:
  - resources/js/features/gta-alerts/components/FeedView.tsx
  - resources/js/features/gta-alerts/App.tsx
---

## Overview

Review of commits `16775bb` and `047ca5f` implementing Phase 2 of the Server-Side Filters & Infinite Scroll plan. The implementation is largely correct and robust, but a few UX and consistency edge cases were identified.

## Findings

### 1. Global Loading State Scope (UX)
**Severity:** Medium
**Location:** `resources/js/features/gta-alerts/components/FeedView.tsx`

The `isLoading` state is driven by global Inertia router events (`start`, `finish`) without filtering for the navigation target.

```typescript
    useEffect(() => {
        const removeStartListener = router.on('start', () => {
            setIsLoading(true);
        });
        // ...
    }, []);
```

**Issue:**
If a user initiates a navigation *outside* the feed context (e.g., clicking a "Logout" link or a sidebar link that triggers a full page visit), the FeedView will display the "Updating feed..." indicator and overlay before the page unmounts/redirects. This message is misleading for non-feed actions.

**Recommendation:**
Scope the loading listener to only trigger for partial reloads targeting the feed, or specific URLs. Alternatively, check the `detail.visit` properties in the event listener.

```typescript
router.on('start', (event) => {
    // Only show loading if we are reloading alerts or filters
    if (event.detail.visit.only?.includes('alerts')) {
        setIsLoading(true);
    }
});
```

### 2. Stale `filters` Prop in App.tsx
**Severity:** Low
**Location:** `resources/js/features/gta-alerts/App.tsx`

In the search debounce `useEffect`, the `router.get` call updates the URL but requests `only: ['alerts']`.

```typescript
                router.get(
                    // ...
                    {
                        // ...
                        only: ['alerts'], // Missing 'filters'
                    },
                );
```

**Issue:**
While `FeedView.tsx` correctly requests `['alerts', 'filters']`, `App.tsx` omits `filters`. This means the `filters` prop passed to `App` (and subsequently to `FeedView`) will become stale relative to the URL parameters after a search update. While the UI currently relies on local state (`searchQuery`) and the backend doesn't mutate other filters based on search, this creates an inconsistency where `usePage().props.filters` does not match the URL `q` parameter.

**Recommendation:**
Update `App.tsx` to include `'filters'` in the `only` array for consistency.

```typescript
only: ['alerts', 'filters'],
```

### 3. Test Robustness for Router Events
**Severity:** Low
**Location:** `resources/js/features/gta-alerts/components/FeedView.test.tsx`

The test mocks `usePage` but does not explicitly mock `router.on`.

```typescript
vi.mock('@inertiajs/react', async () => {
    const actual = await vi.importActual('@inertiajs/react');
    return {
        ...actual, // Uses actual router if available
        usePage: () => ({ ... }),
    };
});
```

**Issue:**
If the test environment (Vitest/JSDOM) does not fully support the `router` singleton or if `vi.importActual` returns a non-functional router stub, the `useEffect` in `FeedView` might throw or fail silently.

**Recommendation:**
Explicitly mock `router.on` in the test setup to ensure deterministic behavior and prevent future regressions if the internal `router` implementation changes.

## Action Items

- [ ] Update `FeedView.tsx` to scope `isLoading` to relevant visits.
- [ ] Update `App.tsx` to include `filters` in `only` array.
- [ ] (Optional) Enhance test mocks in `FeedView.test.tsx`.

## Implementation Context

### Phase 1 Commit Audit - FEED-001 Server-Side Filters + Infinite Scroll

Below are the **implementation commits** (code edits only, excluding docs/conductor-only commits) mapped to each phase since `e325354`:

#### Phase 1: Backend Filters + Cursor Pagination (Foundation)

**e325354** — feat(feed): Phase 1 backend filters
- **Controller (`GtaAlertsController`)**: Added validated feed filter params (`status`, `source`, `q`, `since`, `cursor`) via `Rule::enum` and custom `UnifiedAlertsCursorRule`
- **Inertia Props**: Added `filters` prop that echoes active values (`status`, `source`, `q`, `since`) for UI rehydration from URL state
- **UnifiedAlertsCriteria DTO**: Expanded with `?string $source`, `?string $query`, `?string $since`, `?CarbonImmutable $sinceCutoff`, `?UnifiedAlertsCursor $cursor`
- **Normalization Helpers**: Added `normalizeSource()` (validates against `AlertSource` enum), `normalizeQuery()` (trim, null if empty), `normalizeSince()` (validates against `SINCE_OPTIONS`), `computeSinceCutoff()` (maps `30m|1h|3h|6h|12h` to `CarbonImmutable`), `normalizeCursor()` (decodes opaque cursor)
- **UnifiedAlertsCursor DTO**: Created readonly class with `CarbonImmutable $timestamp` and `string $id`, implements `encode()` (base64url of JSON `{ts, id}`) and `decode()` with validation
- **UnifiedAlertsQuery**: Implemented `cursorPaginate()` method with seek-based cursor pagination (`timestamp < cursor_ts OR (timestamp = cursor_ts AND id < cursor_id)`), returns `['items' => ..., 'next_cursor' => ...]`
- **AlertSelectProvider Interface**: Updated `select()` signature to accept `UnifiedAlertsCriteria $criteria`
- **Provider Implementations** (`FireAlertSelectProvider`, `PoliceAlertSelectProvider`, `TransitAlertSelectProvider`, `GoTransitAlertSelectProvider`): Added MySQL FULLTEXT `MATCH...AGAINST` predicates for `q` search with SQLite `LIKE` fallback
- **Migration**: Created `2026_02_19_120000_add_fulltext_indexes_to_alert_tables.php` adding FULLTEXT indexes on `fire_incidents(event_type, prime_street, cross_streets)`, `police_calls(call_type, cross_streets)`, `transit_alerts(title)`, `go_transit_alerts(message_subject)`
- **UnifiedAlertsCursorRule**: Created validation rule that trims cursor and fails closed for invalid/tampered cursors
- **Tests**: Extended coverage for criteria validation, filter combinations, cursor pagination determinism, and MySQL FULLTEXT path

#### Phase 1 Fixes & Refinements

**f56581a** — fix(alerts): trim cursor during validation
- **UnifiedAlertsCriteria**: Updated `normalizeCursor()` to trim before decoding and treat whitespace-only as unset
- **UnifiedAlertsCursorRule**: Updated to trim cursor value before validation
- **UnifiedAlertsCursor**: `decode()` now accepts trimmed values
- **Regression Tests**: Added tests for trimmed cursor handling
- **Manual Scripts**: Updated all manual verification scripts for provider signature changes

#### Phase 1 Extensions (Performance & Optimization)

**10c87bd** — perf(alerts): push down provider filters
- **AlertSelectProvider Interface**: Added `source(): string` method to identify provider source
- **Provider Implementations**: All providers now implement `source()` returning enum value (`fire`, `police`, `transit`, `go_transit`)
- **UnifiedAlertsQuery**: Optimized `unionSelect()` to skip providers when `$criteria->source` is set (push-down optimization), filters providers before unioning
- **Provider Queries**: Added status and since predicate push-down into individual provider SELECTs to reduce rows before union
- **Outer Filters**: Kept outer unified filters as defensive layer
- **Tests**: Added coverage for provider filtering and push-down behavior
- **Documentation**: Created `FEED-002-provider-filter-optimization.md` ticket doc

**3c9abff** — feat(feed): align sources and since filters
- **AlertSource Enum**: Removed `Hazard` case (not a unified alert source), kept `Fire`, `Police`, `Transit`, `GoTransit`
- **Frontend (`App.tsx`, `FeedView.tsx`)**: Updated to use server-driven filter props from Inertia, removed client-side category filtering
- **AlertService**: Removed local filtering methods that were doing page-scoped filtering
- **Constants**: Updated source options to match backend enum
- **Documentation**: Updated `notification-system.md` to reflect unified source list

#### Phase 1 Verification & Infrastructure

**54703b6** — test(manual): add FEED-001 Phase 1 verifier
- **Manual Test Script**: Created `tests/manual/verify_feed_001_phase_001_backend_filters_cursor_pagination.php`
- **Test Coverage**: Seeds deterministic mixed feed (Fire, Police, Transit, GO Transit), verifies status/source/since/q filtering, cursor pagination ordering, cursor encoding/decoding, deduplication across batches
- **Database Safety**: SQLite runs inside transaction with rollback; MySQL runs without transaction (InnoDB FULLTEXT limitation) with explicit cleanup

**d1436de** — fix(scripts): run manual tests via sail user
- **scripts/run-manual-test.sh**: Removed container user override so Sail can initialize writable composer cache and drop privileges correctly, exported `WWWUSER`/`WWWGROUP` to avoid compose warnings

**a63d9b8** — test(manual): avoid mysql fulltext rollback trap
- **Manual Test Script**: Updated to detect MySQL driver and skip wrapping transaction (InnoDB FULLTEXT may miss uncommitted inserts), added explicit cleanup in `finally` block for MySQL path

#### Auxiliary Implementation (Security)

**3f30f12** — feat(security): implement security headers middleware and tests
- **EnsureSecurityHeaders Middleware**: Created middleware setting `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=(self)`, `Strict-Transport-Security` (conditionally in production/HTTPS)
- **Bootstrap**: Registered middleware in `bootstrap/app.php`
- **Tests**: Created `SecurityHeadersTest.php` with assertions for all security headers

### Phase 2 Commit Audit - FEED-001 Frontend URL Filters + UX

Below are the **implementation commits** (code edits only, excluding docs/conductor-only commits) mapped to Phase 2 since `f77f68d`:

#### Phase 2: Frontend URL Filters + UX (In Progress)

**b299aba** — fix(gta-alerts): synchronize search input with URL on router success
- **Frontend State Management**: Replaced render-phase state synchronization pattern with event-driven `router.on('success')` listener
- **URL State Tracking**: Implemented `useCallback` memoized `syncSearchQueryFromUrl()` function that reads `q` param from `window.location.search` and updates local `searchQuery` state conditionally (only if changed)
- **Inertia Router Integration**: Added event listener for Inertia `success` events, filtering for `gta-alerts` component only to avoid unnecessary re-syncs on other page navigations
- **Debounced Search Update**: Enhanced debounce logic to read current URL query directly from `URLSearchParams` instead of relying on stale prop values, ensuring accurate comparison before triggering `router.get()` navigation
- **Back Button Support**: Search input now correctly synchronizes when using browser back/forward navigation
- **Reset Filters Support**: Search input properly clears when "Reset All Filters" action is triggered via URL change

#### Phase 2: Frontend URL Filters + UX (Completed)

**047ca5f** — fix(gta-alerts): restore filter state and loading UX
- **Loading State Detection**: Replaced `usePage().props.processing` with Inertia router event listeners (`router.on('start')` and `router.on('finish')`) for accurate loading state tracking
- **Event Listener Lifecycle**: Implemented proper `useEffect` setup and cleanup for router event listeners to prevent memory leaks
- **Filter Reset Fix**: Updated "Reset All Filters" logic to set `status: null` instead of `status === 'all' ? null : status`, ensuring complete filter clearing on reset
- **Partial Reload Enhancement**: Added `'filters'` to the `only` array in all `router.get()` calls (alongside `'alerts'`), ensuring filter UI state is preserved and restored correctly during partial page reloads
- **SSR Safety**: Maintained proper event listener cleanup on component unmount

**e3b199b** — fix(gta-alerts): keep status and search on reset
- **Selective Filter Reset**: Reverted the "Reset All Filters" behavior to preserve status and q (search query) while only clearing source and since filters
- **Status Preservation**: Reset now keeps status: status === 'all' ? null : status instead of clearing to null
- **Search Query Preservation**: Reset now keeps q: searchQuery || null instead of clearing to null
- **UX Refinement**: Aligns reset behavior with user expectations—users typically want to maintain their current status filter (Active/Resolved) and search terms when clearing other filters

### Summary

**Phase 2 Complete**: Frontend URL Filters + UX implementation is now complete. This commit addresses critical UX issues around loading state visibility and filter state restoration during partial reloads.

**Key Deliverables from this Commit**:
1. **Accurate Loading UX**: Router event-based loading state ensures users see loading indicators during Inertia navigation
2. **Complete Filter Reset**: Reset action now properly clears all filter parameters including status
3. **State Restoration**: Partial reloads now include the `filters` prop, ensuring UI components stay synchronized with URL state after navigation
4. **Memory Management**: Proper cleanup of router event listeners prevents memory leaks

**Phase 2 Tasks Completed** (covered by commits since f77f68d and 16775bb):
- ✅ Task: Update Alert Feed Component Structure
- ✅ Task: Frontend - URL Synchronization (Inertia)
- ✅ Task: Implement Filter UI Components
- ✅ Task: Implement "Active/Resolved" Toggle (Status)
- ✅ Task: Pagination Integration (URL + State Preservation)
- ✅ Task: Loading States & Feedback
