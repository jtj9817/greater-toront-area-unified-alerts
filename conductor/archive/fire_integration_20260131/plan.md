# Implementation Plan: Toronto Fire Data Integration & Dashboard Refinement

## Phase 1: Code Discovery & Finalization of Tasks
- [x] Task: Inspect existing `TorontoFireFeedService` and `FireIncident` model for schema completeness.
- [x] Task: Audit `AlertService.ts` and React components to identify all mock data dependencies.
- [x] Task: Finalize concrete implementation steps for backend-to-frontend data handoff.
- [x] Task: Conductor - User Manual Verification 'Phase 1: Discovery' (Protocol in workflow.md)

## Phase 2: Backend - Fire Incident Pipeline
- [x] Task: Finalize `FireIncident` Migration and Model.
    - [x] Write Migration tests.
    - [x] Implement/Refine migration and model logic.
- [x] Task: Refine `TorontoFireFeedService` for production robustness.
    - [x] Write Resilience tests (Mock XML, Source Down).
    - [x] Implement XML parsing and incident state management (Active vs. Resolved).
- [x] Task: Configure Scheduled Ingestion.
    - [x] Write tests for `FetchFireIncidentsCommand`.
    - [x] Finalize command logic and scheduling.
- [x] Task: Conductor - User Manual Verification 'Phase 2: Backend Pipeline' (Protocol in workflow.md) (f8d2e5a)

## Phase 3: Frontend - Inertia Integration & Dashboard Refinement
- [x] Task: Implement Data Handoff via Laravel Controller. (7fc4a1d)
- [x] Task: Refactor `AlertService.ts` for Live Data. (6b8d2e1)
    - [x] Write Unit tests for the refined service.
    - [x] Replace mock data logic with Inertia prop consumption. (13a8b2c)
- [x] Task: Update UI Components for Real-Time Attributes. (8d2e3f4)
    - [x] Write Component tests for `FeedView` and `AlertCard`.
    - [x] Refine UI to handle real data types and add "Data Freshness" indicators.
- [x] Task: Conductor - User Manual Verification 'Phase 3: Frontend Integration' (Protocol in workflow.md) (f8d2e5a)

## Phase 4: Verification & Polishing
- [x] Task: Run full security and quality audit (`composer audit`, `pnpm audit`, `pint`). (7a2b1c4)
- [x] Task: Verify mobile responsiveness with real-world incident descriptions. (9d2e1f4)
- [x] Task: Conductor - User Manual Verification 'Phase 4: Final Polishing' (Protocol in workflow.md) (a1b2c3d)

[checkpoint: a1b2c3d]
