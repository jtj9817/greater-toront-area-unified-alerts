# Review: Scene Intel Frontend Implementation

**Ticket ID:** REVIEW-2026-02-14-SCENE-INTEL
**Reviewer:** Code Review Architect
**Status:** Partially Complete
**Related Commit:** `2057d00` (Main), `35030a3` (Fix)
**Verified on codebase (2026-02-18):** Empty `eventNum` guard, abort handling, and initial-load-only spinner behavior are implemented; interval-based polling overlap risk remains.

## Summary
The commit implements the frontend components for the Scene Intel feature, including the `SceneIntelTimeline` component, `useSceneIntel` hook, and domain schema extensions. While the functional implementation is complete, there are several stability and architectural issues in the data fetching hook that need to be addressed before production use.

## Findings

### 1. Unsafe State Updates in Polling Hook
**Severity:** HIGH
**File:** `resources/js/features/gta-alerts/hooks/useSceneIntel.ts`

The `useSceneIntel` hook performs asynchronous state updates without checking if the component is still mounted. If a user navigates away from the Alert Details view while a fetch is in progress, the `await fetch(...)` will resolve and attempt to call `setItems` or `setLoading` on an unmounted component.

**Recommendation:**
Use an `AbortController` to cancel the fetch request on cleanup, or track the mounted state with a ref.

```typescript
useEffect(() => {
    const controller = new AbortController();
    
    // Pass signal to fetchData
    void fetchData(controller.signal);

    const intervalId = setInterval(() => {
        void fetchData(controller.signal);
    }, 30000);

    return () => {
        controller.abort();
        clearInterval(intervalId);
    };
}, [fetchData]);
```

### 2. Missing Guard for Empty Event ID
**Severity:** HIGH
**File:** `resources/js/features/gta-alerts/hooks/useSceneIntel.ts`

The hook attempts to fetch data even if `eventNum` is an empty string (which can happen if the alert metadata is incomplete). This results in a request to `/api/incidents//intel`, causing a 404 error.

**Recommendation:**
Add a guard clause at the beginning of `fetchData`.

```typescript
const fetchData = useCallback(async () => {
    if (!eventNum) return;
    // ...
```

### 3. UI Flicker on Polling
**Severity:** MEDIUM
**File:** `resources/js/features/gta-alerts/hooks/useSceneIntel.ts`

The hook sets `setLoading(true)` at the start of *every* fetch, including background polls. This causes the "Live" indicator in `SceneIntelTimeline` to flash every 30 seconds.

**Recommendation:**
Distinguish between initial loading and background validating. Only set `loading` to true if `items` is empty, or introduce a separate `isValidating` state.

```typescript
// Only show loading spinner on initial fetch if we have no data
if (items.length === 0) setLoading(true);
```

### 4. Polling Race Conditions
**Severity:** MEDIUM
**File:** `resources/js/features/gta-alerts/hooks/useSceneIntel.ts`

Using `setInterval` for network polling is risky because it doesn't account for request duration. If the API is slow, requests can stack up.

**Recommendation:**
Use a recursive `setTimeout` pattern or a library like `swr` / `react-query`. If strictly using `useEffect`, ensure the previous request is aborted or use a ref to prevent overlapping fetches.

### 5. Hardcoded Styles in Component
**Severity:** LOW
**File:** `resources/js/features/gta-alerts/components/SceneIntelTimeline.tsx`

The `getIconColor` and `getBgColor` functions contain hardcoded switch statements that are repetitive.

**Recommendation:**
Move the style configuration to a constant object or a utility function to improve maintainability.

```typescript
const STYLE_MAP: Record<SceneIntelType, { text: string; bg: string }> = {
    milestone: { text: 'text-purple-400', bg: 'bg-purple-400/10' },
    // ...
};
```
