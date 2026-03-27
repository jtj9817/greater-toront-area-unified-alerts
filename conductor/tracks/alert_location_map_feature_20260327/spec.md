# Specification: Alert Location Map (Leaflet + OpenStreetMap)

## Overview
Replace the current placeholder-only location treatment in the GTA Alerts details view with a real Leaflet + OpenStreetMap map whenever an alert already includes usable coordinates. Keep the first release frontend-only, preserve the existing Inertia/API contract, and make the feature testable through a strict presentation-boundary seam.

## Architecture Direction
- The feature must ride on the existing `alerts.data[*].location` payload returned by `UnifiedAlertResource`; no new top-level Inertia prop or backend schema change is part of this track.
- Raw `location.name`, `location.lat`, and `location.lng` must remain transport/domain concerns. The UI should consume a normalized presentation field such as `locationCoords`, not raw nested transport data.
- The shared `Location Map` section belongs in the common alert details layout, immediately after the `Official Briefing` card, so all alert kinds inherit the same behavior.
- Fire-specific content such as `SceneIntelTimeline` remains specialized content and should not own the location-map rendering path.
- Browser-only Leaflet code and Leaflet CSS must be isolated behind a client-only boundary so the Inertia SSR build never eagerly imports `leaflet` or `react-leaflet`.

## Functional Requirements
- Render a real map when both latitude and longitude are present, finite, and geographically valid.
- Treat missing, partial, non-finite, out-of-range, or `0,0` coordinates as non-renderable and surface them as `locationCoords: null` at the presentation boundary.
- Preserve the existing human-readable location label even when a map cannot be rendered.
- Replace the current placeholder text (`Interactive Map Loading...`) with either:
  - a real map for renderable coordinates, or
  - an explicit unavailable-state card when coordinates are not renderable.
- Use the public OpenStreetMap raster tile URL for v1 and keep attribution visible.
- Configure Leaflet marker assets so marker icons resolve correctly through Vite.
- Add deterministic IDs for the shared location section, the map wrapper, and the unavailable-state wrapper based on the alert ID.
- Respect mobile scrolling by avoiding map interaction patterns that trap vertical page scrolling on small viewports.

## Non-Functional Requirements
- This is a frontend-first track. Backend/controller/resource changes are only valid if a test proves the existing contract is insufficient.
- No CSP expansion is expected for v1 because raster tiles load as cross-origin images and current `img-src` policy already permits `https:`.
- The feature must be implemented with TDD discipline:
  - lock down the transport/partial-reload contract first,
  - add failing mapper tests before presentation changes,
  - add failing component/detail-view tests before UI integration.
- Vitest coverage should rely on mocked `react-leaflet` primitives rather than real Leaflet rendering in JSDOM.
- The final implementation must pass both the client build and the SSR build.
- Tile URL and attribution must live behind a small seam so a hosted OSM-compatible provider can replace them later without refactoring the details view.

## Acceptance Criteria
- Alerts with renderable coordinates show a real map with a marker in the details view.
- Alerts without renderable coordinates show a truthful unavailable-state card and still display the location label.
- The location section is shared across fire, police, TTC, and GO detail layouts instead of being embedded in a fire-only branch.
- The frontend preserves coordinates through the presentation boundary as `locationCoords` and does not force components to inspect raw transport objects.
- The `alerts` Inertia prop remains the sole contract boundary for this feature, including partial reloads.
- The implementation passes targeted mapper/component/detail tests, the existing GTA Alerts feature tests, the SSR build, and the full QA suite.

## Out of Scope
- Geocoding or reverse geocoding for alerts that currently lack coordinates.
- Provider-side coordinate enrichment for fire, TTC, or GO data.
- Backend API redesign, new Inertia prop keys, or schema changes for map support.
- Advanced map features such as clustering, routing, multiple markers, or paid tile providers.
