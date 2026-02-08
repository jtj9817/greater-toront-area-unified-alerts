# Changelog

All notable documentation-relevant changes are tracked here.

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
