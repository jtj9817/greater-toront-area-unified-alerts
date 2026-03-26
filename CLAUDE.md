# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

GTA Alerts is a real-time dashboard for emergency services and transit alerts across the Greater Toronto Area. Built with Laravel 12 (PHP 8.2+) backend and React 19 (TypeScript) frontend connected via Inertia.js.

### Current Integrations
- **Toronto Fire Services** - Live CAD XML feed (real-time, every 5 minutes)
- **Toronto Police Services** - ArcGIS FeatureServer scraping (every 10 minutes)
- **TTC Transit Alerts** - Composite feed (live API + SXA + static pages, every 5 minutes)
- **GO Transit** - Metrolinx JSON API (`api.metrolinx.com/external/go/serviceupdate/en/all`, every 5 minutes)
- **Weather (Environment Canada)** - Live weather data by FSA (30-min cache, location picker)

## Commands

### Development
```bash
composer run setup          # One-time: install deps, .env, key, migrate, build
composer run dev            # Runs server, supervised queue worker, log tail (pail), Vite, and scheduler concurrently
```

### Testing
```bash
composer run test           # Config clear + Pint lint check + php artisan test
php artisan test            # Run Pest tests only
php artisan test --filter=AuthenticationTest  # Run a single test file
```

### Manual Testing
```bash
# Manual test scripts require a running testing database (PostgreSQL). Use the helper script:
./scripts/run-manual-test.sh tests/manual/verify_phase_1_foundations.php
./scripts/run-manual-test.sh tests/manual/verify_phase_2_provider_implementations.php
./scripts/run-manual-test.sh tests/manual/verify_phase_3_unified_querying.php
./scripts/run-manual-test.sh tests/manual/verify_phase_4_frontend_integration.php
./scripts/run-manual-test.sh tests/manual/verify_phase_5_quality_gate.php
./scripts/run-manual-test.sh tests/manual/verify_feed_001_phase_5_documentation.php
./scripts/run-manual-test.sh tests/manual/verify_production_data_migration_phase_3_automation_documentation.php
./scripts/run-manual-test.sh tests/manual/verify_scheduler_resilience_phase_1_critical_fixes_foundation.php
./scripts/run-manual-test.sh tests/manual/verify_scheduler_resilience_phase_2_resilience_architecture_upgrade.php
./scripts/run-manual-test.sh tests/manual/verify_scheduler_resilience_phase_3_data_integrity_maintenance.php
./scripts/run-manual-test.sh tests/manual/verify_phase_5_saved_alerts_quality_gates.php
```

### Linting & Formatting
```bash
composer run lint           # PHP: Pint auto-fix
pnpm run lint                # JS/TS: ESLint auto-fix
pnpm run format              # JS/TS: Prettier auto-format
pnpm run types               # TypeScript type checking (tsc --noEmit)
```

### Data
```bash
php artisan fire:fetch-incidents       # Manually sync Toronto Fire feed
php artisan police:fetch-calls         # Manually sync Toronto Police feed
php artisan go-transit:fetch-alerts    # Manually sync GO Transit feed
php artisan db:export-sql              # Export alert tables as SQL UPSERT statements
php artisan db:import-sql --file=... --force  # Import SQL dump through psql
./scripts/export-alert-data.sh --sail  # Orchestrate SQL export with transfer guidance
php artisan db:export-to-seeder        # Deprecated: legacy seeder export workflow
php artisan db:verify-production-seed  # Deprecated: legacy seeder verification workflow
./scripts/generate-production-seed.sh --sail  # Deprecated: legacy orchestration script
```

### Scheduler
```bash
php artisan scheduler:run-and-log    # Run scheduled tasks with logging
php artisan scheduler:status         # Check scheduler health
php artisan scheduler:report         # Show schedule configuration
```

## Architecture

### Unified Alerts System (Core)

The system uses a **Provider & Adapter** pattern to unify divergent data sources:

```
Source Models (FireIncident, PoliceCall, GoTransitAlert)
    ↓
Select Providers (AlertSelectProvider implementations)
    ↓
UNION Query (UnifiedAlertsQuery)
    ↓
UnifiedAlert DTO (transport)
    ↓
DomainAlert (frontend typed union)
    ↓
AlertPresentation (frontend view model)
```

