---
ticket_id: FEED-001-REVIEW
title: "[Review] FEED-001 Phase 3 Infinite Scroll Implementation"
status: Open
priority: High
assignee: Unassigned
created_at: 2026-02-21
tags: [review, frontend, performance]
related_files:
  - resources/js/features/gta-alerts/hooks/useInfiniteScroll.ts
  - app/Http/Controllers/Api/FeedController.php
---

## Overview

Code review findings for Phase 3 implementation (Commit `bf98745`).
Note: Issues addressed in `a2e6cdc` (restoration of alert details) are excluded.

## Critical / High Priority

### 1. IntersectionObserver Thrashing in `useInfiniteScroll`

**Severity:** High (Performance/UX)
**File:** `resources/js/features/gta-alerts/hooks/useInfiniteScroll.ts`

The `loadMore` function currently depends on the `alerts` state to perform deduplication. This causes the `loadMore` function reference to change every time a new batch of alerts is appended.

Consequently, the `useEffect` hook managing the `IntersectionObserver` (which depends on `loadMore`) runs its cleanup and setup cycle on every render where `alerts` changes. This means the observer is constantly disconnecting and reconnecting as the user scrolls, which is unnecessary work and can lead to scroll jank or missed intersection events on slower devices.

**Current Implementation:**
```typescript
// useInfiniteScroll.ts

const loadMore = useCallback(async () => {
    // ...
    // Depends on 'alerts'
    const existingIds = new Set(alerts.map((a) => a.id));
    const uniqueNewAlerts = newAlerts.filter(
        (alert) => !existingIds.has(alert.id),
    );

    setAlerts((prev) => [...prev, ...uniqueNewAlerts]);
    // ...
}, [nextCursor, apiUrl, alerts]); // <-- 'alerts' dependency causes thrashing
```

**Recommended Fix:**
Use a functional state update for `setAlerts` to access the previous state for deduplication. This allows removing `alerts` from the `useCallback` dependency array.

```typescript
const loadMore = useCallback(async () => {
    // ... fetching logic ...

    // Deduplicate inside the setter
    setAlerts((prev) => {
        const existingIds = new Set(prev.map((a) => a.id));
        const uniqueNewAlerts = newAlerts.filter(
            (alert) => !existingIds.has(alert.id),
        );
        return [...prev, ...uniqueNewAlerts];
    });

    setNextCursor(data.next_cursor);
    // ...
}, [nextCursor, apiUrl]); // 'alerts' dependency removed
```

## Low Priority / Suggestions

### 2. Hardcoded Page Size in Feed API

**Severity:** Low (Flexibility)
**File:** `app/Http/Controllers/Api/FeedController.php`

The API enforces a hardcoded `perPage` limit via `UnifiedAlertsCriteria::DEFAULT_PER_PAGE`.

```php
$criteria = new UnifiedAlertsCriteria(
    // ...
    perPage: UnifiedAlertsCriteria::DEFAULT_PER_PAGE,
);
```

**Suggestion:**
Consider allowing the client to request a custom page size (with a sane maximum cap, e.g., 100) to support different device viewports or network conditions in the future.

### 3. Redundant AbortController Logic

**Severity:** Low (Code Cleanliness)
**File:** `resources/js/features/gta-alerts/hooks/useInfiniteScroll.ts`

The `loadMore` function attempts to set `abortControllerRef.current` *after* checking `isFetchingRef`. While this works because `isFetchingRef` prevents re-entry, the cleanup logic in `reset` and `useEffect` handles aborting. The explicit `abortControllerRef.current` assignment logic is slightly scattered.

Ensure consistent lifecycle management: if `loadMore` is called, it should strictly be the owner of the *new* controller, and previous ones should generally have been cleaned up or irrelevant if `isFetchingRef` works correctly. No code change strictly required, but worth noting for future refactoring.
