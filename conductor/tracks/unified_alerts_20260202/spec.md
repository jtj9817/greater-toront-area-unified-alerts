# Track Specification: Unified Alerts Architecture Implementation

## Overview
This track implements the "Provider & Adapter" architecture to unify disparate emergency data sources (Toronto Fire and Toronto Police) into a single, cohesive real-time feed. It transitions the application from a single-source "Fire Incident" view to a robust, extensible "Unified Alert" system that supports true pagination over history and is prepared for future sources (like Transit).

## Functional Requirements
- **Unified Backend DTOs:** Implement `UnifiedAlert` and `AlertLocation` Value Objects as the strict transport shape between backend and frontend.
- **Provider Pattern:** 
    - Define a `AlertSelectProvider` interface.
    - Implement `FireAlertSelectProvider` and `PoliceAlertSelectProvider` to map respective database models to the unified transport shape.
    - Implement a `TransitAlertSelectProvider` placeholder to ensure the system is extensible.
- **Unified Querying:**
    - Implement `UnifiedAlertsQuery` using MySQL UNION queries for read-time unification.
    - Support server-side pagination across the mixed feed.
    - Support filtering by status (`all`, `active`, `cleared`), defaulting to `all` to include history.
    - Ensure deterministic ordering by `timestamp` + tie-breakers (source, external ID).
- **Controller Refactoring:**
    - Update `GtaAlertsController` to use `UnifiedAlertsQuery`.
    - **Hard Switch:** Replace the existing `incidents` prop with the new `alerts` prop (using `UnifiedAlertResource`).
- **Frontend Integration:**
    - Update `resources/js/features/gta-alerts/types.ts` to include `UnifiedAlertResource`.
    - Implement `AlertService.mapUnifiedAlertToAlertItem()` to map the new transport DTO to the existing UI view-model (`AlertItem`).
    - Update the dashboard components to consume the new `alerts` prop.
- **Comprehensive Test Coverage:** 
    - Achieve >90% code coverage for all new and modified backend services, providers, and mappers (Pest PHP).
    - Achieve >90% component/logic coverage for the modified frontend components and the new `AlertService` mapping logic (Vitest/Jest).

## Non-Functional Requirements
- **Low Latency:** The UNION query must remain efficient for real-time dashboard updates.
- **Data Fidelity:** Ensure no data loss or incorrect mapping during the transition from `IncidentResource` to `UnifiedAlertResource`.

## Tech Stack
- **Backend:** Laravel 12.x, PHP 8.4+, MySQL (UNION queries).
- **Frontend:** React 19, Inertia.js, TypeScript.

## Acceptance Criteria
- [ ] The dashboard successfully renders a mixed feed of both Fire incidents and Police calls.
- [ ] Pagination works correctly across the unified feed, including historical (cleared) items.
- [ ] Filtering by "Active" or "Cleared" correctly updates the unified list.
- [ ] The "Hard Switch" is complete: the old `incidents` prop is removed, and all components successfully consume the `alerts` prop.
- [ ] The `TransitAlertSelectProvider` placeholder is in place and verified by tests.
- [ ] **Test Coverage Gate:**
    - Backend: >90% coverage for `UnifiedAlertsQuery`, all `*Provider` classes, and DTOs.
    - Frontend: >90% coverage for `AlertService` (new mapping logic) and updated `FeedView` integration.

## Out of Scope
- Real implementation of the Transit (TTC) scraper (this track only adds the provider placeholder).
- Migration of historical data from external logs (only data currently in the database is unified).
