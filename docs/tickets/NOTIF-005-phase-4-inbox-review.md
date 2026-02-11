# Ticket: Phase 4 Inbox Code Review Findings

**ID:** NOTIF-005
**Type:** Code Review / Refactor
**Status:** Open
**Priority:** High
**Created:** 2026-02-10
**Component:** Notifications (Inbox)

## Description
This ticket captures findings and required improvements from the code review of the Phase 4 Notification Inbox implementation (commit `65568be`). The focus is on addressing performance bottlenecks, functional gaps in pagination, and usability improvements.

## Findings & Action Items

### 1. Fix Inefficient Bulk Update
**Severity:** HIGH
**Location:** `app/Http/Controllers/Notifications/NotificationInboxController.php` (L104)
**Issue:** The `clearAll` method loads all notification IDs into memory (`pluck('id')->all()`) before updating them. This will lead to memory exhaustion for users with large notification histories.
**Action:** Refactor to use direct database query updates.

```php
// Proposed Implementation
public function clearAll(Request $request): JsonResponse
{
    $userId = $request->user()->id;
    $now = now();

    // Mark active unread notifications as read first
    NotificationLog::query()
        ->where('user_id', $userId)
        ->whereNull('dismissed_at')
        ->whereNull('read_at')
        ->update(['read_at' => $now]);

    // Then dismiss all active notifications
    $dismissedCount = NotificationLog::query()
        ->where('user_id', $userId)
        ->whereNull('dismissed_at')
        ->update([
            'dismissed_at' => $now,
            'status' => 'dismissed',
        ]);

    return response()->json([
        'meta' => [
            'dismissed_count' => $dismissedCount,
            'unread_count' => $this->unreadCount($userId),
        ],
    ]);
}
```

### 2. Implement Frontend Pagination
**Severity:** HIGH
**Location:** `resources/js/features/gta-alerts/components/NotificationInboxView.tsx` (L99)
**Issue:** The frontend fetches a fixed page size (50 items) but ignores the pagination links (`next`, `prev`) returned by the API. Users cannot access older notifications once they exceed the initial page limit.
**Action:** Implement "Load More" functionality or standard pagination controls to fetch subsequent pages.

### 3. Improve Notification Interaction (Click-to-View)
**Severity:** MEDIUM
**Location:** `resources/js/features/gta-alerts/components/NotificationInboxView.tsx` (L338)
**Issue:** Notification items are static text. Users cannot navigate to the alert details from the inbox.
**Action:** Integrate inbox items with the existing in-app alert details flow (state-driven in `App.tsx`) instead of adding a hardcoded `/alerts/{id}` link route that does not currently exist. Add an `onOpenAlert(alertId)` callback from `App.tsx` to `NotificationInboxView` and trigger it for non-digest items with a valid `alert_id`.

```tsx
// NotificationInboxView props
type NotificationInboxViewProps = {
    authUserId: number | null;
    onOpenAlert?: (alertId: string) => void;
};

<p className="mb-3 text-sm leading-snug font-medium text-white">
    {!isDigest && item.alert_id ? (
        <button
            type="button"
            className="text-left hover:text-primary hover:underline"
            onClick={() => onOpenAlert?.(item.alert_id)}
        >
            {isDigest ? digestDescription(item) : alertSummary(item)}
        </button>
    ) : (
        isDigest ? digestDescription(item) : alertSummary(item)
    )}
</p>
```

### 4. Optimize Date Formatting Performance
**Severity:** LOW
**Location:** `resources/js/features/gta-alerts/components/NotificationInboxView.tsx` (L29)
**Issue:** `Intl.DateTimeFormat` is instantiated inside the render loop for every item.
**Action:** Move the formatter instance outside the component/function scope.

```typescript
const dateFormatter = new Intl.DateTimeFormat('en-CA', {
    dateStyle: 'medium',
    timeStyle: 'short',
});
```
