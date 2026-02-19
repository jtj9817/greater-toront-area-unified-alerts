# Implementation Plan - Server-Side Filtering & Search Refactor

This plan outlines the steps to refactor the Alert Feed to support full server-side filtering, search, and pagination, addressing the misleading client-side filtering issue.

## User Review Required

> [!IMPORTANT]
> **Critical UX Decision:** Confirm the desired behavior for "Active" vs. "Resolved" filters. Should the default view show *only* active alerts, or *all* alerts sorted by time? (Assumption: Default to "Active" for relevance, with a toggle for "All").

## Proposed Plan

### Phase 1: Backend Query Refactor & Testing
- [ ] Task: Create Feature Branch & Test Environment
    - [ ] Create branch `feat/server-side-filtering`
    - [ ] Ensure local database has sufficient seed data (at least 100+ alerts) for meaningful pagination testing.
- [ ] Task: TDD - Define Filtering Requirements
    - [ ] Create `tests/Feature/AlertFilteringTest.php`
    - [ ] Write failing test: `it_can_filter_alerts_by_category` (e.g., `?category=fire` returns only Fire incidents).
    - [ ] Write failing test: `it_can_search_alerts_by_text` (e.g., `?search=Yonge` returns matching records).
    - [ ] Write failing test: `it_can_filter_alerts_by_date_range` (e.g., `?start_date=2024-01-01&end_date=2024-01-02`).
    - [ ] Write failing test: `it_can_filter_alerts_by_time_of_day` (e.g., `?start_time=14:00&end_time=16:00`).
    - [ ] Write failing test: `it_can_combine_multiple_filters` (e.g., Category + Search + Date).
- [ ] Task: Implement Backend Filtering Logic
    - [ ] Update `AlertController@index` to accept query parameters: `category`, `search`, `start_date`, `end_date`, `start_time`, `end_time`.
    - [ ] Implement query scopes or a dedicated `AlertFilter` class to apply these filters cleanly.
    - [ ] Add `FULLTEXT` index migration to `alerts` table columns (`description`, `location`, `title`) for performance.
    - [ ] Implement date/time filtering logic, ensuring explicit `America/Toronto` timezone handling.
    - [ ] Verify all tests in `AlertFilteringTest.php` pass.
- [ ] Task: Conductor - User Manual Verification 'Backend Query Refactor & Testing' (Protocol in workflow.md)

### Phase 2: Frontend State Management & UX
- [ ] Task: Update Alert Feed Component Structure
    - [ ] Refactor `AlertFeed.tsx` (or parent page) to accept filters as props from Inertia.
    - [ ] Update `useForm` or state management to sync filter inputs with URL query parameters.
- [ ] Task: Implement Filter UI Components
    - [ ] Add/Update Category Selector (Dropdown or Tabs) to trigger server reload on change.
    - [ ] Add/Update Search Bar to trigger server reload (with debounce) on input.
    - [ ] Add Date Range Picker component (Start/End Date).
    - [ ] Add Time Range Picker component (Start/End Time).
    - [ ] Add "Quick Filters" (e.g., "Today", "Last 24h") that auto-populate date/time fields.
- [ ] Task: Implement "Active/Resolved" Toggle
    - [ ] Add UI toggle for "Show Resolved" (default: Off/Active Only).
    - [ ] Wire toggle to backend `status` filter.
- [ ] Task: Pagination Integration
    - [ ] Ensure pagination links (Inertia `<Link>`) preserve current query parameters (`preserveState`, `preserveScroll` where appropriate).
    - [ ] Verify "Load More" or "Next Page" behavior works correctly with active filters.
- [ ] Task: Loading States & Feedback
    - [ ] Implement visual loading indicators (spinner, skeleton loader) during data fetches.
    - [ ] Add "No Results" empty state with "Clear Filters" action.
- [ ] Task: Conductor - User Manual Verification 'Frontend State Management & UX' (Protocol in workflow.md)

### Phase 3: Performance & Edge Cases
- [ ] Task: Performance Tuning
    - [ ] Analyze query performance with `EXPLAIN` on complex filter combinations.
    - [ ] optimize indexes if necessary (beyond FULLTEXT).
- [ ] Task: Edge Case Handling
    - [ ] Test behavior with invalid dates/times in URL.
    - [ ] Test behavior with empty search strings.
    - [ ] Test behavior with extreme pagination (e.g., page 1000).
- [ ] Task: Conductor - User Manual Verification 'Performance & Edge Cases' (Protocol in workflow.md)

### Phase 4: Quality & Documentation
- [ ] Task: Full Test Suite Run
    - [ ] Run `./vendor/bin/sail artisan test` to ensure no regressions.
    - [ ] Run `./vendor/bin/sail artisan test --coverage` to verify >90% coverage on new/modified files.
- [ ] Task: Documentation Update
    - [ ] Update `docs/` with new filtering capabilities and API parameters.
    - [ ] Update `README.md` if user-facing features significantly changed.
- [ ] Task: Conductor - User Manual Verification 'Quality & Documentation' (Protocol in workflow.md)
