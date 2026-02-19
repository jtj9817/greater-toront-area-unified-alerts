# Changelog

All notable documentation-relevant changes are tracked here.

## [February 18, 2026] - Feed Tickets, Notification Fan-Out, and Observability Additions

### Added
- Created four open feed improvement tickets in `docs/tickets/`:
  - `FEED-001-server-side-filters-infinite-scroll.md` - Move category/search/time filters server-side, replace pagination with cursor-based infinite scroll
  - `FEED-002-real-time-push.md` - Real-time push updates for the alert feed (depends on FEED-001)
  - `FEED-003-saved-filter-presets.md` - Saved filter presets (depends on FEED-001)
  - `FEED-004-sort-direction-toggle.md` - Sort direction toggle (depends on FEED-001)
- Archived 16 closed tickets from `docs/tickets/` to `docs/tickets/archive/`.
- Updated `docs/backend/notification-system.md`:
  - Expanded architecture diagram to reflect the three-stage fan-out pipeline: `FanOutAlertNotificationsJob` -> `DispatchAlertNotificationChunkJob` (250 users/chunk) -> `DeliverAlertNotificationJob`
  - Added "Fan-Out Pipeline" explanation section
  - Added `SavedPlace` CRUD API endpoints (`GET/POST/PATCH/DELETE /api/saved-places`) including 20-place maximum limit
  - Added `SubscriptionOptions` API endpoint (`GET /api/subscriptions/options`) with response shape
  - Expanded file reference list with `FanOutAlertNotificationsJob`, `DispatchAlertNotificationChunkJob`, `SavedPlaceController`, `SubscriptionOptionsController`, `LocalGeocodingSearchController`, `LocalGeocodingService`, `NotificationAlertFactory`, `NotificationSeverity`, and all geocoding/saved-place models
  - Expanded test file references with all Notification feature test files
- Updated `docs/backend/enums.md`:
  - Added `IncidentUpdateType` enum documentation (`milestone`, `resource_status`, `alarm_change`, `phase_change`, `manual_note`)
  - Added frontend equivalent for Scene Intel update types
- Updated `docs/backend/unified-alerts-system.md`:
  - Added `subscription_route_options` to the controller props list
- Updated `docs/README.md`:
  - Added `backend/scene-intel.md` to the documentation structure tree
  - Added `plans/notification-system-feature-plan.md`, `plans/scene-intel-feature-plan.md`, `plans/frontend-typed-alert-domain-plan.md` to plan docs listing
  - Added `tickets/` directory to the documentation structure tree
  - Added Scene Intel to the Implementation Status table
  - Added FEED-001/FEED-002 planned items to the Implementation Status table
  - Added "Open Tickets" section
  - Added `backend/scene-intel.md` to the Recommended Reading Order

### Changed
- `GtaAlertsController` now passes `subscription_route_options` (sorted route IDs from `config/transit_data.php`) as an Inertia prop, sourced from the backend rather than hardcoded in the frontend.
- Notification delivery pipeline refactored to fan-out: `DispatchAlertNotifications` listener now dispatches `FanOutAlertNotificationsJob`, which chunks matching users into groups of 250 before dispatching per-chunk and per-user delivery jobs.
- `useSceneIntel` hook updated to prevent overlapping poll requests (in-flight guard added).

### Fixed
- Scene intel GET endpoint (`GET /api/incidents/{eventNum}/intel`) now has `throttle:60,1` rate limiting applied.
- `FetchTransitAlertsCommand` now trims whitespace from `external_id` before persisting, preventing duplicate-key issues from padding differences.
- `QueueEnqueueDebugServiceProvider` added for queue enqueue debug logging (controlled by `QUEUE_ENQUEUE_DEBUG_ENABLED` env var).

## [February 13, 2026] - Notifications Phase 4 QA & Documentation Alignment

### Added
- Added backend maintenance runbook for notification retention:
  - `docs/backend/maintenance.md`
  - Documents `notifications:prune`, daily schedule, boundary behavior, and verification paths.
- Added Phase 4 manual verification entrypoint:
  - `tests/manual/verify_notifications_phase_4_quality_documentation.php`
  - Wraps `tests/manual/verify_notifications_phase_5_quality_documentation.php` for track naming continuity.

### Changed
- Rewrote `docs/backend/notification-system.md` to match the current implementation:
  - Persisted model: `saved_places` + `subscriptions`
  - Legacy payload compatibility: `geofences`, `subscribed_routes`
  - Inbox API now explicitly includes `PATCH /notifications/inbox/read-all`
- Updated `docs/README.md` to index `docs/backend/maintenance.md`.
- Updated manual QA script to run reliably in non-Sail environments by overriding test DB env vars to in-memory SQLite during scripted test execution.

## [February 11, 2026] - Notifications Phase 5 Quality & Documentation

### Added
- Full system integration test: `tests/Feature/Notifications/NotificationSystemIntegrationTest.php`
  - End-to-end matching alert flow (event -> job -> delivery -> inbox -> mark-as-read)
  - Non-matching geofence verification
  - Digest user daily digest inbox flow
