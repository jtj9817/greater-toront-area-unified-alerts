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
- [ ] Task: Conductor - User Manual Verification 'Static Geofencing & Address Search' (Protocol in workflow.md)

## Phase 2: TTC Accessibility & Granular Subscriptions

- [ ] Task: Database - Subscriptions Schema
    - [ ] Create migration to rename `subscribed_routes` column to `subscriptions` in `notification_preferences`.
    - [ ] Update `NotificationPreference` model to cast `subscriptions` as array.
    - [ ] Create `config/transit_data.php` containing static lists of Stations, Lines, and major Routes.
- [ ] Task: Feed Integration - Refactor TTC Service
    - [ ] Refactor `TtcAlertsFeedService` to extract `accessibility` data.
    - [ ] Map accessibility data to `Alert` model (Source: `ttc_accessibility`, Type: `elevator`/`escalator`).
- [ ] Task: Backend - Subscription Logic (URN System)
    - [ ] Implement `AlertContentExtractor` service.
        - [ ] Implement Regex matchers for Routes (e.g., `/\b(50[1-8]|3\d{2})\b/`).
        - [ ] Implement Keyword matchers for Stations using `config/transit_data.php`.
        - [ ] Return array of URNs (e.g., `['route:504', 'station:union']`).
    - [ ] Update `NotificationMatcher` to intersect alert URNs with user `subscriptions`.
    - [ ] Create endpoint `/api/subscriptions/options` returning data from `config/transit_data.php`.
- [ ] Task: Frontend - Accessibility & Subscription UI
    - [ ] Update `NotificationSettings` to include "Accessibility Alerts" toggle.
    - [ ] Create `SubscriptionManager` component.
        - [ ] Implement Tabs: "Routes", "Stations", "Lines".
        - [ ] Implement Searchable Multi-Select using URNs as values.
        - [ ] Fetch options from `/api/subscriptions/options`.
- [ ] Task: Conductor - User Manual Verification 'TTC Accessibility Integration' (Protocol in workflow.md)

## Phase 3: Automated Data Pruning & Inbox QoL

- [ ] Task: Scheduled Job - Prune Old Notifications
    - [ ] Create a Laravel Command (e.g., `notifications:prune`).
    - [ ] Implement logic to delete `NotificationLog` records older than 30 days.
    - [ ] Schedule the command to run daily in `app/Console/Kernel.php` (or `routes/console.php`).
- [ ] Task: Backend - Inbox Management API
    - [ ] Add API endpoint for "Mark All as Read".
    - [ ] Add API endpoint for "Clear All" (soft delete or hide).
- [ ] Task: Frontend - Inbox Actions
    - [ ] Add "Mark All Read" and "Clear All" buttons to the Notification Center header.
    - [ ] Connect buttons to the backend API and update local state optimistically.
- [ ] Task: Conductor - User Manual Verification 'Automated Data Pruning & Inbox QoL' (Protocol in workflow.md)

## Phase 4: Quality Assurance & Documentation

- [ ] Task: Comprehensive Testing
    - [ ] Write Feature tests for Geocoding Service (mocking the external API).
    - [ ] Write Feature tests for Geofence Matching logic (edge cases, boundary conditions).
    - [ ] Write Feature tests for Accessibility Feed parsing and alert triggering.
    - [ ] Verify Pruning Command execution and retention policy logic.
- [ ] Task: Documentation Update
    - [ ] Update `docs/backend/notifications.md` (or similar) with details on Geofencing and Accessibility integration.
    - [ ] Document the Pruning policy in `docs/backend/maintenance.md`.
    - [ ] Update API documentation if applicable.
- [ ] Task: Conductor - User Manual Verification 'Quality Assurance & Documentation' (Protocol in workflow.md)
