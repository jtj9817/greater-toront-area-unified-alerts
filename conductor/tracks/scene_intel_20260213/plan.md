# Implementation Plan - Scene Intel Feature

## Phase 1: Database & Models (TDD)
- [ ] Task: Create `incident_updates` migration and model
    - [ ] Create migration file with `event_num`, `update_type`, `content`, `metadata` (JSON), `source`, `created_by`
    - [ ] Create `IncidentUpdate` model with casted attributes and relationships (`fireIncident`, `creator`)
    - [ ] Create `IncidentUpdateType` Enum with `MILESTONE`, `RESOURCE_STATUS`, `ALARM_CHANGE`, `PHASE_CHANGE`, `MANUAL_NOTE` cases
    - [ ] Test: Verify model creation, relationships, and JSON casting
- [ ] Task: Create `SceneIntelRepository`
    - [ ] Implement `getLatestForIncident(eventNum, limit)`
    - [ ] Implement `addManualEntry(eventNum, content, userId, metadata)`
    - [ ] Test: Verify repository methods with factory data
- [ ] Task: Update `FireIncident` model
    - [ ] Add `hasMany` relationship to `IncidentUpdate`
    - [ ] Test: Verify relationship
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Database & Models' (Protocol in workflow.md)

## Phase 2: Synthetic Intel Generation (TDD)
- [ ] Task: Create `SceneIntelProcessor` Service
    - [ ] Implement `processIncidentUpdate(incident, previousData)`
    - [ ] Implement detection logic for `ALARM_CHANGE` (up/down)
    - [ ] Implement diffing logic for `RESOURCE_STATUS` (dispatched/cleared units)
    - [ ] Implement detection logic for `PHASE_CHANGE` (incident closure)
    - [ ] Test: Verify processor correctly generates `IncidentUpdate` records for various scenarios (alarm level up, unit added/removed, incident closed)
- [ ] Task: Integrate with `FetchFireIncidentsCommand`
    - [ ] Inject `SceneIntelProcessor` into the command
    - [ ] Modify loop to capture `previousData` before `updateOrCreate`
    - [ ] Call `processIncidentUpdate` after successful update
    - [ ] Test: Run command with mocked feed data and verify `incident_updates` are created
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Synthetic Intel Generation' (Protocol in workflow.md)

## Phase 3: API & Real-time (TDD)
- [ ] Task: API Endpoints
    - [ ] Create `SceneIntelController`
    - [ ] Implement `timeline(eventNum)` endpoint (GET)
    - [ ] Implement `store(eventNum)` endpoint (POST) with validation and authorization check
    - [ ] Define routes in `routes/web.php` or `routes/api.php`
    - [ ] Test: Verify endpoints return correct JSON structure and enforce auth
- [ ] Task: Real-time Broadcasting (Reverb/Pusher)
    - [ ] Create `IncidentUpdated` event (implements `ShouldBroadcast`)
    - [ ] Dispatch event from `SceneIntelProcessor` (for synthetic) and `SceneIntelController` (for manual)
    - [ ] Configure channel routes (private/public as needed)
    - [ ] Test: Verify event is broadcasted with correct data payload
- [ ] Task: Conductor - User Manual Verification 'Phase 3: API & Real-time' (Protocol in workflow.md)

## Phase 4: Frontend Implementation
- [ ] Task: Update Frontend Domain
    - [ ] Update `FireMetaSchema` in `resources/js/features/gta-alerts/domain/alerts/fire/schema.ts` to include `intel_summary`
    - [ ] Create `SceneIntelItem` type definition
- [ ] Task: Create `SceneIntelTimeline` Component
    - [ ] Create component with list layout
    - [ ] Implement distinct styles/icons for `update_type` (Enum mapping)
    - [ ] Implement "Live" indicator for real-time updates
- [ ] Task: Integrate with `AlertDetailsView`
    - [ ] Replace hardcoded mock list with `SceneIntelTimeline`
    - [ ] Connect to `useSceneIntel` hook (or similar) for data fetching
    - [ ] Implement Echo listener for real-time updates to append to list
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Frontend Implementation' (Protocol in workflow.md)
