# Implementation Plan: In-App Notification System (Phase 1)

## Phase 1: Data Model & Preferences
Establish the database schema and user preference management logic.

- [ ] Task: Create Database Migrations
    - [ ] Create `notification_preferences` table (user_id, alert_type, severity_filters, source_filters, etc.)
    - [ ] Create `notification_logs` table (user_id, alert_id, read_at, dismissed_at, etc.)
- [ ] Task: Implement Models & Factories (TDD)
    - [ ] Create `NotificationPreference` model with relationships and validation
    - [ ] Create `NotificationLog` model with scopes (e.g., `unread()`)
    - [ ] Implement Pest tests for preference validation and log retrieval
- [ ] Task: Preference Management API
    - [ ] Scaffold `NotificationPreferenceController`
    - [ ] Implement `GET /settings/notifications` and `PATCH /settings/notifications`
    - [ ] Write feature tests for updating preferences and severity filters
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Data Model & Preferences' (Protocol in workflow.md)

## Phase 2: Notification Engine & Broadcasting
Implement the logic that matches new alerts to user subscriptions and dispatches them in real-time.

- [ ] Task: Configure Real-time Infrastructure
    - [ ] Set up Laravel Broadcasting (BroadcastServiceProvider)
    - [ ] Configure `reverb` or `pusher` driver in `env.example`
- [ ] Task: Create Alert Notification Event (TDD)
    - [ ] Create `AlertNotificationSent` broadcast event
    - [ ] Write test to verify event payload structure
- [ ] Task: Implement Matcher Engine (TDD)
    - [ ] Create a service to find users matching a new alert's source/route/severity
    - [ ] Implement a Listener to hook into the existing `AlertCreated` or similar lifecycle
    - [ ] Write tests ensuring only "matching" users are queued for notifications
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Notification Engine & Broadcasting' (Protocol in workflow.md)

## Phase 3: Frontend - Settings & Toasts
Build the UI for managing preferences and displaying real-time toasts.

- [ ] Task: Build Notification Settings UI
    - [ ] Create `NotificationSettings` view using Radix UI/Tailwind
    - [ ] Integrate with the Preference API
- [ ] Task: Implement Real-time Toast Component
    - [ ] Install and configure `laravel-echo` and `pusher-js`
    - [ ] Create a persistent `NotificationToast` layout wrapper
    - [ ] Verify toasts appear when receiving a mock WebSocket event
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Frontend - Settings & Toasts' (Protocol in workflow.md)

## Phase 4: Notification Center (Inbox)
Implement the persistent history view for notifications.

- [ ] Task: Build Notification Inbox UI
    - [ ] Create a slide-over or dedicated page for the Notification Center
    - [ ] Implement "Mark as Read" and "Clear All" functionality
- [ ] Task: Inbox API Integration (TDD)
    - [ ] Implement endpoints for marking logs as read/dismissed
    - [ ] Write tests for unauthorized access prevention (users can only modify their own logs)
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Notification Center (Inbox)' (Protocol in workflow.md)

## Phase 5: Quality & Documentation
Final verification and cleanup.

- [ ] Task: Full System Integration Test
    - [ ] Manually verify end-to-end: Create alert -> Receive toast -> See in Inbox -> Mark as read
- [ ] Task: Coverage and Linting Verification
    - [ ] Execute `./vendor/bin/sail artisan test --coverage` (target >90% on new modules)
    - [ ] Run `pint` and `npm run lint`
- [ ] Task: Documentation Update
    - [ ] Update `docs/backend/notification-system.md`
- [ ] Task: Conductor - User Manual Verification 'Phase 5: Quality & Documentation' (Protocol in workflow.md)
