# Specification: Alert Location Map (Leaflet + OpenStreetMap)

## Overview
Replace the GTA Alerts detail-view location placeholder with a real Leaflet + OpenStreetMap map for alerts that already provide coordinates. Preserve existing location labels, add a clear unavailable state for non-mappable alerts, and keep the implementation frontend-only for v1.

## Functional Requirements
- Render a real map in the alert details view when both latitude and longitude are valid finite numbers.
- Preserve and expose coordinates through the frontend presentation boundary (`locationCoords`) without backend schema changes.
- Replace the fire-only placeholder path with a shared map/fallback location section across alert types.
- Render an explicit "Map unavailable for this alert" UI when coordinates are missing or invalid.
- Keep map behavior SSR-safe by isolating browser-only Leaflet rendering behind a client-only boundary.
- Use OpenStreetMap raster tiles with visible attribution for v1.
- Add deterministic element IDs for map section, map container, and unavailable-state container.

## Non-Functional Requirements
- No backend schema changes or geocoding work in this track.
- Preserve current security header behavior (`img-src` already permits `https:` for raster tiles).
- Keep implementation testable via Vitest with component-level map mocking.
- Keep map integration resilient for mobile interaction (avoid page scroll trapping).
- Ensure implementation remains easy to switch to a hosted OSM-compatible tile provider later.

## Acceptance Criteria
- Alerts with valid coordinates show a real map with marker and popup in details view.
- Alerts without valid coordinates show a clear unavailable-state card, not a fake loading placeholder.
- Existing detail branches (fire, police, transit, GO) use the same location section behavior.
- Frontend tests validate coordinate mapping preservation and map/fallback rendering outcomes.
- Existing Inertia `alerts` prop contract remains unchanged.
- QA phase executes the full project testing suite and required quality checks successfully.

## Out of Scope
- Geocoding/reverse geocoding for fire, TTC, or GO alerts.
- Multi-marker clustering, route overlays, and advanced map interactions.
- Backend API contract redesign for location data.
- Paid/hosted map provider migration (left as a future follow-up).
