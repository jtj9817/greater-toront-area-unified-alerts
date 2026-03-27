# Specification: Weather Feature (GTA Alerts)

## Overview
Replace the hardcoded weather placeholder in the GTA Alerts footer with live, location-aware weather data scoped to a user-selected GTA Forward Sortation Area (FSA).

Primary data source is Environment Canada, normalized into a single backend DTO (`WeatherData`) and emitted as a `snake_case` API resource (`WeatherResource`). The system uses a two-layer caching model (fast Laravel cache store + durable `weather_caches` table) and persists the user’s chosen location in `localStorage` (`gta_weather_location_v1`).

Source plan: `docs/plans/weather-feature-plan.md`.

## Functional Requirements
- **Location selection**
  - Search by FSA prefix, municipality, or neighbourhood from a curated `gta_postal_codes` allowlist (approx 200 rows).
  - Browser geolocation resolves to the nearest allowlisted FSA (with coarse GTA bounds checks).
  - User-selected location persists across sessions.
  - Search results must return stable ordering and only the selector fields needed by the frontend (`fsa`, `display_name`, `lat`, `lng`).
- **Weather display**
  - Footer displays current conditions (temperature, humidity, wind, condition text) for the selected FSA.
  - Alert headline may be shown; `alert_level` must only be set when an explicit upstream signal exists (no inference from condition text).
  - Stale cached weather may remain visible while background refresh occurs (explicit UX choice).
  - Frontend weather state must expose explicit loading, stale, success, and error states rather than relying on implicit UI behavior.
- **Backend architecture**
  - Provider contract supports ordered fallbacks via config (`WEATHER_PROVIDERS`).
  - Provider failures are handled gracefully; upstream failures return 503 for `/api/weather`.
  - Cross-DB-driver compatibility is required for search and nearest-FSA resolution (SQLite/MySQL/PostgreSQL).
  - The durable cache layer must round-trip the canonical DTO payload cleanly and support fast-cache rewarming after durable hits.
  - Controllers remain thin: validation, orchestration, and transport mapping only; fetch, parsing, lookup, and cache logic belong in models / services.

## Non-Goals
- Street-address geocoding.
- Hyperlocal accuracy beyond centroid precision.
- Full forecast UI; current conditions only.
- Warming all FSAs on day one.
- Inferring alert severity from generic weather copy.

## Canonical Contracts
- **Location source of truth**
  - `gta_postal_codes` is the canonical allowlist for manual search and geolocation resolution.
  - `App\Models\GtaPostalCode` owns FSA normalization, search behavior, nearest-FSA lookup, and display-label generation.
- **Weather source of truth**
  - `App\Services\Weather\DTOs\WeatherData` is the canonical normalized weather contract inside the backend.
  - `App\Services\Weather\Contracts\WeatherProvider` is the single provider interface used by all upstream integrations.
  - `App\Services\Weather\Exceptions\WeatherFetchException` is the canonical provider/service failure type.
- **HTTP transport**
  - `/api/weather` emits `snake_case` fields through `App\Http\Resources\WeatherResource`.
  - `/api/postal-codes` and `/api/postal-codes/resolve-coords` return only the minimal location payload required by the selector.
- **Frontend contract**
  - `resources/js/features/gta-alerts/domain/weather/resource.ts` defines the raw API schema.
  - `resources/js/features/gta-alerts/domain/weather/types.ts` defines the frontend domain shape.
  - `resources/js/features/gta-alerts/domain/weather/fromResource.ts` is the only mapping layer from transport to domain state.

## Runtime and Provider Requirements
- `config/weather.php` must define provider order, timeouts, retry policy, fast-cache TTLs, and durable-cache freshness windows.
- Environment Canada is the default provider and must be production-ready without any optional fallback enabled.
- Any fallback provider remains disabled unless explicitly enabled by configuration.
- Provider implementations must normalize into the same finite `condition_code` set and must only populate `alert_level` from explicit upstream signals.
- Unusable upstream responses must fail explicitly rather than returning partially inferred weather data.

## API and UI Behavior Requirements
- `GET /api/postal-codes` must validate query length, support normalized FSA lookups, and cap results.
- `POST /api/postal-codes/resolve-coords` must reject non-GTA coordinates before nearest-FSA resolution.
- `GET /api/weather` must normalize the incoming postal code, delegate to `WeatherCacheService`, and return 422 for invalid input or 503 when no provider can supply usable data.
- `useWeather` owns persisted location state, weather fetch state, and stale-while-revalidate behavior.
- `LocationPicker` owns location search / geolocation UX only; it must not duplicate weather-fetch logic already owned by `useWeather`.
- `Footer` renders the normalized live-weather state and must handle empty, loading, success, stale, and alert-headline cases.

## Delivery and Verification Requirements
- The work is delivered in six phases:
  1. Backend foundation
  2. Provider + cache services
  3. Backend API endpoints
  4. Frontend state + components
  5. QA phase
  6. Documentation phase
- The final two phases are mandatory and must remain:
  - **QA Phase**: targeted weather regressions, full Laravel / Vitest test runs, coverage verification, formatting, static checks, and dependency audits.
  - **Documentation Phase**: technical docs, project-facing docs, and Conductor track closeout / archive updates.
- Each implementation phase requires matching automated tests before completion.
- Coverage for the weather-related backend modules must meet the project threshold (`>= 90%`) during the QA phase.
- Documentation must record environment variables, cache behavior, upstream-failure behavior, and any intentional deviations from `docs/plans/weather-feature-plan.md`.
