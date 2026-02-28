# Tech Stack: GTA Alerts

## Backend (PHP/Laravel)
- **Core Framework:** Laravel 12.x
- **Environment:** Laravel Sail (Dockerized development environment)
- **Runtime:** PHP 8.4/8.5
- **Authentication:** Laravel Fortify
- **Communication:** Inertia.js (React Adapter)

## Frontend (React/TypeScript)
- **Library:** React 19
- **Build Tool:** Vite with Tailwind CSS 4.0
- **Styling:** Tailwind CSS 4.0 (CSS-first approach)
- **UI Components:** Radix UI primitives & Lucide React icons
- **State Management:** Inertia.js (Server-driven state)

## Infrastructure & Data
- **Database:** PostgreSQL 16 (production), MySQL 8.4 (local/dev via Sail), SQLite (tests/dev fallback)
- **Caching/Queue:** Redis (Alpine-based, via Sail)
- **Data Fetching:** Laravel HTTP Client for external GTA open data feeds

### Cross-Driver Search Behavior
The unified alert feed supports `q` (full-text search) on all three database drivers:
- **PostgreSQL:** Provider-level FTS using `to_tsvector('simple', ...) @@ plainto_tsquery('simple', ?)` (GIN index) plus an `ILIKE` substring fallback.
- **MySQL:** Provider-level `MATCH(...) AGAINST(... IN NATURAL LANGUAGE MODE)` plus a `LIKE` substring fallback.
- **SQLite:** Outer-query `LOWER(title) LIKE ? OR LOWER(location_name) LIKE ?` fallback (no native FTS).

Verification configs:
- `phpunit.xml` — SQLite (default, CI baseline)
- `phpunit.mysql.xml` — MySQL cross-driver suite
- `phpunit.pgsql.xml` — PostgreSQL cross-driver suite (`pgsql-testing` Sail service)

## Quality & Tooling
- **Testing:** Pest PHP
- **Linting:** 
    - **PHP:** Laravel Pint
    - **JS/TS:** ESLint & Prettier
- **Static Analysis:** TypeScript (Strict mode)
