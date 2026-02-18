# REVIEW-003: Code Review Findings - Notifications Phase 3

**Date:** 2026-02-10
**Reviewer:** Gemini CLI (Principal Software Engineer / Code Review Architect)
**Commit:** 29c4169
**Status:** Complete
**Priority:** High
**Verified on codebase (2026-02-18):** Toast timer cleanup, payload schema validation, and backend-provided subscription route options are implemented. Route subscription options are no longer derived from active alert payloads.

## Summary
Review of the Phase 3 implementation for Notification Settings and Real-time Toasts. While functional, the implementation contains a client-side memory leak in the toast layer and a logical flaw in how users discover routes for subscription, which limits the feature's utility to currently active alerts only.

## High Priority Issues

### [HIGH] Memory Leak in NotificationToastLayer
**File:** `resources/js/features/gta-alerts/components/NotificationToastLayer.tsx`
**Line:** 151
**Description:** The `timersRef.current` array accumulates `setTimeout` IDs for every incoming notification but never removes them. In a long-running dashboard session with many alerts, this array will grow indefinitely, consuming memory and preventing cleanup of stale IDs.
**Recommendation:**
- Filter the `timersRef.current` array to remove the timer ID once the timeout callback executes.
- Ensure `clearTimeout` is called for any remaining timers in the `useEffect` cleanup.

### [HIGH] Incomplete Route Subscription Options
**File:** `resources/js/features/gta-alerts/App.tsx`
**Line:** 99
**Description:** The `routeOptions` (used to populate the subscription list in Settings) are derived exclusively from the `alerts.data` prop. This means users can only subscribe to routes that currently have an active alert on the first page of the feed. Routes without current incidents are invisible and unsubscribable.
**Recommendation:**
- Provide a comprehensive list of routes from the backend or a static constant.
- Decouple the "Available Routes" metadata from the "Active Alerts" state.

## Medium Priority Issues

### [MEDIUM] Hardcoded Route Domain Data in UI
**File:** `resources/js/features/gta-alerts/components/SettingsView.tsx`
**Line:** 43
**Description:** `FALLBACK_ROUTES` contains hardcoded route IDs like '501' and 'GO-LW'. This couples the frontend presentation logic to specific backend data that may change over time, requiring a code deploy for simple data updates.
**Recommendation:**
- Move these defaults to a central configuration file or, preferably, fetch them from a dedicated API endpoint.

## Low Priority Issues

### [LOW] Redundant Toast Normalization
**File:** `resources/js/features/gta-alerts/components/NotificationToastLayer.tsx`
**Line:** 26
**Description:** Manual normalization of the WebSocket payload is verbose.
**Recommendation:** Consider using a Zod schema or a dedicated TypeScript type guard utility to simplify the validation logic.
