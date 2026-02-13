# Track: Scene Intel Feature

## Overview
**Scene Intel** is a feature designed to provide real-time operational updates for fire incidents. It replaces static mock data with a dynamic system that captures, stores, and displays genuine incident progression. The system will derive "synthetic intel" by diffing snapshots of the Toronto Fire CAD feed (e.g., detecting unit arrivals, alarm level changes) and allow for manual intel entry by authorized users.

This track is based on `docs/plans/scene-intel-feature-plan.md` and is written to align with the current repo architecture:
- Incidents are synced via `app/Console/Commands/FetchFireIncidentsCommand.php`, scheduled from `routes/console.php`.
- The dashboard page (`/`) is public (`routes/web.php`), so read-only intel must be retrievable without requiring authentication (manual entry remains restricted).
- The primary runtime database is MySQL (see `compose.yaml`), with a separate MySQL service available for testing.

## Functional Requirements

### 1. Data Ingestion & Processing
*   **Source:** Toronto Fire Services CAD XML Feed (`https://www.toronto.ca/data/fire/livecad.xml`).
*   **Mechanism:** Modify `FetchFireIncidentsCommand` to compare the *new* feed snapshot against the *existing* database state for each incident. (`FetchFireIncidentsJob` is a thin Artisan wrapper; the command is the true integration point.)
*   **Synthetic Intel Generation:**
    *   **Alarm Level Changes:** Detect when `alarm_level` changes (e.g., 1 -> 2). Generate an `ALARM_CHANGE` update.
    *   **Unit Status:**
        *   Compare the `units_dispatched` list (comma-separated string).
        *   Detect **New Units** (present in new feed, absent in DB). Generate `RESOURCE_STATUS` update: "Unit X Dispatched".
        *   Detect **Cleared Units** (present in DB, absent in new feed). Generate `RESOURCE_STATUS` update: "Unit X Cleared".
        *   **Granular Status (Deferred):** The current feed parsing only exposes unit assignment, not per-unit state. If the XML feed is found to include unit suffixes/status codes, this can be promoted to a richer status model later; for v1, remain limited to dispatched/cleared.
    *   **Incident Closure:** When the sync deactivates incidents no longer present in the active feed, generate a `PHASE_CHANGE` update: "Incident marked as resolved".
        *   Note: the command currently performs a bulk deactivation; closure intel generation must explicitly process the deactivated set, not only "after updateOrCreate".
    *   **Feed Jitter Guard:** Synthetic generation should be resilient to brief feed anomalies (e.g., a unit or incident disappearing for a single poll). At minimum, avoid generating duplicate closures for the same incident.
*   **Manual Entry:** Provide an API endpoint for authorized users to manually add text-based intel notes (e.g., "Fire is under control").
    *   Authorization: repo currently has no dispatcher/admin role model; implement initial enforcement via an explicit Policy/Gate (details in the implementation plan), and keep the endpoint behind `auth` + `verified`.

### 2. Database Schema
*   **New Table:** `incident_updates`
    *   `id` (PK)
    *   `event_num` (FK to `fire_incidents`)
    *   `update_type` (Enum: `milestone`, `resource_status`, `alarm_change`, `phase_change`, `manual_note`)
    *   `content` (Text description)
    *   `metadata` (JSON: stores previous values, unit codes, user ID for manual entries)
    *   `source` (Enum: `synthetic`, `manual`)
    *   `created_by` (nullable FK to `users`)
    *   `created_at`, `updated_at` (timestamps)
    *   Indexes: at minimum `(event_num, created_at)` plus any additional indexes needed for query patterns.

### 3. API Layer
*   **Endpoints:**
    *   `GET /api/incidents/{eventNum}/intel`: Returns the full chronological timeline of updates for a specific incident.
        *   Read-only and safe for the public dashboard; keep this endpoint public (rate-limit if needed).
    *   `POST /api/incidents/{eventNum}/intel`: (Auth + verified + authorization) Adds a manual note.
*   **Integration (Optional Optimization):** Extend `FireAlertSelectProvider` to include an `intel_summary` (latest 3-5 items) inside the unified alert meta. This reduces the "blank" state before the timeline fetch completes, but must be implemented carefully to avoid expensive per-row subqueries at scale.
    *   Include `intel_last_updated` as an ISO timestamp when embedding `intel_summary` so the UI can display freshness without an extra request.

### 4. Frontend (React/Inertia)
*   **Component:** `SceneIntelTimeline`
    *   Displays updates in a chronological list.
    *   Uses distinct icons/colors for different update types (e.g., Red for Alarm Change, Blue for Resources).
    *   v1 supports polling-based updates; WebSockets are a future enhancement.
*   **Integration:**
    *   Replace the hardcoded mock list in `AlertDetailsView.tsx`.
    *   Fetch timeline via `GET /api/incidents/{eventNum}/intel` and poll periodically for active incidents (e.g., 30s).
    *   If `intel_summary` is present in the initial alert payload, use it as an initial render seed, then reconcile with the fetched timeline.

## Non-Functional Requirements
*   **Performance:** The diffing logic in `FetchFireIncidentsCommand` must be efficient to handle the 5-minute feed update cycle without processing lag.
*   **Scalability:** The system should handle the accumulation of incident updates without significant degradation in query performance (indexing on `event_num` and `created_at`).
*   **Reliability:** Synthetic generation must be robust against feed jitter (temporary disappearance of units).

## Acceptance Criteria
1.  **Synthetic Generation:**
    *   When the feed shows an alarm level increase, a corresponding "Alarm Level Change" item appears in the timeline.
    *   When a new unit appears in the feed, a "Unit Dispatched" item appears.
    *   When a unit disappears from the feed, a "Unit Cleared" item appears.
2.  **Manual Entry:**
    *   An authorized user can post a note via API, and it appears in the timeline.
3.  **Real-time:**
    *   With the dashboard open, if a new update is generated (synthetic or manual), it appears in the `SceneIntelTimeline` without a page refresh (via polling).
    *   WebSocket-driven "immediate" updates are a future enhancement.
4.  **UI:**
    *   The `AlertDetailsView` shows the `SceneIntelTimeline` component instead of the static mock list.
    *   Different update types have distinct visual indicators.

## Out of Scope
*   **Predictive Intel:** No estimation of arrival times or future state.
*   **Radio Transcription:** No integration with audio feeds or speech-to-text.
*   **Synthetic Tactical Milestones:** Detailed milestones like "primary search complete" are not derivable from the CAD snapshot and are out of scope for synthetic generation (they may still be captured via manual entry if authorized users provide them).