- Architecture documentation: `docs/backend/notification-system.md`
  - Covers overview, event-driven pipeline, database schema, matching engine,
    delivery pipeline, daily digest, broadcasting, API endpoints, frontend integration
- Phase 5 manual verification runner:
  - `tests/manual/verify_notifications_phase_5_quality_documentation.php`

### Changed
- Updated `docs/README.md` with notification-system.md in structure, reading order, and status table
- Updated `CLAUDE.md` with notification system architecture references
- Archived notifications track in conductor registry

## [February 10, 2026] - Notifications Phase 3 Manual Verification

### Added
- Added Phase 3 notifications manual verification runner:
  - `tests/manual/verify_notifications_phase_3_frontend_settings_toasts.php`
  - Covers settings/toast frontend contract checks, route wiring assertions,
    and targeted Vitest execution for Phase 3 regressions.

### Changed
- Marked notifications track Phase 3 manual verification task complete in:
  - `conductor/tracks/notifications_20260209/plan.md`
  - Verification evidence log:
    `storage/logs/manual_tests/notifications_phase_3_frontend_settings_toasts_2026_02_10_202027.log`

## [February 10, 2026] - Notifications Phase 1 Manual Verification

### Added
- Added Phase 1 notifications manual verification runner:
  - `tests/manual/verify_notifications_phase_1_data_model_preferences.php`
  - Covers schema/index checks, model validation/scopes, and settings
    controller GET/PATCH behavior with rollback cleanup.

### Changed
- Marked notifications track Phase 1 manual verification task complete in:
  - `conductor/tracks/notifications_20260209/plan.md`
  - Verification evidence log:
    `storage/logs/manual_tests/notifications_phase_1_data_model_preferences_2026_02_10_023053.log`

## [February 09, 2026] - Production Data Migration Final Quality Gate

### Added
- Added Phase 4 manual verification runner:
  - `tests/manual/verify_production_data_migration_phase_4_final_quality_gate.php`
- Documented final quality-gate execution in deployment runbook:
  - `docs/deployment/production-seeding.md`
  - Includes full export/verify/reseed fidelity checks and idempotency verification.

### Changed
- Updated docs index deployment section to reference the Phase 4 manual verification command.
- Marked production data migration plan outcomes as complete in `docs/plans/production-data-migration.md`.

## [February 08, 2026] - Production Data Seeding Runbook

### Added
- Added deployment runbook for production data migration:
  - `docs/deployment/production-seeding.md`
  - Forge one-off/deploy-script execution guidance
  - Security warnings and troubleshooting matrix

### Changed
- Updated documentation index to include deployment runbooks.
- Updated root/agent docs to reference production seeding commands and automation script.

## [February 06, 2026] - TTC + GO Transit Unified Feed Completion

### Added
- Documented **GO Transit integration** end-to-end:
  - `GoTransitFeedService`
  - `go-transit:fetch-alerts` command
  - `FetchGoTransitAlertsJob`
  - `go_transit_alerts` table/model/factory
  - `GoTransitAlertSelectProvider`
- Added source documentation for GO Transit feed parsing and storage contract.

### Changed
- Updated unified system docs to reflect **four active providers**: Fire, Police, TTC Transit, GO Transit.
- Updated enum/type docs for `AlertSource::GoTransit` / `'go_transit'`.
- Updated frontend mapping docs for transit category aliasing (`transit` + `go_transit`) and GO-specific severity/icon/color handling.
- Updated docs index/status tables to mark TTC as implemented.

## [February 05, 2026] - Transit Expansion and Query Hardening

### Added
- `AlertStatus` enum and criteria normalization for unified feed filtering.
- `transit_alerts` ingestion pipeline:
  - `TtcAlertsFeedService`
  - `transit:fetch-alerts` command
  - `FetchTransitAlertsJob`
  - `TransitAlert` model and factory
  - `TransitAlertSelectProvider`
- `go_transit_alerts` persistence and enum support scaffolding in unified source model.

### Changed
- `UnifiedAlertsQuery` uses tagged provider injection via `#[Tag('alerts.select-providers')]`.
- `GtaAlertsController` computes latest feed timestamp across all integrated sources.
- Scheduler registration now includes both transit commands:
  - `transit:fetch-alerts` every 5 minutes
  - `go-transit:fetch-alerts` every 5 minutes

## [February 03, 2026] - Dynamic Zones Architecture Spec

### Added
- `docs/architecture/dynamic-zones.md` specification for a backend-driven zone stats feature.

## [February 02, 2026] - Unified Alerts Architecture

### Added
- `UnifiedAlertsQuery`, provider contract, mapper, DTOs, and unified resource transport.
- Fire and Police provider adapters for DB-level `UNION ALL` aggregation.

## [February 01, 2026] - Toronto Police Integration

### Added
- Toronto Police ArcGIS feed integration (`TorontoPoliceFeedService`, command, job, model).

## [January 31, 2026] - Toronto Fire Integration

### Added
- Toronto Fire CAD XML integration (`TorontoFireFeedService`, command, job, model).

## [January 30, 2026] - Production Scheduler Observability

### Added
- Scheduler container support, heartbeat/status commands, and startup reporting.
