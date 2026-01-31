# Greater Toronto Area Alerts (GTA Alerts)

A real-time dashboard for emergency services and transit alerts across the Greater Toronto Area.

## Project Overview

- **Purpose:** To provide a unified, real-time view of fire, police, transit, and other emergency alerts in the GTA.
- **Architecture:** Laravel 12 backend serving as a data aggregator and API, with a React 19 frontend powered by Inertia.js for a seamless SPA experience.
- **Key Technologies:**
    - **Backend:** PHP 8.2+, Laravel 12, Inertia.js, Laravel Fortify (Auth).
    - **Frontend:** React 19, TypeScript, Vite, Tailwind CSS 4.0, Radix UI.
    - **Data Sources:** Live XML feeds from Toronto Open Data (e.g., Toronto Fire Live CAD).
    - **Testing:** Pest PHP.

## Getting Started

### Prerequisites
- PHP 8.2+
- Node.js & pnpm/npm
- SQLite (or other database supported by Laravel)

### Installation & Setup
```bash
# Full setup (installs dependencies, generates key, runs migrations, builds assets)
composer run setup

# Or manual steps:
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm install
npm run build
```

### Running the Application
```bash
# Start all development services (Server, Queue, Vite, Pail)
composer run dev
```

### Running Tests
```bash
# Run all tests using Pest
composer run test

# Run tests with coverage
php artisan sail --args=pest --args=--coverage
```

## Backend Services & Commands

### Toronto Fire Sync
The application fetches live fire incident data from the Toronto Fire Services CAD feed.
- **Service:** `App\Services\TorontoFireFeedService` handles XML fetching and parsing.
- **Command:** `php artisan fire:fetch-incidents` - Syncs active incidents to the database and marks resolved ones as inactive.
- **Model:** `App\Models\FireIncident` stores incident details like event type, location, and alarm level.

## Frontend Structure

The frontend is organized into features under `resources/js/features/`.
- **GTA Alerts Feature:** Located in `resources/js/features/gta-alerts/`.
    - `App.tsx`: Main entry point for the dashboard UI.
    - `components/`: UI components (Sidebar, FeedView, AlertDetailsView, etc.).
    - `services/AlertService.ts`: Client-side service for searching, filtering, and sorting alerts.
    - `constants.ts`: Currently holds static mock data (`ALERT_DATA`) which is being phased out for real backend data.

## Development Conventions

- **Code Style:** Laravel Pint is used for PHP linting. Run `composer run lint`. Prettier and ESLint are used for frontend.
- **Testing:** All new features should include Pest tests in `tests/Feature`.
- **UI/UX:** Adhere to the custom "GTA Alerts" theme defined in the CSS and Tailwind config. Use Radix UI primitives for accessible components.
- **Data Integration:** When moving from mock data to real data, update `AlertService.ts` to fetch from Laravel routes instead of static constants.