#### Key Components

**DTOs & Value Objects:**
- `App\Services\Alerts\DTOs\UnifiedAlert` - Transport DTO (UI-agnostic)
- `App\Services\Alerts\DTOs\AlertLocation` - Nested location DTO
- `App\Services\Alerts\DTOs\UnifiedAlertsCriteria` - Query criteria with validation
- `App\Services\Alerts\DTOs\AlertId` - Composite ID (`source:externalId`) with validation
- `App\Services\Alerts\DTOs\UnifiedAlertsCursor` - Opaque cursor `(ts,id)` encoder/decoder

**Enums:**
- `App\Enums\AlertSource` - Fire, Police, Transit, GoTransit (type-safe)
- `App\Enums\AlertStatus` - All, Active, Cleared (with `normalize()` method)

**Providers (Tagged Injection):**
- `App\Services\Alerts\Contracts\AlertSelectProvider` - Interface for SELECT queries
- `App\Services\Alerts\Providers\FireAlertSelectProvider` - Fire incidents adapter
- `App\Services\Alerts\Providers\PoliceAlertSelectProvider` - Police calls adapter
- `App\Services\Alerts\Providers\TransitAlertSelectProvider` - TTC transit alerts adapter
- `App\Services\Alerts\Providers\GoTransitAlertSelectProvider` - GO Transit alerts adapter

**Query & Mapper:**
- `App\Services\Alerts\UnifiedAlertsQuery` - UNION ALL query with tagged provider injection
- `App\Services\Alerts\Mappers\UnifiedAlertMapper` - Maps DB rows to DTOs

#### Query Flow
1. `GtaAlertsController` and `Api\FeedController` receive request with `status`, `source`, `q`, `since`, and optional `cursor`
2. Controllers create `UnifiedAlertsCriteria` (normalization for source/query/since/cursor/per-page)
3. Controllers call `UnifiedAlertsQuery::cursorPaginate($criteria)`
4. Query fetches tagged providers and builds a UNION ALL subquery
5. Query applies status/source/since filters and cross-driver `q` behavior (PostgreSQL FTS + ILIKE, MySQL FULLTEXT + LIKE, SQLite outer `LIKE`)
6. Query uses deterministic seek pagination on `(timestamp DESC, id DESC)` to compute `next_cursor`
7. Controllers return `UnifiedAlertResource` batches plus `next_cursor`

### Backend Data Pipeline

**Toronto Fire:**
- XML feed → `TorontoFireFeedService` (fetch/parse) → `FetchFireIncidentsCommand` (upsert via `event_num`) → `FireIncident` model
- Command marks missing incidents as `is_active = false`
- Scheduled every 5 minutes in `routes/console.php`

**Toronto Police:**
- ArcGIS scraping → `TorontoPoliceFeedService` → `FetchPoliceCallsCommand` (upsert via `object_id`) → `PoliceCall` model
- Command marks missing calls as `is_active = false`
- Scheduled every 10 minutes in `routes/console.php`

**TTC Transit:**
- Multiple sources: alerts.ttc.ca JSON API, Sitecore SXA search, static CMS pages
- Command marks missing alerts as `is_active = false`
- Scheduled every 5 minutes in `routes/console.php`

**GO Transit:**
- Metrolinx JSON API → `GoTransitFeedService` (fetch/parse) → `FetchGoTransitAlertsCommand` (upsert via `external_id`) → `GoTransitAlert` model
- Parses Trains (notifications + SAAG real-time delays), Buses, and Stations
- Command marks missing alerts as `is_active = false`
- Scheduled every 5 minutes in `routes/console.php`

### In-App Notification System

Event-driven notification pipeline for user-targeted alerts:

```
AlertCreated event → DispatchAlertNotifications listener → FanOutAlertNotificationsJob → DispatchAlertNotificationChunkJob (chunks of 250) → DeliverAlertNotificationJob → NotificationLog + AlertNotificationSent broadcast
```

