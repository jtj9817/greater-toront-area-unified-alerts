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
