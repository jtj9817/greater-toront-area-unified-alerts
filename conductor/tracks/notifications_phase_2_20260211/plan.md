# Implementation Plan - In-App Notification System (Phase 2)

## Phase 1: Static Geofencing & Address Search

- [ ] Task: Install & Configure Google Maps/Mapbox SDK
    - [ ] Install necessary PHP/JS libraries for geocoding.
    - [ ] Configure API keys in `.env` and `config/services.php`.
    - [ ] Create a `GeocodingService` wrapper to handle API requests and potential errors.
- [ ] Task: Backend - Saved Places Management
    - [ ] Create `SavedPlace` model and migration (user_id, name, lat, long, radius).
    - [ ] Implement API endpoints for CRUD operations on saved places.
    - [ ] Add request validation for address search and geofence parameters.
- [ ] Task: Frontend - Address Search UI
    - [ ] Create a "Saved Places" settings component.
    - [ ] Implement an address autocomplete input using the geocoding service.
    - [ ] visualizing the selected location on a small map preview (optional but recommended).
    - [ ] Connect the UI to the backend API to save the user's geofence.
- [ ] Task: Notification Engine - Geofence Matching Logic
    - [ ] Update `AlertProcessingEngine` to check incoming alerts against active `SavedPlace` records.
    - [ ] Implement efficient distance calculation (e.g., Haversine formula or database spatial functions).
    - [ ] Trigger notifications when an alert falls within a user's defined radius.
- [ ] Task: Conductor - User Manual Verification 'Static Geofencing & Address Search' (Protocol in workflow.md)

## Phase 2: TTC Accessibility Integration

- [ ] Task: Feed Integration - TTC Elevator/Escalator Status
    - [ ] Create `TtcAccessibilityFeedService` to fetch the external feed.
    - [ ] Parse the feed (XML/JSON) to extract Station, Device, Status, and Return-to-Service time.
    - [ ] Handle API failures and malformed data gracefully (logging errors without crashing).
- [ ] Task: Backend - Accessibility Alerts
    - [ ] Create `AccessibilityAlert` model/migration (or reuse existing Alert structure with specific type).
    - [ ] Implement logic to detect status changes (e.g., In Service -> Out of Service).
    - [ ] Trigger notifications for subscribed users when a relevant status change occurs.
- [ ] Task: Frontend - Accessibility Preferences
    - [ ] Add a section in Notification Settings for "Accessibility Alerts".
    - [ ] Allow users to subscribe to specific stations or lines (optional: global "All Accessibility Alerts").
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
