# Track: Scene Intel Feature

## Overview
**Scene Intel** is a feature designed to provide real-time operational updates for fire incidents. It replaces static mock data with a dynamic system that captures, stores, and displays genuine incident progression. The system will derive "synthetic intel" by diffing snapshots of the Toronto Fire CAD feed (e.g., detecting unit arrivals, alarm level changes) and allow for manual intel entry by authorized users.

## Functional Requirements

### 1. Data Ingestion & Processing
*   **Source:** Toronto Fire Services CAD XML Feed (`https://www.toronto.ca/data/fire/livecad.xml`).
*   **Mechanism:** Modify the existing `FetchFireIncidentsJob` to compare the *new* feed snapshot against the *existing* database state for each incident.
*   **Synthetic Intel Generation:**
    *   **Alarm Level Changes:** Detect when `alarm_level` changes (e.g., 1 -> 2). Generate an `ALARM_CHANGE` update.
    *   **Unit Status:**
        *   Compare the `units_dispatched` list (comma-separated string).
        *   Detect **New Units** (present in new feed, absent in DB). Generate `RESOURCE_STATUS` update: "Unit X Dispatched".
        *   Detect **Cleared Units** (present in DB, absent in new feed). Generate `RESOURCE_STATUS` update: "Unit X Cleared".
        *   **Granular Status:** Investigate the XML feed for specific status codes/suffixes per unit. If available, use them to generate more specific updates (e.g., "Arrived", "Transporting"). If not, fall back to "Dispatched"/"Cleared".
    *   **Incident Closure:** Detect when an incident is removed from the active feed. Generate a `PHASE_CHANGE` update: "Incident Marked as Resolved".
*   **Manual Entry:** Provide an API endpoint for authorized users (Admin/Dispatcher) to manually add text-based intel notes (e.g., "Fire is under control").

### 2. Database Schema
*   **New Table:** `incident_updates`
    *   `id` (PK)
    *   `event_num` (FK to `fire_incidents`)
    *   `update_type` (Enum: `milestone`, `resource_status`, `alarm_change`, `phase_change`, `manual_note`)
    *   `content` (Text description)
    *   `metadata` (JSON: stores previous values, unit codes, user ID for manual entries)
    *   `source` (Enum: `synthetic`, `manual`)
    *   `created_at` (Timestamp)

### 3. API Layer
*   **Endpoints:**
    *   `GET /api/incidents/{eventNum}/intel`: Returns the full chronological timeline of updates for a specific incident.
    *   `POST /api/incidents/{eventNum}/intel`: (Auth required) Endpoint to add a manual note.
*   **Integration:** Update the existing `FireAlertSelectProvider` to include a *summary* (latest 3-5 items) of intel in the initial alert payload, reducing the need for an immediate separate fetch.

### 4. Frontend (React/Inertia)
*   **Component:** `SceneIntelTimeline`
    *   Displays updates in a chronological list.
    *   Uses distinct icons/colors for different update types (e.g., Red for Alarm Change, Blue for Resources).
    *   Supports real-time updates via WebSockets.
*   **Integration:**
    *   Replace the hardcoded mock list in `AlertDetailsView.tsx`.
    *   Implement **Real-time Updates** using Laravel Reverb/Pusher (or similar WebSocket solution) to push new `incident_updates` to the client instantly without polling.

## Non-Functional Requirements
*   **Performance:** The diffing logic in `FetchFireIncidentsJob` must be efficient to handle the 5-minute feed update cycle without processing lag.
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
    *   With the dashboard open, if a new update is generated (synthetic or manual), it appears in the `SceneIntelTimeline` *immediately* without a page refresh (via WebSockets).
4.  **UI:**
    *   The `AlertDetailsView` shows the `SceneIntelTimeline` component instead of the static mock list.
    *   Different update types have distinct visual indicators.

## Out of Scope
*   **Predictive Intel:** No estimation of arrival times or future state.
*   **Radio Transcription:** No integration with audio feeds or speech-to-text.
*   **Historical Pruning:** No automated deletion of old intel records (postponed to a future maintenance track).
