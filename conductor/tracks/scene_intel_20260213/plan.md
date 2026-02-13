# Implementation Plan - Scene Intel Feature

## Phase 1: Database & Models (TDD)
- [x] Task: Create `incident_updates` migration and model
    - [x] Create migration file with `event_num`, `update_type`, `content`, `metadata` (JSON), `source`, `created_by`, timestamps, and indexes
    - [x] Add FK: `incident_updates.event_num` -> `fire_incidents.event_num` (unique in current schema) with `ON DELETE CASCADE`
    - [x] Create `IncidentUpdate` model with casted attributes and relationships (`fireIncident`, `creator`)
    - [x] Create `IncidentUpdateType` Enum with v1 cases: `MILESTONE`, `RESOURCE_STATUS`, `ALARM_CHANGE`, `PHASE_CHANGE`, `MANUAL_NOTE`
        - [x] Note: `SAFETY_NOTICE` / `WEATHER_ALERT` are defined in the basis plan but are deferred unless needed by an additional data source.
    - [x] Test: Verify model creation, relationships, and JSON casting
- [x] Task: Create `SceneIntelRepository`
    - [x] Implement `getLatestForIncident(eventNum, limit)`
    - [x] Implement `getTimeline(eventNum)` (chronological ascending)
    - [x] Implement `getSummaryForIncident(eventNum, limit)` (for optional `intel_summary` embedding)
    - [x] Implement `addManualEntry(eventNum, content, userId, metadata)`
    - [x] Test: Verify repository methods with factory data
- [x] Task: Update `FireIncident` model
    - [x] Add `hasMany` relationship to `IncidentUpdate`
    - [x] Test: Verify relationship
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Database & Models' (Protocol in workflow.md)

## Phase 2: Synthetic Intel Generation (TDD)
- [ ] Task: Create `SceneIntelProcessor` Service
    - [ ] Implement `processIncidentUpdate(incident, previousData)`
    - [ ] Implement detection logic for `ALARM_CHANGE` (up/down)
    - [ ] Implement diffing logic for `RESOURCE_STATUS` (dispatched/cleared units)
    - [ ] Implement detection logic for `PHASE_CHANGE` (incident closure)
    - [ ] Test: Verify processor correctly generates `IncidentUpdate` records for various scenarios (alarm level up, unit added/removed, incident closed)
- [ ] Task: Integrate with `FetchFireIncidentsCommand`
    - [ ] Update the command (not the job wrapper) to capture `previousData` before `updateOrCreate`
    - [ ] Call `processIncidentUpdate` after successful update
    - [ ] Implement closure intel generation for deactivated incidents
        - [ ] The command currently bulk-updates `is_active=false` via `whereNotIn`; closure intel must explicitly process the deactivated set.
        - [ ] Add jitter guard (avoid duplicate closure entries)
    - [ ] Test: Run command with mocked feed data and verify `incident_updates` are created for alarm/unit changes and deactivations
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Synthetic Intel Generation' (Protocol in workflow.md)

## Phase 3: API (Polling-First) (TDD)
- [ ] Task: API Endpoints
    - [ ] Create `SceneIntelController`
    - [ ] Implement `timeline(eventNum)` endpoint (GET)
    - [ ] Implement `store(eventNum)` endpoint (POST) with validation and authorization check
    - [ ] Define routes (this repo has no `routes/api.php`)
        - [ ] Public: `GET /api/incidents/{eventNum}/intel` in `routes/web.php` (read-only for the public dashboard)
        - [ ] Protected: `POST /api/incidents/{eventNum}/intel` in `routes/settings.php` behind `auth` + `verified` + Policy/Gate
    - [ ] Test: Verify endpoints return correct JSON structure and enforce auth
- [ ] Task: Authorization Strategy For Manual Entry
    - [ ] Implement a Policy/Gate for creating manual intel entries (repo does not currently model dispatcher/admin roles)
    - [ ] Document the initial rule (e.g., verified users only, or explicit allowlist) and keep it easy to tighten later
- [ ] Task: Conductor - User Manual Verification 'Phase 3: API (Polling-First)' (Protocol in workflow.md)

## Phase 4: Frontend Implementation
- [ ] Task: Update Frontend Domain
    - [ ] Add `SceneIntelItem` type definition (and Zod schema) for API responses
    - [ ] Extend `FireMetaSchema` to optionally include `intel_summary` (default empty array) and `intel_last_updated` (nullable ISO string) for the optimization path
- [ ] Task: Create `SceneIntelTimeline` Component
    - [ ] Create component with list layout
    - [ ] Implement distinct styles/icons for `update_type` (Enum mapping)
    - [ ] Implement "Live" indicator for polling freshness (WebSocket "instant" is deferred)
- [ ] Task: Integrate with `AlertDetailsView`
    - [ ] Replace hardcoded mock list with `SceneIntelTimeline`
    - [ ] Add a `useSceneIntel` hook (or similar) using `fetch` (current repo does not use React Query)
    - [ ] Poll timeline for active incidents (e.g., every 30 seconds)
    - [ ] Seed initial UI from `intel_summary` if present, then reconcile with fetched timeline
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Frontend Implementation' (Protocol in workflow.md)

## Phase 5: Backend Optimization (Optional)
- [ ] Task: Embed `intel_summary` In Fire Meta (MySQL-first)
    - [ ] Update `app/Services/Alerts/Providers/FireAlertSelectProvider.php` to include `intel_summary` (latest 3-5 updates)
    - [ ] Include `intel_last_updated` derived from the newest `incident_updates.created_at` for the incident
    - [ ] Keep implementation compatible with the test database strategy (MySQL in `compose.yaml`; avoid relying on SQLite-only JSON behavior)
    - [ ] Load/perf check: ensure the unified alerts query remains acceptable under expected traffic

## Phase 6: Maintenance
- [ ] Task: Scene Intel Pruning
    - [ ] Add `scene-intel:prune --days=90` command
    - [ ] Schedule in `routes/console.php`
    - [ ] Document in `docs/backend/maintenance.md` (if present), consistent with the basis plan

## Phase 7: Quality & Documentation
Final verification and documentation maintenance for the shipped v1 scope.

- [ ] Task: Coverage and Linting Verification
    - [ ] Execute `composer test`
    - [ ] Execute `pnpm run quality:check`
- [ ] Task: Documentation Update
    - [ ] Update `docs/backend/maintenance.md` to include Scene Intel pruning policy and verification steps
    - [ ] Add `docs/backend/scene-intel.md` covering schema, synthetic generation, and API endpoints
    - [ ] Update `docs/frontend/types.md` to describe the Scene Intel frontend contract
- [ ] Task: Conductor - User Manual Verification 'Phase 7: Quality & Documentation' (Protocol in workflow.md)

## Future Enhancements (Not In v1)
- [ ] Real-time Broadcasting (Reverb/Pusher)
    - [ ] Create broadcast event for new intel entries and push to clients via Echo
    - [ ] Add channel authorization and client subscription strategy
