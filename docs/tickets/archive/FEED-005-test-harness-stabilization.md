---
ticket_id: FEED-005
title: "[Tests] Stabilize Infinite Scroll Test Harness"
status: Closed
priority: Medium
assignee: Unassigned
created_at: 2026-02-21
tags: [tests, frontend, feed-001, phase-3]
related_files:
  - resources/js/tests/setup.ts
  - resources/js/features/gta-alerts/components/FeedView.test.tsx
  - resources/js/features/gta-alerts/App.test.tsx
  - conductor/tracks/server_side_filtering_20260218/plan.md
---

## Overview

Supplement to Phase 3 (Infinite Scroll) in `conductor/tracks/server_side_filtering_20260218/plan.md`. This ticket adds test harness fixes so the current infinite scroll implementation is exercised safely in Vitest + jsdom.

## Problem

- JSDOM does not ship `IntersectionObserver`, so tests fail at runtime.
- FeedView tests still use deprecated pagination props, causing invalid render calls and assertions.

## Scope

**In scope**
- Add an `IntersectionObserver` polyfill in the test setup.
- Update FeedView test fixtures to use `initialAlerts` + `initialNextCursor` and current UI expectations.

**Out of scope**
- Production code changes.
- Backend pagination or feed contract changes.

## Plan

1. Add a minimal `IntersectionObserver` stub in `resources/js/tests/setup.ts`.
2. Update `resources/js/features/gta-alerts/components/FeedView.test.tsx`:
   - Replace `allAlerts` + `pagination` with `initialAlerts` + `initialNextCursor`.
   - Align assertions with infinite scroll UI (loaded count vs. pagination controls).
3. Re-run targeted tests:
   - `pnpm run test -- resources/js/features/gta-alerts/components/FeedView.test.tsx`
   - `pnpm run test -- resources/js/features/gta-alerts/App.test.tsx`
4. Run the full test chain and lint/format/types per guidelines.

## Test Parameters

- `searchQuery`
- `onSelectAlert`
- `initialAlerts`
- `initialNextCursor`
- `latestFeedUpdatedAt`
- `status`
- `source`
- `since`

## Expected Effects

- Test runs use the same data flow as production: `UnifiedAlertResource[]` -> `useInfiniteScroll` -> UI.
- `IntersectionObserver` runtime errors are eliminated without changing production behavior.

## Acceptance Criteria

- Targeted FeedView and App tests pass without `IntersectionObserver` errors.
- Full test suite and lint/format/type checks pass.
