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
- **Weather display**
  - Footer displays current conditions (temperature, humidity, wind, condition text) for the selected FSA.
  - Alert headline may be shown; `alert_level` must only be set when an explicit upstream signal exists (no inference from condition text).
  - Stale cached weather may remain visible while background refresh occurs (explicit UX choice).
- **Backend architecture**
  - Provider contract supports ordered fallbacks via config (`WEATHER_PROVIDERS`).
  - Provider failures are handled gracefully; upstream failures return 503 for `/api/weather`.
  - Cross-DB-driver compatibility is required for search and nearest-FSA resolution (SQLite/MySQL/PostgreSQL).

## Non-Goals
- Street-address geocoding.
- Hyperlocal accuracy beyond centroid precision.
- Full forecast UI; current conditions only.
- Warming all FSAs on day one.

