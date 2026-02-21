# Greater Toronto Area Alerts (GTA Alerts)

A real-time dashboard for emergency services and transit alerts across the Greater Toronto Area. This project provides a unified, live view of fire, police, and other emergency incidents, aggregating data from multiple municipal sources into a single, cohesive feed.

## Project Overview

GTA Alerts is built as a data aggregator and API using Laravel, with a high-performance React frontend powered by Inertia.js. It features automated scraping subsystems that pull live data from Toronto Open Data feeds (CAD systems) and ArcGIS Feature Servers.

### Key Features

- **Unified Alert Feed:** A mixed-source timeline of Fire, Police, TTC Transit, and GO Transit incidents with server-authoritative filters and cursor-based infinite scroll.
- **Toronto Fire Integration:** Real-time sync with the Toronto Fire Services CAD (Computer Aided Dispatch) XML feed.
- **Toronto Police Integration:** Automated scraping of the TPS "Calls for Service" ArcGIS FeatureServer.
- **TTC Transit Integration:** Composite feed from alerts.ttc.ca JSON API, Sitecore SXA search, and static CMS pages.
- **GO Transit Integration:** Real-time service updates from the Metrolinx JSON API (trains, buses, stations, and delay notifications).
- **Inertia.js SPA:** A seamless single-page application experience with React 19 and Radix UI.
- **Production-Ready Scheduler:** Built-in observability for background scraping tasks with heartbeat monitoring and health checks.

---

## Tech Stack

- **Backend:** PHP 8.2+, Laravel 12, Inertia.js, Laravel Fortify (Authentication).
- **Frontend:** React 19, TypeScript, Vite, Tailwind CSS 4.0, Radix UI.
- **Database:** SQLite (local/dev), MySQL (production).
- **Automation:** Playwright (for complex web scraping).
- **Testing:** Pest PHP.

---

## Architecture

The system follows a **Provider & Adapter** pattern to unify divergent data sources:

1.  **Source Models:** Raw data is persisted in source-specific tables (`fire_incidents`, `police_calls`, `transit_alerts`, `go_transit_alerts`).
2.  **Select Providers:** Standardized query providers map source-specific columns into a unified SQL structure.
3.  **Unified Aggregator:** A database-level `UNION ALL` query supports stable cross-source ordering, server-side filtering, and cursor pagination.
4.  **Frontend Boundary:** Inertia transport resources are validated with Zod and mapped to a typed `DomainAlert` discriminated union.
5.  **Presentation Mapping:** UI components render a derived `AlertPresentation` model from `DomainAlert` values.

### Source Coverage

| Source | Backing Table | Schedule |
|--------|---------------|----------|
| Toronto Fire | `fire_incidents` | Every 5 minutes |
| Toronto Police | `police_calls` | Every 10 minutes |
| TTC Transit | `transit_alerts` | Every 5 minutes |
| GO Transit | `go_transit_alerts` | Every 5 minutes |

---

## Getting Started

### Prerequisites
- PHP 8.2+
- Node.js & pnpm
- Docker (for Laravel Sail development)

### Installation & Setup

```bash
# Clone the repository
git clone <repository-url>
cd greater-toronto-area-alerts

# Install dependencies and setup environment
composer run setup

# Or manual steps:
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

### Running Locally (Laravel Sail)

```bash
# Start the development environment
./vendor/bin/sail up -d

# Run the frontend dev server
./vendor/bin/sail pnpm run dev

# Run the data fetchers manually
./vendor/bin/sail artisan fire:fetch-incidents
./vendor/bin/sail artisan police:fetch-calls
./vendor/bin/sail artisan transit:fetch-alerts
./vendor/bin/sail artisan go-transit:fetch-alerts
```

---

## Data Synchronization

The application uses the Laravel Scheduler to keep data fresh.

| Source | Schedule | Endpoint |
|--------|----------|----------|
| **Toronto Fire** | Every 5 minutes | `livecad.xml` CAD feed |
| **Toronto Police** | Every 10 minutes | ArcGIS FeatureServer |
| **TTC Transit** | Every 5 minutes | alerts.ttc.ca JSON API + SXA |
| **GO Transit** | Every 5 minutes | `api.metrolinx.com` JSON API |

In production, the project uses a dedicated scheduler container (`docker/scheduler/Dockerfile`) that runs `php artisan scheduler:run-and-log` every minute, providing detailed logs and a health check heartbeat.

Empty feed protection is enforced by default (`ALLOW_EMPTY_FEEDS=false`) to prevent mass deactivation when upstream feeds return zero results unexpectedly.

---

## Production Data Seeding

To migrate locally scraped alert data into production as code-managed seeders:

```bash
# Generate + verify seeder files
./scripts/generate-production-seed.sh --sail

# Or run commands directly
php artisan db:export-to-seeder
php artisan db:verify-production-seed
```

Forge deployment runbook:
- **[docs/deployment/production-seeding.md](docs/deployment/production-seeding.md)**

---

## Testing

The project uses Pest PHP for feature and unit testing.

```bash
# Run all tests
composer run test

# Run tests with coverage (Sail)
php artisan sail --args=pest --args=--coverage
```

Manual test scripts under `tests/manual` are destructive and only run
when `APP_ENV=testing` and `DB_DATABASE=gta_alerts_testing`. There is no
override for this guard. Use the `.env.testing` MySQL config, for
example:

```bash
APP_ENV=testing ./vendor/bin/sail php tests/manual/verify_phase_1_foundations.php
```

## Feed Query Parameters

The live feed (`/`) and infinite-scroll API endpoint (`/api/feed`) support:

- `status`: `all`, `active`, `cleared`
- `source`: `fire`, `police`, `transit`, `go_transit`
- `q`: free-text search (trimmed, max 200 chars)
- `since`: `30m`, `1h`, `3h`, `6h`, `12h`
- `cursor`: opaque base64url cursor for deterministic next-batch loading

Example:

```bash
curl "http://localhost/api/feed?status=active&source=fire&q=alarm&since=1h"
```

---

## Documentation

For detailed documentation on architecture, implementation, and development:

- **[docs/](docs/)** - Comprehensive project documentation
- **[CLAUDE.md](CLAUDE.md)** - Agent guidance and development conventions
- **[docs/CHANGELOG.md](docs/CHANGELOG.md)** - Version history and recent changes

### Key Documentation Topics

- **[Unified Alerts System](docs/backend/unified-alerts-system.md)** - Core architecture with server-side filters and cursor infinite scroll
- **[Provider & Adapter Pattern](docs/architecture/provider-adapter-pattern.md)** - Data source integration pattern
- **[Frontend Types](docs/frontend/types.md)** - TypeScript domain types and presentation mapping
- **[AlertService](docs/frontend/alert-service.md)** - Frontend transport/domain mapping and live-feed boundary contract
- **[Production Scheduler](docs/backend/production-scheduler.md)** - Background job observability
- **[Scheduler Runbooks](docs/runbooks/scheduler-troubleshooting.md)** - Operations and recovery guide

### Data Source Documentation

- **[Toronto Fire](docs/sources/toronto-fire.md)** - CAD XML feed integration
- **[Toronto Police](docs/sources/toronto-police.md)** - ArcGIS FeatureServer integration
- **[TTC Transit](docs/sources/ttc-transit.md)** - Composite feed (API + SXA + static)
- **[GO Transit](docs/sources/go-transit.md)** - Metrolinx JSON API integration

---

## License

This project is licensed under the **GNU Public License (GPL)**.