- **Matching Engine:** `NotificationMatcher` evaluates alert type, severity threshold, geofence (Haversine), and route subscriptions against `NotificationPreference` records
- **Delivery:** `DeliverAlertNotificationJob` with optimistic locking and idempotent `firstOrCreate`
- **Daily Digest:** `GenerateDailyDigestJob` aggregates prior-day notifications for `digest_mode` users
- **Broadcasting:** `AlertNotificationSent` on `private-users.{userId}.notifications` channel
- **Inbox API:** `NotificationInboxController` — list, mark read, dismiss, clear all (ownership-enforced)
- **Preferences API:** `NotificationPreferenceController` — GET/PATCH at `/settings/notifications`

See `docs/backend/notification-system.md` for full documentation.

### Weather Feature

Live weather conditions displayed in the footer, location-aware via Forward Sortation Area (FSA) lookup.

**Architecture:**
- Two-layer caching: fast Laravel Cache (30 min) + durable DB cache for historical data
- Location selection: Postal code search or browser geolocation → nearest FSA centroid
- Data source: Environment Canada weather API

**Key Components:**
- `App\Services\Weather\DTOs\WeatherData` - Weather data DTO (temp, conditions, alerts, metadata)
- `App\Services\Weather\Contracts\WeatherProvider` - Interface for weather data sources
- `App\Services\Weather\Providers\EnvironmentCanadaWeatherProvider` - Environment Canada API adapter
- `App\Services\Weather\WeatherFetchService` - Orchestrates fetch with caching strategy
- `App\Services\Weather\WeatherCacheService` - Dual-layer cache management
- `App\Models\GtaPostalCode` - Postal code → FSA mapping with centroid coordinates
- `App\Models\WeatherCache` - Durable database cache for weather data
- `useWeather` hook - Frontend reactive weather state management
- `WeatherController` - API endpoint for current weather by FSA
- `PostalCodeSearchController` - Postal code lookup endpoint
- `PostalCodeResolveCoordsController` - Geolocation → nearest FSA resolution

See `docs/backend/weather.md` for full documentation.

### Frontend Structure

Inertia.js renders React pages from `resources/js/pages/`. The main public page is `gta-alerts.tsx` which mounts the feature module at `resources/js/features/gta-alerts/`.

**Transport vs Domain vs Presentation:**
- Backend sends `UnifiedAlertResource[]` (transport shape)
- `fromResource(...)` validates and maps transport values to `DomainAlert`
- Components consume `DomainAlert` and derive `AlertPresentation` with `mapDomainAlertToPresentation(...)`

**Views:**
- `FeedView` - Server-filtered feed with cursor-based infinite scroll and URL-driven filters
- `ZonesView` - Geographic zone statistics (hard-coded, awaiting backend service)
- `SavedView` - Saved alerts (guest: `localStorage` capped at 10; auth: `/api/saved-alerts` with server persistence, uncapped)
- `SettingsView` - User settings
- `AlertDetailsView` - Detail view for individual alerts

**Services:**
- `AlertService` - Domain boundary mapping for transport -> domain conversion (no live-feed query filtering)
- `useInfiniteScroll` hook - Cursor batch loading (`/api/feed`) with dedupe, abort, stale-response guards
- Domain schemas/types in `resources/js/features/gta-alerts/domain/alerts/`

### Routing
- `routes/web.php` — HTTP routes (home renders `gta-alerts` Inertia page; includes `/api/feed` JSON batch endpoint; dashboard requires auth)
- `routes/console.php` — Scheduled commands
- `routes/settings.php` — Authenticated user settings

### Auth
Laravel Fortify handles authentication including two-factor auth. Settings controllers live in `app/Http/Controllers/Settings/`.

### Testing
Pest PHP with Feature and Unit suites.

**Default (SQLite):**
- `tests/Feature/` - Integration tests
- `tests/Unit/` - Unit tests
- Uses SQLite, sync queue, array cache/session (configured in `phpunit.xml`)

**MySQL Driver Tests:**
- `phpunit.mysql.xml` - MySQL configuration for cross-driver verification
- `tests/Feature/UnifiedAlerts/UnifiedAlertsMySqlDriverTest.php`

**Manual Test Scripts:**
- `tests/manual/` - Destructive verification scripts
- Require a running testing database (configured in `.env.testing`). Run via `./scripts/run-manual-test.sh`.

