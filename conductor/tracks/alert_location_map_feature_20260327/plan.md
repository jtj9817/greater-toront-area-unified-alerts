# Implementation Plan: Alert Location Map (Leaflet + OpenStreetMap)

## Phase 1: Dependency and Frontend Foundation

- [ ] Task: Add map runtime dependencies and TypeScript types
    - [ ] Add `leaflet` and `react-leaflet` to runtime dependencies.
    - [ ] Add `@types/leaflet` to dev dependencies.
    - [ ] Install dependencies through Sail and ensure lockfile is updated.
- [ ] Task: Add shared Leaflet runtime configuration seam
    - [ ] Create `resources/js/features/gta-alerts/lib/leaflet.ts` to configure default marker icon assets for Vite.
    - [ ] Keep icon setup centralized for future map surfaces.
- [ ] Task: Introduce SSR-safe client-only map entry pattern
    - [ ] Add a client-only map component file that imports `leaflet/dist/leaflet.css`.
    - [ ] Add a wrapper component that lazy-loads the client map with fallback UI.
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Dependency and Frontend Foundation' (Protocol in workflow.md)

## Phase 2: Preserve Coordinates Through Presentation Mapping

- [ ] Task: Extend alert presentation types with coordinate support
    - [ ] Add `locationCoords: { lat: number; lng: number } | null` in presentation types.
    - [ ] Keep existing location string behavior intact for existing UI copy.
- [ ] Task: Preserve and validate coordinates in presentation mapper
    - [ ] Map domain `location.lat/lng` into `locationCoords` when both values are finite.
    - [ ] Apply coordinate guardrails (reject partial, non-finite, and out-of-bounds values).
- [ ] Task: Update mapper tests for coordinate retention and fallback behavior
    - [ ] Add test coverage for valid coordinates producing `locationCoords`.
    - [ ] Add test coverage for invalid/partial coordinates producing `null`.
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Preserve Coordinates Through Presentation Mapping' (Protocol in workflow.md)

## Phase 3: Build Shared Alert Map Components

- [ ] Task: Implement reusable alert map component
    - [ ] Create client map component using `MapContainer`, `TileLayer`, `Marker`, and `Popup`.
    - [ ] Use OSM raster tile URL `https://tile.openstreetmap.org/{z}/{x}/{y}.png` with attribution.
    - [ ] Add map container styling to prevent height collapse and z-index bleed.
- [ ] Task: Implement map unavailable-state component
    - [ ] Create explicit fallback UI for alerts without usable coordinates.
    - [ ] Preserve and display the human-readable location label in fallback state.
- [ ] Task: Add stable IDs and responsive behavior for map surfaces
    - [ ] Add deterministic IDs for map section, map container, and unavailable state.
    - [ ] Prevent mobile scroll-trap behavior by limiting map drag interaction on touch-sized viewports.
- [ ] Task: Add focused component tests for map and fallback units
    - [ ] Add `AlertLocationMap` tests using `react-leaflet` mocks.
    - [ ] Validate fallback rendering semantics and expected map section structure.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Build Shared Alert Map Components' (Protocol in workflow.md)

## Phase 4: Integrate Map Into Alert Details Experience

- [ ] Task: Replace placeholder path with shared location section renderer
    - [ ] Remove the old "Interactive Map Loading..." placeholder behavior.
    - [ ] Render map when `locationCoords` exists; render unavailable state otherwise.
- [ ] Task: Apply location section consistently across alert types
    - [ ] Integrate the shared location section into the common details rendering flow.
    - [ ] Preserve branch-specific content (e.g., fire scene timeline) without duplication.
- [ ] Task: Expand detail-view tests for map/fallback outcomes
    - [ ] Add tests covering police with valid coordinates.
    - [ ] Add tests covering fire/transit/GO alerts without coordinates.
    - [ ] Assert removal of legacy placeholder text.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Integrate Map Into Alert Details Experience' (Protocol in workflow.md)

## Phase 5: QA (Full Testing Suite)

- [ ] Task: Run complete backend and frontend automated test suites
    - [ ] Run `vendor/bin/sail artisan test --compact`.
    - [ ] Run `vendor/bin/sail pnpm test`.
    - [ ] Run `vendor/bin/sail artisan test --coverage --min=90`.
- [ ] Task: Run full quality gates and security checks
    - [ ] Run `vendor/bin/sail bin pint --dirty --format agent`.
    - [ ] Run `vendor/bin/sail pnpm types`.
    - [ ] Run `vendor/bin/sail pnpm lint:check`.
    - [ ] Run `vendor/bin/sail composer audit` and `vendor/bin/sail pnpm audit`.
- [ ] Task: Validate map behavior through manual browser verification checklist
    - [ ] Verify map rendering for alerts with coordinates.
    - [ ] Verify unavailable-state rendering for alerts without coordinates.
    - [ ] Verify mobile scrolling behavior and SSR-safe loading transition.
- [ ] Task: Conductor - User Manual Verification 'Phase 5: QA (Full Testing Suite)' (Protocol in workflow.md)

## Phase 6: Documentation Phase

- [ ] Task: Update feature and architecture documentation
    - [ ] Document alert-location map behavior and coordinate eligibility rules in `docs/`.
    - [ ] Document tile provider strategy and deferred geocoding scope.
- [ ] Task: Update repository-facing operational docs
    - [ ] Update `README.md` with map feature behavior where appropriate.
    - [ ] Update `CLAUDE.md` only if new long-term implementation conventions were introduced.
- [ ] Task: Finalize Conductor registry/archive readiness notes
    - [ ] Ensure track artifacts are complete and ready for archival handoff after implementation.
- [ ] Task: Conductor - User Manual Verification 'Phase 6: Documentation Phase' (Protocol in workflow.md)
