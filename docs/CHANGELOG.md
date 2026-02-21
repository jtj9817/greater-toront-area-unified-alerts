# Changelog

All notable documentation-relevant changes are tracked here.

## [February 21, 2026] - FEED-001 Closure and Artifact Alignment

### Changed
- Marked FEED-001 ticket as closed/implemented and added implementation-resolution notes:
  - `docs/tickets/FEED-001-server-side-filters-infinite-scroll.md`
- Aligned conductor FEED-001 artifacts to completed state:
  - `conductor/tracks.md`
  - `conductor/tracks/server_side_filtering_20260218/metadata.json`
  - `conductor/tracks/server_side_filtering_20260218/plan.md`
  - `conductor/tracks/server_side_filtering_20260218/spec.md`
- Updated architecture/guidance docs to match shipped cursor-based feed behavior:
  - `docs/backend/architecture-walkthrough.md`
  - `docs/backend/unified-alerts-qa.md`
  - `CLAUDE.md`
- Updated dependent/open ticket current-state language to reflect FEED-001 being shipped:
  - `docs/tickets/FEED-002-real-time-push.md`
  - `docs/tickets/FEED-003-saved-filter-presets.md`
  - `docs/tickets/FEED-004-sort-direction-toggle.md`
- Corrected frontend service docs to match current `AlertService` responsibilities:
  - `docs/frontend/alert-service.md`

## [February 21, 2026] - FEED-001 Phase 5 Documentation Completion

### Added
- Added FEED-001 Phase 5 documentation verification runner:
  - `tests/manual/verify_feed_001_phase_5_documentation.php`
  - Verifies feed query param docs (`status`, `source`, `q`, `since`, `cursor`), infinite scroll cursor semantics, FULLTEXT/sqlite fallback notes, and live-feed client-filter removal docs.

### Changed
- Updated root docs for shipped FEED-001 behavior:
  - `README.md` now documents server-authoritative filters, cursor infinite scroll, and feed query parameter examples.
- Updated backend architecture docs:
  - `docs/backend/unified-alerts-system.md` now documents request/query flow for `/` and `/api/feed`, cursor tuple semantics, seek pagination rules, and MySQL FULLTEXT + sqlite fallback expectations.
  - `docs/backend/dtos.md` now documents expanded `UnifiedAlertsCriteria` fields and `UnifiedAlertsCursor`.
- Updated frontend docs:
  - `docs/frontend/alert-service.md` now clarifies that live feed filtering is server-authoritative and `AlertService.searchDomainAlerts()` is not used for live feed request filtering.
- Updated documentation index/status:
  - `docs/README.md` now marks FEED-001 as implemented in the status table and removes it from open tickets.
- Updated agent guidance:
  - `CLAUDE.md` query flow and frontend feed descriptions now reflect cursor pagination and URL-driven server-side filtering.
  - Added manual test command for `verify_feed_001_phase_5_documentation.php`.
- Updated conductor track plan:
  - `conductor/tracks/server_side_filtering_20260218/plan.md` now marks Phase 5 documentation and manual verification as complete with verification log reference.

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

## [February 16, 2026] - Scheduler Resilience & Stability Overhaul

