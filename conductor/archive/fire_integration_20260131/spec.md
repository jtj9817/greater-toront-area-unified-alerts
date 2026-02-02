# Specification: Toronto Fire Data Integration & Dashboard Refinement

## 1. Overview
This track transitions the GTA Alerts dashboard from mock data to real-time data sourced from the Toronto Fire Services CAD feed. It involves refining the backend synchronization logic, finalizing the database schema, and integrating the frontend via Inertia.js.

## 2. Technical Objectives
- **Data Fidelity:** Ensure the `FireIncident` model accurately represents all fields from the Toronto Fire XML feed.
- **Sync Reliability:** Implement a robust synchronization service that handles incident updates and resolutions (marking incidents inactive).
- **Frontend Integration:** Refactor `AlertService.ts` to consume data passed from Laravel controllers via Inertia, removing dependency on `constants.ts`.
- **UX/UI:** Implement "Data Freshness" indicators and ensure the "Calm Urgency" design principles are applied to real-time updates.

## 3. Key Components
- **Backend:** `TorontoFireFeedService`, `FetchFireIncidentsCommand`, `FireIncident` Model.
- **Frontend:** `resources/js/features/gta-alerts/`, `AlertService.ts`, `FeedView.tsx`.
- **Communication:** Inertia.js Shared Data or Page Props.

## 4. Acceptance Criteria
- [ ] Active fire incidents are successfully fetched and stored in MySQL.
- [ ] Inactive incidents are correctly identified and archived/hidden from the live feed.
- [ ] The dashboard displays real incidents with correct location and timestamp data.
- [ ] 90% test coverage for all new/modified PHP and TypeScript logic.
- [ ] No security vulnerabilities introduced in the data pipeline.
