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
- [x] Task: Create `SceneIntelProcessor` Service
    - [x] Implement `processIncidentUpdate(incident, previousData)`
    - [x] Implement detection logic for `ALARM_CHANGE` (up/down)
    - [x] Implement diffing logic for `RESOURCE_STATUS` (dispatched/cleared units)
    - [x] Implement detection logic for `PHASE_CHANGE` (incident closure)
    - [x] Test: Verify processor correctly generates `IncidentUpdate` records for various scenarios (alarm level up, unit added/removed, incident closed)
- [x] Task: Integrate with `FetchFireIncidentsCommand`
    - [x] Update the command (not the job wrapper) to capture `previousData` before `updateOrCreate`
    - [x] Call `processIncidentUpdate` after successful update
    - [x] Implement closure intel generation for deactivated incidents
        - [x] The command currently bulk-updates `is_active=false` via `whereNotIn`; closure intel must explicitly process the deactivated set.
        - [x] Add jitter guard (avoid duplicate closure entries)
    - [x] Test: Run command with mocked feed data and verify `incident_updates` are created for alarm/unit changes and deactivations
- [x] Task: Conductor - User Manual Verification 'Phase 2: Synthetic Intel Generation' (Protocol in workflow.md)

## Phase 3: API (Polling-First) (TDD)
- [x] Task: API Endpoints
    - [x] Create `SceneIntelController`
    - [x] Implement `timeline(eventNum)` endpoint (GET)
    - [x] Implement `store(eventNum)` endpoint (POST) with validation and authorization check
    - [x] Define routes (this repo has no `routes/api.php`)
        - [x] Public: `GET /api/incidents/{eventNum}/intel` in `routes/web.php` (read-only for the public dashboard)
        - [x] Protected: `POST /api/incidents/{eventNum}/intel` in `routes/settings.php` behind `auth` + `verified` + Policy/Gate
    - [x] Test: Verify endpoints return correct JSON structure and enforce auth
- [x] Task: Authorization Strategy For Manual Entry
    - [x] Implement a Policy/Gate for creating manual intel entries (repo does not currently model dispatcher/admin roles)
    - [x] Document the initial rule (e.g., verified users only, or explicit allowlist) and keep it easy to tighten later
- [x] Task: Conductor - User Manual Verification 'Phase 3: API (Polling-First)' (Protocol in workflow.md)

## Phase 4: Frontend Implementation
- [x] Task: Update Frontend Domain
    - [x] Add `SceneIntelItem` type definition (and Zod schema) for API responses
    - [x] Extend `FireMetaSchema` to optionally include `intel_summary` (default empty array) and `intel_last_updated` (nullable ISO string) for the optimization path
- [x] Task: Create `SceneIntelTimeline` Component
    - [x] Create component with list layout
    - [x] Implement distinct styles/icons for `update_type` (Enum mapping)
    - [x] Implement "Live" indicator for polling freshness (WebSocket "instant" is deferred)
- [x] Task: Integrate with `AlertDetailsView`
    - [x] Replace hardcoded mock list with `SceneIntelTimeline`
    - [x] Add a `useSceneIntel` hook (or similar) using `fetch` (current repo does not use React Query)
    - [x] Poll timeline for active incidents (e.g., every 30 seconds)
    - [x] Seed initial UI from `intel_summary` if present, then reconcile with fetched timeline
- [x] Task: Conductor - User Manual Verification 'Phase 4: Frontend Implementation' (Protocol in workflow.md) [`8ffc060`]

## Phase 5: Optimization & Hardening
- [x] Task: Embed `intel_summary` In Fire Alert Selection [`b5a98f6`]
    - [x] Extend `FireAlertSelectProvider` query to include `intel_summary` column
        - [x] Implement efficient subquery or JSON aggregation to fetch latest 3 updates
        - [x] Ensure JSON compatibility for both SQLite (testing) and MySQL (production)
    - [x] Add `intel_last_updated` column (max `created_at` from updates)
    - [x] Align columns in `Police` and `Transit` providers (add `NULL` or empty JSON placeholders) to satisfy `UNION` requirements
- [x] Task: Frontend Integration of Embedded Data [`b5a98f6`]
    - [x] Update `AlertService.ts` to consume `intel_summary` from the main feed
    - [x] Update `AlertDetailsView` to initialize from `intel_summary` before first poll
- [x] Task: Conductor - User Manual Verification 'Phase 5: Optimization & Hardening' (Protocol in workflow.md; verified 2026-02-14, script: `tests/manual/verify_scene_intel_phase_5_optimization_hardening.php`, log: `storage/logs/manual_tests/scene_intel_phase_5_optimization_hardening_2026_02_14_214049.log`)

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
