# Changelog

All notable documentation-relevant changes are tracked here.

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
