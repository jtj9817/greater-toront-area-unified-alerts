# Implementation Plan: Toronto Fire Data Integration & Dashboard Refinement

## Phase 1: Code Discovery & Finalization of Tasks
- [x] Task: Inspect existing `TorontoFireFeedService` and `FireIncident` model for schema completeness.
- [x] Task: Audit `AlertService.ts` and React components to identify all mock data dependencies.
- [x] Task: Finalize concrete implementation steps for backend-to-frontend data handoff.
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Discovery' (Protocol in workflow.md)

## Phase 2: Backend - Fire Incident Pipeline
- [~] Task: Finalize `FireIncident` Migration and Model.
    - [ ] Write Migration tests.
    - [ ] Implement/Refine migration and model logic.
- [ ] Task: Refine `TorontoFireFeedService` for production robustness.
    - [ ] Write Resilience tests (Mock XML, Source Down).
    - [ ] Implement XML parsing and incident state management (Active vs. Resolved).
- [ ] Task: Configure Scheduled Ingestion.
    - [ ] Write tests for `FetchFireIncidentsCommand`.
    - [ ] Finalize command logic and scheduling.
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Backend Pipeline' (Protocol in workflow.md)

## Phase 3: Frontend - Inertia Integration & Dashboard Refinement
- [ ] Task: Implement Data Handoff via Laravel Controller.
    - [ ] Write Feature tests for the GTA Alerts route.
    - [ ] Implement Inertia controller logic to provide paginated/filtered incidents.
- [ ] Task: Refactor `AlertService.ts` for Live Data.
    - [ ] Write Unit tests for the refined service.
    - [ ] Replace mock data logic with Inertia prop consumption.
- [ ] Task: Update UI Components for Real-Time Attributes.
    - [ ] Write Component tests for `FeedView` and `AlertCard`.
    - [ ] Refine UI to handle real data types and add "Data Freshness" indicators.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Frontend Integration' (Protocol in workflow.md)

## Phase 4: Verification & Polishing
- [ ] Task: Run full security and quality audit (`composer audit`, `pnpm audit`, `pint`).
- [ ] Task: Verify mobile responsiveness with real-world incident descriptions.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Final Polishing' (Protocol in workflow.md)
