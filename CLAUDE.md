# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

GTA Alerts is a real-time dashboard for emergency services and transit alerts across the Greater Toronto Area. Built with Laravel 12 (PHP 8.2+) backend and React 19 (TypeScript) frontend connected via Inertia.js. Currently integrates the Toronto Fire Services live CAD XML feed, with plans to add police, transit, and hazard data sources.

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

### Linting & Formatting
```bash
composer run lint           # PHP: Pint auto-fix
npm run lint                # JS/TS: ESLint auto-fix
npm run format              # JS/TS: Prettier auto-format
npm run types               # TypeScript type checking (tsc --noEmit)
```

### Data
```bash
php artisan fire:fetch-incidents   # Manually sync Toronto Fire feed
```

## Architecture

### Backend Data Pipeline
Toronto Fire XML feed → `TorontoFireFeedService` (fetch/parse) → `FetchFireIncidentsCommand` (artisan command, upserts via `event_num`) → `FireIncident` model. The command marks incidents no longer in the feed as `is_active = false`. `FetchFireIncidentsJob` wraps the command for queue dispatch. Scheduled every 5 minutes in `routes/console.php`.

### Frontend Structure
Inertia.js renders React pages from `resources/js/pages/`. The main public page is `gta-alerts.tsx` which mounts the feature module at `resources/js/features/gta-alerts/`. Feature modules contain their own components, services, types, and constants. `AlertService.ts` handles client-side filtering, search, and sorting. Currently uses mock data in `constants.ts` — being transitioned to real backend data.

### Routing
- `routes/web.php` — HTTP routes (home renders `gta-alerts` Inertia page; dashboard requires auth)
- `routes/console.php` — Scheduled commands
- `routes/settings.php` — Authenticated user settings

### Auth
Laravel Fortify handles authentication including two-factor auth. Settings controllers live in `app/Http/Controllers/Settings/`.

### Testing
Pest PHP with Feature and Unit suites. Tests use SQLite, sync queue, and array cache/session (configured in `phpunit.xml`). Existing coverage focuses on auth flows (`tests/Feature/Auth/`) and settings (`tests/Feature/Settings/`).

## Conventions

- PHP formatting: Laravel Pint (Laravel preset). Run `composer run lint`.
- JS/TS formatting: Prettier + ESLint with import sorting. Run `npm run format && npm run lint`.
- Indentation: 4 spaces (PHP/JS/TS), 2 spaces (YAML).
- React components: PascalCase filenames. Hooks: camelCase.
- UI components use Radix UI primitives wrapped in `resources/js/components/ui/`.
- Commit messages: imperative, concise. Conventional prefixes accepted (e.g., `feat:`, `fix:`).
