# Specification: Weather Feature (GTA Alerts)

## Overview
Replace the hardcoded weather placeholder in the footer with live, location-aware weather data while preserving a GTA-specific location model. This feature will use Environment Canada as the primary data source and maintain a two-layer caching strategy (fast cache + database cache) for resilience.

## Functional Requirements
- **Location Selection:**
  - Users must explicitly select their location (via search or browser geolocation) before weather data is shown; there is no preselected default location.
  - Users can search for locations by Forward Sortation Area (FSA), municipality, or neighbourhood.
  - Browser geolocation resolves to the nearest supported GTA FSA.
  - The chosen location is persisted locally in `localStorage`.
- **Weather Display:**
  - The footer will display current conditions only (temperature, humidity, wind, condition description). Forecasts are excluded from this phase.
  - Stale cached weather data will remain visible in the UI during background revalidation (no loading skeleton on refresh).
  - The system will attempt to parse and display color-coded alert badges (yellow/orange/red) based on Environment Canada text content immediately.
- **Backend Architecture:**
  - A reference table (`gta_postal_codes`) will store approximately 200 GTA-focused FSAs.
  - A durable cache table (`weather_caches`) will store recent fetches as a fallback.
  - Environment Canada will be scraped as the primary provider, mapping data into a normalized `WeatherData` DTO.

## Non-Functional Requirements
- **Performance:** Two-layer caching strategy must minimize slow external HTTP requests.
- **Resilience:** The system must gracefully handle external provider outages or parsing failures.
- **Cross-Driver Support:** FSA searching and nearest-neighbour distance calculations must function correctly across SQLite, MySQL, and PostgreSQL.
- **Code Standards:** Backend responses must use `snake_case`, and frontend mappers must convert to `camelCase` following existing patterns.

## Out of Scope
- Street-address geocoding.
- Hyperlocal forecast accuracy beyond FSA centroid precision.
- A full weather dashboard or forecast module (only current conditions in footer).
- Background cache warming for all ~200 FSAs.
