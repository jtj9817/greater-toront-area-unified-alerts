# Repository Guidelines

## Project Structure & Module Organization
- `app/`: Laravel application code (controllers, models, services).
- `app/Services/TorontoFireFeedService.php`: Fetches Toronto Fire CAD XML feed.
- `app/Console/Commands/FetchFireIncidentsCommand.php`: Syncs feed data into `fire_incidents`.
- `app/Jobs/FetchFireIncidentsJob.php`: Queue wrapper for scheduled fetches.
- `app/Models/FireIncident.php`: Incident model + `active()` scope.
- `routes/`: HTTP routes; start with `routes/web.php`.
- `routes/web.php`: Home route renders the GTA Alerts Inertia page.
- `resources/`: Frontend assets (Inertia + React components, CSS, JS/TS).
- `resources/js/features/gta-alerts/`: GTA Alerts UI, services, and mock data.
- `database/`: Migrations, seeders, and factories.
- `database/migrations/*create_fire_incidents_table.php`: Incident storage and indexes.
- `tests/`: Pest tests for application and feature coverage.

## Build, Test, and Development Commands
- `composer setup`: One-time setup (installs deps, creates `.env`, generates key, migrates, builds assets).
- `composer dev`: Runs Laravel server, queue, log tailing, and Vite dev server concurrently.
- `npm run dev`: Frontend hot-reload only (Vite).
- `npm run build`: Production asset build.
- `composer test`: Clears config, runs Pint in test mode, then runs `php artisan test`.
- `npm run lint`: ESLint with auto-fix across the repo.
- `npm run format`: Prettier formatting for `resources/`.
- `php artisan fire:fetch-incidents`: Manually syncs Toronto Fire feed to the database.

## Coding Style & Naming Conventions
- Indentation: 4 spaces (see `.editorconfig`). YAML uses 2 spaces.
- PHP: Follow Laravel conventions; format with `pint` (`composer lint`).
- Frontend: Format with Prettier; lint with ESLint.
- Names: Use clear, descriptive names; React components in `PascalCase` (e.g., `AlertCard.tsx`), hooks in `camelCase` (e.g., `useAlerts.ts`).

## Testing Guidelines
- Framework: Pest (`php artisan test`).
- Place tests in `tests/`, mirroring the app structure.
- Keep test names descriptive; group related tests in the same file.
- Auth and settings flows are covered in `tests/Feature/Auth/*` and `tests/Feature/Settings/*`.

## Commit & Pull Request Guidelines
- Commit messages are concise and imperative; a conventional prefix is acceptable (e.g., `feat: add alert filtering`).
- PRs should include: a short description, linked issue (if any), and screenshots for UI changes in `resources/`.
- Note any migrations, config changes, or new environment variables.

## Security & Configuration Tips
- Use `.env.example` as the template; do not commit secrets.
- If you add new env vars, document them and keep defaults safe for local development.

## Domain Notes: GTA Alerts
- Landing page: `resources/js/pages/gta-alerts.tsx` mounts `resources/js/features/gta-alerts/App.tsx`.
- Feed UI uses mock data in `resources/js/features/gta-alerts/constants.ts`.
- Filtering/search lives in `resources/js/features/gta-alerts/services/AlertService.ts`.
