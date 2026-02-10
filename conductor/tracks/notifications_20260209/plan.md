# Implementation Plan: In-App Notification System (Phase 1 MVP)

This track is aligned to `docs/plans/notification-system-feature-plan.md` Phase 1 MVP scope:
- User preference storage
- In-app real-time delivery
- Simple geofenced alerts
- Severity-based filtering
- Notification center with daily digest

## Phase 1: Data Model & Preferences
Establish schema and preference APIs that match the feature plan fields.

- [x] Task: Create Database Migrations
    - [x] Create `notification_preferences` with: `user_id`, `alert_type`, `severity_threshold`, `geofences`, `subscribed_routes`, `digest_mode`, `push_enabled`, timestamps
    - [x] Create `notification_logs` with: `user_id`, `alert_id`, `delivery_method`, `status`, `sent_at`, `read_at`, `dismissed_at`, `metadata`, timestamps
    - [x] Add indexes for high-read paths (`user_id`, `status`, `sent_at`)
- [x] Task: Implement Models & Factories (TDD)
    - [x] Create `NotificationPreference` model with casts for JSON fields and preference validation rules
    - [x] Create `NotificationLog` model with scopes (`unread()`, `undismissed()`)
    - [x] Implement Pest tests for preference validation and log retrieval
- [x] Task: Preference Management API (TDD)
    - [x] Scaffold `NotificationPreferenceController`
    - [x] Implement `GET /settings/notifications` and `PATCH /settings/notifications`
    - [x] Support route subscriptions, severity threshold, geofences, digest mode, and push toggle
    - [x] Write feature tests for authz and payload validation
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Data Model & Preferences' (Protocol in workflow.md)

## Phase 2: Notification Engine & Broadcasting
Deliver only matching notifications in real-time and prepare digest generation.

- [ ] Task: Configure Real-time Infrastructure
    - [ ] Set up Laravel Broadcasting
    - [ ] Configure `reverb` or `pusher` in `.env.example`
- [ ] Task: Create Alert Notification Event (TDD)
    - [ ] Create `AlertNotificationSent` broadcast event
    - [ ] Include payload fields needed by toast + inbox (`alert_id`, source, severity, summary, sent_at)
    - [ ] Write test to verify event payload structure
- [ ] Task: Implement Matcher Engine (TDD)
    - [ ] Match users by source/route/severity/geofence
    - [ ] Respect `push_enabled` and user opt-outs
    - [ ] Hook matcher into `AlertCreated` (or equivalent) lifecycle
    - [ ] Write tests ensuring only matching users are queued
- [ ] Task: Implement Daily Digest Aggregation (TDD)
    - [ ] Add scheduled job to aggregate daily notifications for `digest_mode = true`
    - [ ] Persist digest entries in `notification_logs` for inbox visibility
    - [ ] Write tests for digest aggregation window and duplicate prevention
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Notification Engine & Broadcasting' (Protocol in workflow.md)

## Phase 3: Frontend - Settings & Toasts
Build the Phase 1 UI controls and real-time in-app delivery UX.

- [ ] Task: Build Notification Settings UI
    - [ ] Create `NotificationSettings` UI with controls for source/route filters
    - [ ] Add severity threshold selector
    - [ ] Add simple geofence editor (initial zone list or radius presets)
    - [ ] Add digest mode and push toggle controls
    - [ ] Integrate with preference API
- [ ] Task: Implement Real-time Toast Component
    - [ ] Install and configure `laravel-echo` and `pusher-js`
    - [ ] Create persistent `NotificationToast` layout wrapper
    - [ ] Verify toasts appear for matching mock WebSocket events only
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Frontend - Settings & Toasts' (Protocol in workflow.md)

## Phase 4: Notification Center (Inbox)
Implement persistent history with read/dismiss plus digest visibility.

- [ ] Task: Build Notification Inbox UI
    - [ ] Create slide-over or dedicated Notification Center page
    - [ ] Show individual alerts and daily digest items
    - [ ] Implement "Mark as Read", "Dismiss", and "Clear All"
- [ ] Task: Inbox API Integration (TDD)
    - [ ] Implement endpoints for listing logs and marking read/dismissed
    - [ ] Enforce ownership checks (users can only modify their own logs)
    - [ ] Write feature tests for unauthorized access prevention
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Notification Center (Inbox)' (Protocol in workflow.md)

## Phase 5: Quality & Documentation
Complete end-to-end validation for the aligned Phase 1 MVP.

- [ ] Task: Full System Integration Test
    - [ ] Verify flow: Create matching alert -> receive toast -> appears in inbox -> mark as read
    - [ ] Verify non-matching geofence alert does not toast
    - [ ] Verify digest user receives daily digest item
- [ ] Task: Coverage and Linting Verification
    - [ ] Execute `composer test`
    - [ ] Run `composer lint` and `pnpm run lint`
- [ ] Task: Documentation Update
    - [ ] Update `docs/backend/notification-system.md` with schema, matching rules, and digest behavior
- [ ] Task: Conductor - User Manual Verification 'Phase 5: Quality & Documentation' (Protocol in workflow.md)
