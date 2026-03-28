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
- `AlertPresentation.locationCoords` carries validated GTA-region coordinates (or `null`) so components never inspect raw transport location objects.

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
- `docs/frontend/alert-location-map.md` - Alert Location Map feature: coordinate eligibility, tile-provider seam, SSR-safe lazy loading (IMPLEMENTED)

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4
- inertiajs/inertia-laravel (INERTIA_LARAVEL) - v2
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- laravel/wayfinder (WAYFINDER) - v0
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v4
- phpunit/phpunit (PHPUNIT) - v12
- @inertiajs/react (INERTIA_REACT) - v2
- laravel-echo (ECHO) - v2
- react (REACT) - v19
- tailwindcss (TAILWINDCSS) - v4
- @laravel/vite-plugin-wayfinder (WAYFINDER_VITE) - v0
- eslint (ESLINT) - v9
- prettier (PRETTIER) - v3

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `laravel-best-practices` — Apply this skill whenever writing, reviewing, or refactoring Laravel PHP code. This includes creating or modifying controllers, models, migrations, form requests, policies, jobs, scheduled commands, service classes, and Eloquent queries. Triggers for N+1 and query performance issues, caching strategies, authorization and security patterns, validation, error handling, queue and job configuration, route definitions, and architectural decisions. Also use for Laravel code reviews and refactoring existing Laravel code to follow best practices. Covers any task involving Laravel backend PHP code patterns.
- `wayfinder-development` — Activates whenever referencing backend routes in frontend components. Use when importing from @/actions or @/routes, calling Laravel routes from TypeScript, or working with Wayfinder route functions.
- `pest-testing` — Use this skill for Pest PHP testing in Laravel projects only. Trigger whenever any test is being written, edited, fixed, or refactored — including fixing tests that broke after a code change, adding assertions, converting PHPUnit to Pest, adding datasets, and TDD workflows. Always activate when the user asks how to write something in Pest, mentions test files or directories (tests/Feature, tests/Unit, tests/Browser), or needs browser testing, smoke testing multiple pages for JS errors, or architecture tests. Covers: it()/expect() syntax, datasets, mocking, browser testing (visit/click/fill), smoke testing, arch(), Livewire component tests, RefreshDatabase, and all Pest 4 features. Do not use for factories, seeders, migrations, controllers, models, or non-test PHP code.
- `inertia-react-development` — Develops Inertia.js v2 React client-side applications. Activates when creating React pages, forms, or navigation; using <Link>, <Form>, useForm, or router; working with deferred props, prefetching, or polling; or when user mentions React with Inertia, React pages, React forms, or React navigation.
- `tailwindcss-development` — Always invoke when the user's message includes 'tailwind' in any form. Also invoke for: building responsive grid layouts (multi-column card grids, product grids), flex/grid page structures (dashboards with sidebars, fixed topbars, mobile-toggle navs), styling UI components (cards, tables, navbars, pricing sections, forms, inputs, badges), adding dark mode variants, fixing spacing or typography, and Tailwind v3/v4 work. The core use case: writing or fixing Tailwind utility classes in HTML templates (Blade, JSX, Vue). Skip for backend PHP logic, database queries, API routes, JavaScript with no HTML/CSS component, CSS file audits, build tool configuration, and vanilla CSS.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `vendor/bin/sail pnpm run build`, `vendor/bin/sail pnpm run dev`, or `vendor/bin/sail composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `vendor/bin/sail artisan route:list`). Use `vendor/bin/sail artisan list` to discover available commands and `vendor/bin/sail artisan [command] --help` to check parameters.
- Inspect routes with `vendor/bin/sail artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `vendor/bin/sail artisan config:show app.name`, `vendor/bin/sail artisan config:show database.default`. Or read config files directly from the `config/` directory.
- To check environment variables, read the `.env` file directly.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `vendor/bin/sail artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `vendor/bin/sail artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail pnpm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `vendor/bin/sail artisan test --compact` with a specific filename or filter.

=== inertia-laravel/core rules ===

# Inertia

- Inertia creates fully client-side rendered SPAs without modern SPA complexity, leveraging existing server-side patterns.
- Components live in `resources/js/pages` (unless specified in `vite.config.js`). Use `Inertia::render()` for server-side routing instead of Blade views.
- ALWAYS use `search-docs` tool for version-specific Inertia documentation and updated code examples.
- IMPORTANT: Activate `inertia-react-development` when working with Inertia client-side patterns.

# Inertia v2

- Use all Inertia features from v1 and v2. Check the documentation before making changes to ensure the correct approach.
- New features: deferred props, infinite scrolling (merging props + `WhenVisible`), lazy loading on scroll, polling, prefetching.
- When using deferred props, add an empty state with a pulsing or animated skeleton.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `vendor/bin/sail artisan list` and check their parameters with `vendor/bin/sail artisan [command] --help`.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `vendor/bin/sail artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `vendor/bin/sail pnpm run build` or ask the user to run `vendor/bin/sail pnpm run dev` or `vendor/bin/sail composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== wayfinder/core rules ===

# Laravel Wayfinder

Wayfinder generates TypeScript functions for Laravel routes. Import from `@/actions/` (controllers) or `@/routes/` (named routes).

- IMPORTANT: Activate `wayfinder-development` skill whenever referencing backend routes in frontend components.
- Invokable Controllers: `import StorePost from '@/actions/.../StorePostController'; StorePost()`.
- Parameter Binding: Detects route keys (`{post:slug}`) — `show({ slug: "my-post" })`.
- Query Merging: `show(1, { mergeQuery: { page: 2, sort: null } })` merges with current URL, `null` removes params.
- Inertia: Use `.form()` with `<Form>` component or `form.submit(store())` with useForm.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/sail bin pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test --format agent`, simply run `vendor/bin/sail bin pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `vendor/bin/sail artisan make:test --pest {name}`.
- Run tests: `vendor/bin/sail artisan test --compact` or filter: `vendor/bin/sail artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== inertia-react/core rules ===

# Inertia + React

- IMPORTANT: Activate `inertia-react-development` when working with Inertia React client-side patterns.

</laravel-boost-guidelines>
