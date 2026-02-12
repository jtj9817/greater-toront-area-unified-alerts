# Ticket: TEST-001 - Enhance Test Coverage for Notifications Phase 2

**Status:** Open
**Priority:** Medium
**Type:** Technical Improvement
**Assignee:** Unassigned
**Component:** Backend / Testing

## Summary
The initial implementation of Notifications Phase 2 (Geospatial Data Import, Local Geocoding, Saved Places) includes foundational "happy path" tests. To ensure system resilience and data integrity, we need to expand the test suite to cover identified edge cases, input boundaries, and error handling scenarios.

## Technical Analysis & Requirements

### 1. Console Command: Geospatial Data Import
**File:** `tests/Feature/Console/ImportTorontoGeospatialDataCommandTest.php`

The current test validates a successful run with the `--truncate` flag but lacks scenarios for data persistence and error states.

**Tasks:**
*   **Verify Truncation Logic:** Update the test to seed the database with "old" data before running the command. Assert that the old data is removed and only new data remains.
*   **Error Handling - File System:** Add test cases for:
    *   Missing input files (ensure command fails gracefully with correct exit code/error message).
    *   Unreadable or invalid file paths.
*   **Error Handling - Data Integrity:** Add test cases for:
    *   Malformed CSV content (missing required columns).
    *   Invalid JSON structure in POI file.
*   **Idempotency/Append Mode:** Test the command *without* the `--truncate` flag. Verify behavior (e.g., does it duplicate records, skip existing ones, or upsert?).

### 2. Controller: Local Geocoding Search
**File:** `tests/Feature/Geocoding/LocalGeocodingSearchControllerTest.php`

Current tests cover authentication and basic name matching. We need to strictly define the API contract and resilience against malformed inputs.

**Tasks:**
*   **Response Contract Verification:** Explicitly assert the structure of the returned JSON objects. Ensure `id`, `lat`, `long`, `type` (address vs. poi), and `zip` (where applicable) are present and correct.
*   **Search Logic Edge Cases:**
    *   **Minimum Query Length:** If the service requires a minimum of 2 or 3 chars, verify that shorter queries return a validation error or empty set, rather than a full table scan.
    *   **Zero Results:** Explicitly test a query string guaranteed to have no matches (e.g., random hash).
    *   **Result Limits:** Seed the database with >20 matching records and verify the API response adheres to the pagination or hard limit (e.g., max 10 results).
*   **Input Sanitization:** Test with query strings containing special characters (e.g., `%`, `_`, `'`, `<script>`) to ensure no SQL injection or unexpected behavior occurs.

### 3. Controller: Saved Places Management
**File:** `tests/Feature/Notifications/SavedPlaceControllerTest.php`

CRUD operations and GTA geospatial bounds are covered. Additional validation layers are needed to prevent bad data states.

**Tasks:**
*   **Radius Boundaries:** Add validation tests for the `radius` field.
    *   Assert failure on negative/zero radius.
    *   Assert failure on excessive radius (e.g., > 100km, if a logic limit exists).
*   **Field Constraints:**
    *   **Name Length:** Verify validation rejects names exceeding column limits (e.g., 255 chars).
    *   **Uniqueness:** Decide and test behavior for duplicate names (e.g., does the user have two places named "Home"?).
*   **Resource Limits (Optional):** If the application imposes a limit on the number of saved places per user (to prevent abuse), implement a test case that hits this limit and asserts the `403` or `422` response.

## Acceptance Criteria
*   New test methods implemented for all tasks listed above.
*   All tests pass (`php artisan test`).
*   No regressions in existing "happy path" tests.