### UI Design Revamp (Prototype Two) Notes
- The revamp is scoped to GTA Alerts components under `resources/js/features/gta-alerts/` and `.gta-alerts-theme` token overrides in `resources/css/app.css`.
- Current shell/view contract includes prototype sidebar/header/footer, refresh FAB, Feed/Table toggle, and expandable table summaries.
- Verification reference spec: `tests/e2e/design-revamp-phase-4.spec.ts`.
- Verification runbook: `docs/runbooks/design-revamp-phase-4-verification.md`.
- Remaining quality-gate findings are tracked in `docs/tickets/FEED-017-design-revamp-phase-4-quality-gate-failures.md` (Closed; see ticket for resolution notes).

### Production Scheduler
Dedicated scheduler container (`docker/scheduler/`) runs `php artisan scheduler:run-and-log` every minute:
- Logs all scheduler output to Laravel logs
- Writes heartbeat keys to cache for health monitoring
- Includes startup report and health check commands

**Fetch job dispatch** is handled by `App\Services\ScheduledFetchJobDispatcher` (called from `Schedule::call()` closures in `routes/console.php`). The dispatcher enforces pre-enqueue uniqueness via a `jobs`-table check and a `UniqueLock`, preventing redundant fetch-job accumulation during worker outages. Fetch-job uniqueness locks use a dedicated cache store configured by `QUEUE_UNIQUE_LOCK_STORE` (default `file`; use `redis` in production) to avoid noisy `cache_locks` conflicts.

## Conventions

- PHP formatting: Laravel Pint (Laravel preset). Run `composer run lint`.
- JS/TS formatting: Prettier + ESLint with import sorting. Run `npm run format && npm run lint`.
- Indentation: 4 spaces (PHP/JS/TS), 2 spaces (YAML).
- React components: PascalCase filenames. Hooks: camelCase.
- UI components use Radix UI primitives wrapped in `resources/js/components/ui/`.
- Commit messages: imperative, concise. Conventional prefixes accepted (e.g., `feat:`, `fix:`).

## Adding New Alert Sources

1. Create model and migration (follow `FireIncident`/`PoliceCall` pattern)
2. Create feed service and fetch command
3. Create `*AlertSelectProvider` implementing `AlertSelectProvider`
4. Register provider in `AppServiceProvider` with tag `alerts.select-providers`
5. Add source to `AlertSource` enum
6. Update `latestFeedUpdatedAt()` in `GtaAlertsController`
7. Add a `Schedule::call()` entry in `routes/console.php` that delegates to a new method on `App\Services\ScheduledFetchJobDispatcher`
8. Add source-specific frontend schema + mapper under `resources/js/features/gta-alerts/domain/alerts/`
9. Update view presentation mapping (`mapDomainAlertToPresentation`) and tests

## Architecture Documentation

See `docs/` for detailed architecture:
- `docs/backend/unified-alerts-system.md` - Unified alerts architecture (IMPLEMENTED)
- `docs/backend/enums.md` - AlertSource, AlertStatus, AlertId documentation
- `docs/backend/dtos.md` - UnifiedAlert, UnifiedAlertsCriteria, AlertLocation
- `docs/backend/production-scheduler.md` - Scheduler container, ScheduledFetchJobDispatcher, resilience guardrails
- `docs/backend/security-headers.md` - EnsureSecurityHeaders middleware, CSP nonce and hot-mode extension
- `docs/deployment/production-seeding.md` - Forge-safe SQL export/import transfer runbook
- `docs/sources/` - Individual data source documentation (Toronto Fire, Police, TTC, GO Transit)
- `docs/architecture/provider-adapter-pattern.md` - Provider pattern explanation
- `docs/backend/notification-system.md` - In-app notification system (IMPLEMENTED)
- `docs/backend/saved-alerts.md` - Saved Alerts system: API contract, guest/auth storage, hydration path, unresolved-ID handling (IMPLEMENTED)
- `docs/backend/weather.md` - Weather feature architecture (IMPLEMENTED)
- `docs/architecture/dynamic-zones.md` - Dynamic zones feature (PLANNED)