### Added
- **Job-based ingestion architecture:** All four fetch tasks migrated from `Schedule::command()` to `Schedule::job()`. Each job now has `$tries=3`, `$backoff=30`, `$timeout=120`, and `WithoutOverlapping` middleware. The `withoutOverlapping(10)` lock expiry prevents 24-hour mutex lockouts.
- **Empty feed protection:** `ALLOW_EMPTY_FEEDS` environment variable (default: `false`). Feed services throw a `RuntimeException` when the feed is empty and the flag is `false`, preventing mass deactivation of existing records.
- **Circuit breaker:** `FeedCircuitBreaker` service opens after 5 consecutive failures per feed and blocks further attempts for 5 minutes. Implemented in all four feed services (`TorontoFireFeedService`, `TorontoPoliceFeedService`, `GoTransitFeedService`, `TtcAlertsFeedService`).
- **Graceful record parsing:** Individual malformed records no longer abort the entire batch. `FetchFireIncidentsCommand` and `FetchGoTransitAlertsCommand` changed from `return self::FAILURE` to `continue` on per-record parse failure. All four commands now log a warning and continue.
- **Police pagination partial-results recovery:** `TorontoPoliceFeedService` persists results from successfully fetched pages when a mid-pagination HTTP failure occurs.
- **Data sanity checks:** `FeedDataSanity` service warns on future timestamps (>15 min drift) and coordinates outside GTA bounds (police/fire only; fire feed does not provide coordinates).
- **Memory safety limit:** `TorontoPoliceFeedService` pagination loop has an upper bound to prevent OOM on unexpectedly large responses.
- **Scene Intel failure rate monitoring:** `FetchFireIncidentsCommand` tracks Scene Intel success/failure per run and logs a warning when >50% of attempts fail.
- **Queue depth monitoring:** Scheduled closure checks `Queue::size()` every 5 minutes and logs an error if depth exceeds 100.
- **Failed job pruning:** `queue:prune-failed --hours=168` runs daily to prevent unbounded growth of `failed_jobs`.
- **Notification delivery retry:** `DeliverAlertNotificationJob` updated to `$tries=5`, `$backoff=10`.
- **Documentation:** Created `docs/runbooks/scheduler-troubleshooting.md` and `docs/runbooks/queue-troubleshooting.md`. Updated `docs/backend/production-scheduler.md` with resilience guardrails, monitoring thresholds, and `ALLOW_EMPTY_FEEDS` strategy. Updated `docs/backend/maintenance.md` with failed job pruning policy. Updated `docs/backend/scene-intel.md` with retry policy and acceptable failure modes.

### Changed
- Scheduled fetch tasks use `Schedule::job()` (not `Schedule::command()`) so the queue handles retries and overlap locking.
- `withoutOverlapping(10)` expiry added to all four fetch jobs (previously police lacked explicit overlapping protection).

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

## [February 07, 2026] - Frontend Typed Alert Domain Refactor

### Added
- Introduced `DomainAlert` discriminated union (`kind: 'fire' | 'police' | 'transit' | 'go_transit'`) as the canonical frontend alert type.
- Zod runtime validation schemas for all four alert sources under `resources/js/features/gta-alerts/domain/alerts/`.
- Canonical boundary entrypoint `fromResource(resource): DomainAlert | null` — invalid items are caught, logged, and discarded without crashing the UI.
- Source-specific domain mapper modules (`fire/mapper.ts`, `police/mapper.ts`, `transit/ttc/mapper.ts`, `transit/go/mapper.ts`).
- `AlertPresentation` view model in `domain/alerts/view/types.ts` for card/table/details renderers.
- `mapDomainAlertToPresentation(...)` mapper for deriving presentation fields (severity, icon, color, description) from `DomainAlert`.
- Dedicated `AlertDetailsView.test.tsx` test suite covering functional `switch (alert.kind)` branch rendering.

### Changed
- `AlertService` refactored to a thin facade: orchestrates `fromResource()` and decentralized mappers; no longer holds source-specific business logic directly.
- `AlertDetailsView` migrated from class inheritance / template-method pattern to a functional component with explicit `switch (alert.kind)` branching.
- `AlertCard`, `FeedView`, `App.tsx`, `AlertTableView`, and `SavedView` updated to consume `DomainAlert` directly.
- Presentation-only categories (`hazard`, `medical`) are now derived at the view layer — not added as `DomainAlert.kind` variants.

### Removed
- Legacy `AlertItem` interface (`resources/js/features/gta-alerts/types.ts`) deleted.
- Deprecated `AlertService` APIs (`mapUnifiedAlertToAlertItem`, `mapUnifiedAlertsToAlertItems`, `search` over legacy view-model values) removed.

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
