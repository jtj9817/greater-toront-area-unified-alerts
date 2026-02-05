# Changelog

All notable changes to the GTA Alerts project will be documented in this file.

## [February 05, 2026] - Unified Alerts System & Query Refinement

### Added
- **AlertSource Enum** (`app/Enums/AlertSource.php`) - Type-safe source identifiers (Fire, Police, Transit)
- **AlertStatus Enum** (`app/Enums/AlertStatus.php`) - Type-safe status values (All, Active, Cleared) with normalization
- **AlertId Value Object** (`app/Services/Alerts/DTOs/AlertId.php`) - Composite ID for unified alerts with validation
- **UnifiedAlertsCriteria DTO** - Query criteria with per-page/page validation
- **Tagged Provider Injection** - AlertSelectProvider implementations tagged and auto-injected into UnifiedAlertsQuery
- **MySQL Cross-Driver Tests** - `phpunit.mysql.xml` configuration with dedicated test database

### Changed
- **UnifiedAlertsQuery** - Refactored to use tagged provider injection via `#[Tag('alerts.select-providers')]`
- **UnifiedAlertsCriteria** - Now uses `AlertStatus` enum with `normalize()` method for validation
- **Fire/Police/Transit Providers** - Updated to use `AlertSource` enum
- **GtaAlertsController** - Uses `AlertStatus::normalize()` for request validation
- **Testing Setup** - Added `.env.testing` for MySQL manual test scripts

### Fixed
- Phase 6 quality gates for query refinement completed
- Type-safe boundary validation for alert queries
- MySQL driver compatibility for all provider SELECT expressions

## [February 03, 2026] - Dynamic Zones Architecture

### Added
- **Dynamic Zones Architecture Specification** (`docs/Dynamic-Zones-Architecture.md`)
  - Zone definitions and beat/division mapping config
  - ZoneStatsService for 24-hour aggregation
  - ZoneStats DTO and Resource serialization
  - Frontend integration with ZonesView component

## [February 02, 2026] - Unified Alerts Implementation

### Added
- **UnifiedAlertsQuery Service** - Database-level UNION query for cross-source pagination
- **AlertSelectProvider Interface** - Contract for source-specific SELECT queries
- **FireAlertSelectProvider** - Toronto Fire incidents unified query adapter
- **PoliceAlertSelectProvider** - Toronto Police calls unified query adapter
- **TransitAlertSelectProvider** - Stub for future TTC transit alerts
- **UnifiedAlertMapper** - Maps database rows to UnifiedAlert DTOs
- **UnifiedAlertResource** - JSON serialization for Inertia props
- **GtaAlertsController** - Public page controller with unified alerts endpoint

### Changed
- **Frontend types** - Added `UnifiedAlertResource` interface to `types.ts`
- **AlertService** - Added `mapUnifiedAlertToAlertItem()` for transport to view-model mapping
- **App.tsx** - Updated to consume `alerts` prop from backend
- **Feed pagination** - Moved from client-side to server-side via UNION query

## [February 01, 2026] - Toronto Police Integration

### Added
- **PoliceCall Model** - Eloquent model for TPS "Calls for Service" data
- **TorontoPoliceFeedService** - ArcGIS FeatureServer scraping service
- **FetchPoliceCallsCommand** - `police:fetch-calls` artisan command
- **FetchPoliceCallsJob** - Queue job wrapper for async processing
- **Police Select Provider** - Unified query adapter for police data

## [January 31, 2026] - Toronto Fire Integration

### Added
- **FireIncident Model** - Eloquent model for Toronto Fire Services CAD data
- **TorontoFireFeedService** - XML feed parsing service
- **FetchFireIncidentsCommand** - `fire:fetch-incidents` artisan command
- **FetchFireIncidentsJob** - Queue job wrapper
- **Fire Select Provider** - Unified query adapter for fire data
- **FireIncidentResource** - JSON API resource serialization

## [January 30, 2026] - Production Scheduler

### Added
- **Scheduler Docker Container** - `docker/scheduler/` with cron-based scheduling
- **scheduler:run-and-log Command** - Enhanced scheduler with logging and heartbeat
- **scheduler:status Command** - Health check command for stale scheduler detection
- **scheduler:report Command** - Startup report with schedule configuration
- **Heartbeat Cache Keys** - `scheduler:last_tick_at`, `scheduler:last_tick_exit_code`, `scheduler:last_tick_duration_ms`
- **HEALTHCHECK Docker Instruction** - Container health monitoring

---

## Project Status Summary

### Completed Features
- Toronto Fire Services live CAD XML feed integration
- Toronto Police Services ArcGIS scraping integration
- Unified Alerts Query system with Provider & Adapter pattern
- Type-safe enums for AlertSource and AlertStatus
- Server-side pagination over active and cleared alerts
- Production scheduler container with observability

### Planned Features
- TTC Transit Alerts integration (architecture spec complete)
- Dynamic Zones feature with real-time statistics
- Additional data sources (hazard, medical)

### Technical Highlights
- Laravel 12 (PHP 8.2+) backend
- React 19 + TypeScript frontend
- Inertia.js for seamless SPA experience
- Pest PHP testing with SQLite/MySQL support
- Tagged provider injection for extensibility
