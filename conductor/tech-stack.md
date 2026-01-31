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
- **Database:** MySQL 8.4 (Managed via Sail)
- **Caching/Queue:** Redis (Alpine-based, via Sail)
- **Data Fetching:** Laravel HTTP Client for external GTA open data feeds

## Quality & Tooling
- **Testing:** Pest PHP
- **Linting:** 
    - **PHP:** Laravel Pint
    - **JS/TS:** ESLint & Prettier
- **Static Analysis:** TypeScript (Strict mode)
