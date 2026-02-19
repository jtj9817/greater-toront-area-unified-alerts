# [FEED-002] Real-Time Push for Alert Feed

**Date:** 2026-02-18
**Status:** Open
**Priority:** Medium
**Components:** Backend, Frontend
**Depends On:** [FEED-001](./FEED-001-server-side-filters-infinite-scroll.md)

## Problem

The alert feed requires a manual page refresh or navigation to see new alerts. For a live emergency dashboard, users expect new incidents to appear automatically as they come in.

## Current State

- Feed data is fetched on initial page load and on pagination/filter navigation via Inertia
- The backend already has a broadcast event `AlertNotificationSent` on `private-users.{userId}.notifications` for the notification system
- No mechanism exists to push new alerts to the general feed view

## Proposed Solution

Once server-side filters and infinite scroll (FEED-001) are in place, introduce real-time updates so new alerts matching the user's active filters are prepended to the top of the feed without a full reload.

### Approach Options

| Option | Pros | Cons |
|--------|------|------|
| **WebSocket (Laravel Echo + Reverb)** | Bi-directional, low latency, existing Echo infrastructure | More server resources, connection management |
| **Server-Sent Events (SSE)** | Simple, one-directional (sufficient for feed), lightweight | No native Laravel support, less ecosystem tooling |
| **Polling (short interval)** | Simplest to implement, no infrastructure changes | Higher server load, not truly real-time, wastes bandwidth |

### Key Considerations

- **Filter-aware updates:** New alerts should only appear if they match the user's current server-side filters (status, source, search, time range)
- **Deduplication:** If an alert already exists in the loaded feed, it should be updated in place rather than duplicated
- **Visual indicator:** A "New alerts available" banner or count badge could let users opt-in to loading new items, avoiding disorienting feed jumps while reading
- **Anonymous vs authenticated:** The general feed is public; a public broadcast channel would be needed rather than the existing private user channel
- **Rate limiting:** Batch rapid-fire updates (e.g., multiple police calls arriving simultaneously) to avoid UI thrashing

## Out of Scope

- Push notifications (browser/mobile) — handled by the existing notification system
- Alert editing or two-way interaction over the socket
