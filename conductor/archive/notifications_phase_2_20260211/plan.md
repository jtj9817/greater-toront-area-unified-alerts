# Implementation Plan - In-App Notification System (Phase 2)

## Phase 1: Static Geofencing & Address Search (Toronto Open Data)

- [x] Task: Database - Cleanup & Setup
    - [x] Create migration to drop unused `geofences` JSON column from `notification_preferences`.
    - [x] Create `SavedPlace` model and migration (user_id, name, lat, long, radius, type).
    - [x] Create `TorontoAddress` model/migration (street_num, street_name, lat, long, zip).
    - [x] Create `TorontoPointOfInterest` model/migration (name, category, lat, long).
    - [x] Create Console Command `data:import-toronto-geospatial` to ingest "Address Points" and "POI" CSV/JSON.
- [x] Task: Backend - Geocoding Service (Local)
    - [x] Create `LocalGeocodingService` to search `TorontoAddress` and `TorontoPointOfInterest` (using `LIKE` or FTS).
    - [x] Implement API endpoint `/api/geocoding/search` for frontend autocomplete.
- [x] Task: Backend - Saved Places Management
    - [x] Implement API endpoints for CRUD operations on `SavedPlace`.
    - [x] Add validation ensuring coordinates are within GTA bounds.
- [x] Task: Frontend - Address Search UI
    - [x] Create `SavedPlacesManager` component.
    - [x] Implement address/POI autocomplete input querying `/api/geocoding/search`.
    - [x] Connect UI to `SavedPlace` CRUD endpoints.
- [x] Task: Notification Engine - Geofence Matching Logic
    - [x] Update `NotificationMatcher` to query `SavedPlace` records instead of the dropped JSON column.
    - [x] Verify Haversine distance calculation works with the new model.
- [x] Task: Conductor - User Manual Verification 'Static Geofencing & Address Search' (Protocol in workflow.md; verified 2026-02-12, log: `storage/logs/manual_tests/notifications_phase_2_static_geofencing_address_search_2026_02_12_072653.log`, script: `tests/manual/verify_notifications_phase_2_static_geofencing_address_search.php`)

## Phase 2: TTC Accessibility & Granular Subscriptions

- [x] Task: Database - Subscriptions Schema
    - [x] Create migration to rename `subscribed_routes` column to `subscriptions` in `notification_preferences`.
    - [x] Update `NotificationPreference` model to cast `subscriptions` as array.
    - [x] Create `config/transit_data.php` containing static lists of Stations, Lines, and major Routes.
- [x] Task: Feed Integration - Refactor TTC Service
    - [x] Refactor `TtcAlertsFeedService` to extract `accessibility` data.
    - [x] Map accessibility data to `Alert` model (Source: `ttc_accessibility`, Type: `elevator`/`escalator`).
- [x] Task: Backend - Subscription Logic (URN System)
    - [x] Implement `AlertContentExtractor` service.
        - [x] Implement Regex matchers for Routes (e.g., `/\b(50[1-8]|3\d{2})\b/`).
        - [x] Implement Keyword matchers for Stations using `config/transit_data.php`.
        - [x] Return array of URNs (e.g., `['route:504', 'station:union']`).
    - [x] Update `NotificationMatcher` to intersect alert URNs with user `subscriptions`.
    - [x] Create endpoint `/api/subscriptions/options` returning data from `config/transit_data.php`.
- [x] Task: Frontend - Accessibility & Subscription UI
    - [x] Update `NotificationSettings` to include "Accessibility Alerts" toggle.
    - [x] Create `SubscriptionManager` component.
        - [x] Implement Tabs: "Routes", "Stations", "Lines".
        - [x] Implement Searchable Multi-Select using URNs as values.
        - [x] Fetch options from `/api/subscriptions/options`.
- [x] Task: Conductor - User Manual Verification 'TTC Accessibility Integration' (Protocol in workflow.md; verified 2026-02-13)

## Phase 3: Automated Data Pruning & Inbox QoL

- [x] Task: Scheduled Job - Prune Old Notifications
    - [x] Create a Laravel Command (e.g., `notifications:prune`).
    - [x] Implement logic to delete `NotificationLog` records older than 30 days.
    - [x] Schedule the command to run daily in `app/Console/Kernel.php` (or `routes/console.php`).
- [x] Task: Backend - Inbox Management API
    - [x] Add API endpoint for "Mark All as Read".
    - [x] Add API endpoint for "Clear All" (soft delete or hide).
- [x] Task: Frontend - Inbox Actions
    - [x] Add "Mark All Read" and "Clear All" buttons to the Notification Center header.
    - [x] Connect buttons to the backend API and update local state optimistically.
- [x] Task: Conductor - User Manual Verification 'Automated Data Pruning & Inbox QoL' (Protocol in workflow.md; verified 2026-02-13, script: `tests/manual/verify_notifications_phase_3_automated_data_pruning_inbox_qol.php`)

## Phase 4: Quality Assurance & Documentation

- [x] Task: Comprehensive Testing
    - [x] Write Feature tests for Geocoding Service (local Toronto Open Data search behavior, auth/validation/limits, special-character hardening).
    - [x] Write Feature tests for Geofence Matching logic (edge cases, boundary conditions, missing coordinates, multi-place match behavior).
    - [x] Write Feature tests for Accessibility Feed parsing and alert triggering.
    - [x] Verify Pruning Command execution and retention policy logic.
- [x] Task: Documentation Update
    - [x] Update `docs/backend/notification-system.md` with current Saved Places + subscriptions architecture, matching flow, and inbox API.
    - [x] Document pruning policy in `docs/backend/maintenance.md`.
    - [x] Update docs registry/API references (`docs/README.md`, inbox `read-all` endpoint surface).
- [x] Task: Conductor - User Manual Verification 'Quality Assurance & Documentation' (Protocol in workflow.md; verified 2026-02-13, script: `tests/manual/verify_notifications_phase_4_quality_documentation.php` -> `tests/manual/verify_notifications_phase_5_quality_documentation.php`, log: `storage/logs/manual_tests/notifications_phase_5_quality_documentation_2026_02_13_191815.log`)
