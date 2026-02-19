# Specification: Server-Side Filtering & Search Refactor

## 1. Overview
The current alert feed implementation suffers from a critical architectural flaw: pagination is handled server-side (50 items/page), but filtering (category, search, date/time) is applied client-side *after* fetching. This results in misleading data, where filters only apply to the current page's subset rather than the full 6,400+ record dataset.

This track will refactor the `AlertController` and frontend components to implement **full server-side filtering**. All filter criteria (category, search text, date/time ranges) will be passed as query parameters to the backend, ensuring accurate results across the entire dataset.

## 2. Functional Requirements
### 2.1 Server-Side Filtering Logic
- **Scope:** Update `AlertController@index` (or equivalent) to accept and process the following query parameters:
    - `category`: Filter by `type` or `source` (e.g., 'Fire', 'Police', 'Transit').
    - `search`: Full-text search string.
    - `start_date` / `end_date`: Date range filter.
    - `start_time` / `end_time`: Specific time-of-day filtering.
    - `relative_time`: Quick filters like "Last 24 Hours", "Today".
- **Database Logic:**
    - Implement MySQL `FULLTEXT` indexes on relevant text columns (`description`, `location`, `title`) for performant search.
    - Ensure all date/time queries explicitly handle the **America/Toronto** timezone to prevent off-by-one errors.
    - Combined logic: Queries must support ANY combination of these filters (e.g., "Fire alerts containing 'Downtown' from yesterday").

### 2.2 Frontend State Management (Inertia.js)
- **URL Synchronization:**
    - Use Inertia's `router.get` (or `useForm` with `get`) to update the URL with current filter state (e.g., `?category=fire&search=collision&range=today`).
    - Ensure browser back/forward buttons correctly restore the filter state.
- **Loading UX:**
    - Leverage Inertia's built-in progress indicators or manual loading states during data fetches.
    - Disable/dim the feed area while new data is loading to indicate activity.
- **Partial Reloads:**
    - Utilize Inertia "partial reloads" where appropriate to refresh only the `alerts` prop, keeping the sidebar/header static for better performance.

### 2.3 Date & Time UX
- **Date Picker:** Add clear start/end date inputs.
- **Time Picker:** Add granular start/end time inputs (e.g., "14:00" to "16:00").
- **Quick Filters:** Add one-click chips for "Last 24h", "Today", "Last Week".

## 3. Non-Functional Requirements
- **Performance:** Search queries must remain performant (<200ms) even with full-text search on the `alerts` table.
- **Scalability:** The solution must work efficiently as the dataset grows beyond 10,000 records.
- **Usability:** Filter state must persist across page reloads (via URL params).

## 4. Acceptance Criteria
- [ ] **Category Filter:** Selecting "Fire" updates the URL and returns ONLY fire alerts from the database, regardless of page number.
- [ ] **Text Search:** Searching for a term (e.g., "Yonge") returns matching records from the full DB, not just the current page.
- [ ] **Date/Time:** Filtering for "Yesterday 2pm-4pm" returns only alerts within that specific window, respecting Toronto time.
- [ ] **Combination:** A complex query (e.g., "Fire" + "Yonge" + "Today") returns the correct intersection of results.
- [ ] **Pagination:** Pagination links (Next/Prev) preserve the current filters (e.g., clicking "Next" keeps `?category=fire`).
- [ ] **Performance:** Full-text search executes efficiently without locking the database.

## 5. Out of Scope
- Real-time websocket updates for *filtered* views (standard polling or refresh is acceptable for this track).
- Advanced geospatial filtering (radius search) - this is handled in a separate track.
