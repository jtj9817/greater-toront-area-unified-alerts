# Greater Toronto Area Alerts (GTA Alerts)

A real-time dashboard for emergency services and transit alerts across the Greater Toronto Area. This project provides a unified, live view of fire, police, and other emergency incidents, aggregating data from multiple municipal sources into a single, cohesive feed.

## Project Overview

GTA Alerts is built as a data aggregator and API using Laravel, with a high-performance React frontend powered by Inertia.js. It features automated scraping subsystems that pull live data from Toronto Open Data feeds (CAD systems) and ArcGIS Feature Servers.

### Key Features

- **Unified Alert Feed:** A mixed-source timeline of Fire and Police incidents, supporting pagination over both active and cleared history.
- **Toronto Fire Integration:** Real-time sync with the Toronto Fire Services CAD (Computer Aided Dispatch) XML feed.
- **Toronto Police Integration:** Automated scraping of the TPS "Calls for Service" dashboard using browser automation.
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

1.  **Source Models:** Raw data is persisted in source-specific tables (`fire_incidents`, `police_calls`).
2.  **Select Providers:** Standardized query providers map source-specific columns into a unified SQL structure.
3.  **Unified Aggregator:** A database-level `UNION` query facilitates stable pagination across all alert types simultaneously.
4.  **Inertia Transport:** Standardized DTOs are passed to the frontend, where they are mapped to a common UI view-model.

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
```

---

## Data Synchronization

The application uses the Laravel Scheduler to keep data fresh.

- **Toronto Fire:** Synced every 5 minutes from `livecad.xml`.
- **Toronto Police:** Synced every 10–20 minutes via ArcGIS FeatureServer interception.

In production, the project uses a dedicated scheduler container (`docker/scheduler/Dockerfile`) that runs `php artisan scheduler:run-and-log` every minute, providing detailed logs and a health check heartbeat.

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

---

## License

This project is licensed under the **GNU Public License (GPL)**.
