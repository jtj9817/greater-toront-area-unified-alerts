# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

GTA Alerts is a real-time dashboard for emergency services and transit alerts across the Greater Toronto Area. Built with Laravel 12 (PHP 8.2+) backend and React 19 (TypeScript) frontend connected via Inertia.js.

### Current Integrations
- **Toronto Fire Services** - Live CAD XML feed (real-time, every 5 minutes)
- **Toronto Police Services** - ArcGIS FeatureServer scraping (every 10 minutes)
- **TTC Transit Alerts** - Architecture spec complete, implementation pending
- **GO Transit** - Metrolinx JSON API (`api.metrolinx.com/external/go/serviceupdate/en/all`, every 5 minutes)

## Commands

### Development
```bash
composer run setup          # One-time: install deps, .env, key, migrate, build
composer run dev            # Runs server, queue worker, log tail (pail), and Vite concurrently
```

### Testing
```bash
composer run test           # Config clear + Pint lint check + php artisan test
php artisan test            # Run Pest tests only
php artisan test --filter=AuthenticationTest  # Run a single test file
```

### Manual Testing (MySQL)
```bash
# Manual test scripts require testing environment and MySQL database
APP_ENV=testing ./vendor/bin/sail php tests/manual/verify_phase_1_foundations.php
APP_ENV=testing ./vendor/bin/sail php tests/manual/verify_phase_2_mapper_extraction.php
APP_ENV=testing ./vendor/bin/sail php tests/manual/verify_phase_3_unified_querying.php
APP_ENV=testing ./vendor/bin/sail php tests/manual/verify_phase_4_frontend_integration.php
APP_ENV=testing ./vendor/bin/sail php tests/manual/verify_phase_5_quality_gate.php
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
AlertItem View Model (frontend)
```

#### Key Components

**DTOs & Value Objects:**
- `App\Services\Alerts\DTOs\UnifiedAlert` - Transport DTO (UI-agnostic)
- `App\Services\Alerts\DTOs\AlertLocation` - Nested location DTO
- `App\Services\Alerts\DTOs\UnifiedAlertsCriteria` - Query criteria with validation
- `App\Services\Alerts\DTOs\AlertId` - Composite ID (`source:externalId`) with validation

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
1. `GtaAlertsController` receives request with `status` filter
2. Creates `UnifiedAlertsCriteria` (with per-page/page validation)
3. Calls `UnifiedAlertsQuery::paginate($criteria)`
4. Query fetches tagged providers, builds UNION ALL
5. Applies status filter, orders by timestamp DESC with tie-breakers
6. Returns `LengthAwarePaginator` with items mapped through `UnifiedAlertMapper`
7. Controller wraps results in `UnifiedAlertResource` collection

### Backend Data Pipeline

**Toronto Fire:**
- XML feed → `TorontoFireFeedService` (fetch/parse) → `FetchFireIncidentsCommand` (upsert via `event_num`) → `FireIncident` model
- Command marks missing incidents as `is_active = false`
- Scheduled every 5 minutes in `routes/console.php`

**Toronto Police:**
- ArcGIS scraping → `TorontoPoliceFeedService` → `FetchPoliceCallsCommand` (upsert via `object_id`) → `PoliceCall` model
- Command marks missing calls as `is_active = false`
- Scheduled every 10 minutes in `routes/console.php`

**TTC Transit (Future):**
- Multiple sources: alerts.ttc.ca JSON API, Sitecore SXA search, static CMS pages
- Architecture documented in `docs/backend/sources/ttc-transit.md`

**GO Transit:**
- Metrolinx JSON API → `GoTransitFeedService` (fetch/parse) → `FetchGoTransitAlertsCommand` (upsert via `external_id`) → `GoTransitAlert` model
- Parses Trains (notifications + SAAG real-time delays), Buses, and Stations
- Command marks missing alerts as `is_active = false`
- Scheduled every 5 minutes in `routes/console.php`

### Frontend Structure

Inertia.js renders React pages from `resources/js/pages/`. The main public page is `gta-alerts.tsx` which mounts the feature module at `resources/js/features/gta-alerts/`.

**Transport vs View-Model:**
- Backend sends `UnifiedAlertResource[]` (transport shape)
- `AlertService.mapUnifiedAlertToAlertItem()` maps to `AlertItem` (view-model)
- Components consume `AlertItem` with UI-agnostic properties

**Views:**
- `FeedView` - Paginated alert feed with client-side search/filter
- `ZonesView` - Geographic zone statistics (hard-coded, awaiting backend service)
- `SavedView` - Saved alerts (client-side storage)
- `SettingsView` - User settings
- `AlertDetailsView` - Detail view for individual alerts

**Services:**
- `AlertService` - Mapping, search, filtering, severity calculation
- Type definitions in `resources/js/features/gta-alerts/types.ts`

### Routing
- `routes/web.php` — HTTP routes (home renders `gta-alerts` Inertia page; dashboard requires auth)
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
- Require `APP_ENV=testing` and MySQL database (configured in `.env.testing`)

### Production Scheduler
Dedicated scheduler container (`docker/scheduler/`) runs `php artisan scheduler:run-and-log` every minute:
- Logs all scheduler output to Laravel logs
- Writes heartbeat keys to cache for health monitoring
- Includes startup report and health check commands

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
7. Add schedule entry in `routes/console.php`
8. Create frontend mapping in `AlertService`
9. Update types and tests

## Architecture Documentation

See `docs/` for detailed architecture:
- `docs/backend/unified-alerts-system.md` - Unified alerts architecture (IMPLEMENTED)
- `docs/backend/enums.md` - AlertSource, AlertStatus, AlertId documentation
- `docs/backend/dtos.md` - UnifiedAlert, UnifiedAlertsCriteria, AlertLocation
- `docs/backend/sources/` - Individual data source documentation
- `docs/architecture/provider-adapter-pattern.md` - Provider pattern explanation
- `docs/architecture/dynamic-zones.md` - Dynamic zones feature (PLANNED)
