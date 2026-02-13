# Ticket: Fix concurrency guard inconsistency in NotificationInboxView

**Status:** Open
**Priority:** Low
**Assignee:** Unassigned
**Created:** 2026-02-12

## Description
During code review of commit 219102c, an inconsistency was identified in `resources/js/features/gta-alerts/components/NotificationInboxView.tsx`.

The "Load More" button is disabled when `activeItemId !== null` (i.e., when an individual item action is in progress), but the `loadMore` function's guard clause does not enforce this check.

## Acceptance Criteria
- Update the `loadMore` function in `NotificationInboxView.tsx` to include `activeItemId !== null` in its early return guard clause.
- Ensure strict consistency between the UI disabled state and the functional guard logic.

## Context
Commit: 219102c
File: `resources/js/features/gta-alerts/components/NotificationInboxView.tsx`
