# REVIEW-002: Code Review Findings - Notifications Phase 2

**Date:** 2026-02-09
**Reviewer:** Gemini CLI
**Commit:** e31a552
**Status:** Open
**Priority:** High

## Summary
Review of the Phase 2 Notification Engine implementation. The core logic is sound, but there are significant scalability concerns regarding memory usage and database query performance that need to be addressed before high-load production use.

## Critical / High Priority Issues

### [HIGH] Memory Exhaustion in NotificationMatcher
**File:** `app/Services/Notifications/NotificationMatcher.php`
**Line:** 15
**Description:** `NotificationPreference::query()->where('push_enabled', true)->get()` loads all enabled preferences into memory. This will cause OOM errors as the user base grows.
**Recommendation:**
- Filter `alert_type` at the database level.
- Use `cursor()` or `chunk()` to process records.

### [HIGH] N+1 Query in Daily Digest Generation
**File:** `app/Jobs/GenerateDailyDigestJob.php`
**Line:** 24
**Description:** The `chunkById` loop executes individual queries for every user to check for existing digests and count notifications. For 1,000 users, this results in ~3,000 database queries.
**Recommendation:**
- Pre-fetch notification counts for the entire chunk using `groupBy` and `selectRaw`.
- Pass the pre-fetched count to the creation method.

## Medium Priority Issues

### [MEDIUM] Sequential Processing in Listener
**File:** `app/Listeners/DispatchAlertNotifications.php`
**Line:** 20
**Description:** Sequential dispatching of jobs inside the listener may time out if there are many matching users.
**Recommendation:**
- Use `LazyCollection` or chunking.
- Consider a "Fan Out" job architecture for high-volume alerts.

## Low Priority Issues

### [LOW] Race Condition in Delivery Job
**File:** `app/Jobs/DeliverAlertNotificationJob.php`
**Line:** 58
**Description:** A minor race condition exists where concurrent jobs could both see a log as 'sent' before updating to 'delivered', potentially causing double delivery.
**Recommendation:** Check `wasRecentlyCreated` on the log model as a guard.

### [LOW] Hardcoded Severity Strings
**File:** `app/Services/Notifications/NotificationAlertFactory.php`
**Line:** 104
**Description:** Severity levels ('major', 'critical', 'minor') are hardcoded strings.
**Recommendation:** Use a `NotificationSeverity` enum or constants for consistency.
