# Implementation Plan: Alert Location Map (Leaflet + OpenStreetMap)

## Phase 1: Contract Guardrails and Runtime Foundation

- [~] Task: Lock down the existing location transport contract before UI work
    - [ ] Extend `tests/Feature/GtaAlertsTest.php` with a fixture that includes real coordinates and assert `alerts.data.*.location` still exposes `name`, `lat`, and `lng`.
    - [ ] Add an Inertia partial-reload assertion using `reloadOnly('alerts', ...)` to prove the feature rides the existing `alerts` prop instead of adding a new top-level page prop.
    - [ ] Confirm the current `UnifiedAlertResource` contract is sufficient; do not change controllers/resources unless the new tests prove a gap.
- [ ] Task: Add Leaflet runtime dependencies through Sail
    - [ ] Add `leaflet` and `react-leaflet` to `package.json` dependencies.
    - [ ] Add `@types/leaflet` to `package.json` devDependencies.
    - [ ] Update the lockfile and ensure dependency installation is reproducible inside `vendor/bin/sail`.
- [ ] Task: Define the SSR-safe loading seam before importing Leaflet
    - [ ] Plan for `resources/js/features/gta-alerts/components/AlertLocationMap.tsx` to be the SSR-safe wrapper only.
    - [ ] Plan for `resources/js/features/gta-alerts/components/AlertLocationMap.client.tsx` to own all `leaflet` / `react-leaflet` imports.
    - [ ] Import `leaflet/dist/leaflet.css` only from the client-side module so CSS stays coupled to the lazy-loaded map chunk instead of the global app entry.
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Contract Guardrails and Runtime Foundation' (Protocol in workflow.md)

## Phase 2: Presentation Boundary and Coordinate Eligibility

- [ ] Task: Write failing mapper tests for normalized coordinates first
    - [ ] Update `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.test.ts` to assert valid coordinates become `locationCoords`.
    - [ ] Add failing cases for `null`, partial, non-finite, out-of-range, and `0,0` coordinates resolving to `locationCoords: null`.
    - [ ] Keep an assertion that the human-readable `location` label still falls back to `Unknown location` when needed.
- [ ] Task: Implement normalized presentation coordinates
    - [ ] Add `AlertPresentationCoordinates` and `locationCoords` to `resources/js/features/gta-alerts/domain/alerts/view/types.ts`.
    - [ ] Implement a single normalization/eligibility seam inside `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.ts`.
    - [ ] Ensure UI components consume `locationCoords` and never have to inspect raw `alert.location.lat/lng`.
- [ ] Task: Protect the surrounding frontend contract
    - [ ] Run and fix any impacted alert-domain tests so the new presentation field does not regress existing domain parsing.
    - [ ] Only touch `resources/js/features/gta-alerts/domain/alerts/resource.ts` or related transport code if the contract tests expose a real mismatch.
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Presentation Boundary and Coordinate Eligibility' (Protocol in workflow.md)

## Phase 3: Shared Client-Only Map Components

- [ ] Task: Write failing component tests before building the map surface
    - [ ] Create `resources/js/features/gta-alerts/components/AlertLocationMap.test.tsx`.
    - [ ] Mock `react-leaflet` primitives so the tests assert wrapper behavior, tile attribution text, stable IDs, and fallback semantics without real Leaflet runtime.
    - [ ] Add explicit tests for the unavailable-state component showing the location label and truthful copy.
- [ ] Task: Implement shared Leaflet runtime setup
    - [ ] Create `resources/js/features/gta-alerts/lib/leaflet.ts` to configure default marker assets for Vite-bundled image URLs.
    - [ ] Keep marker configuration in one place so future map surfaces reuse the same setup.
- [ ] Task: Implement the client-only map module
    - [ ] Build `AlertLocationMap.client.tsx` with `MapContainer`, `TileLayer`, `Marker`, and `Popup`.
    - [ ] Use the public OSM raster tile URL and visible attribution.
    - [ ] Add an explicit height and isolated wrapper to avoid map collapse and z-index bleed.
    - [ ] Use the existing `resources/js/hooks/use-mobile.tsx` hook instead of ad-hoc `window.matchMedia(...)` checks to reduce scroll-trap behavior on small viewports.
- [ ] Task: Implement the SSR-safe wrapper and unavailable state
    - [ ] Build `AlertLocationMap.tsx` as the lazy wrapper that never imports Leaflet directly.
    - [ ] Export or colocate an `AlertLocationUnavailable` component for non-renderable coordinates.
    - [ ] Pass a stable `idBase` or equivalent alert-derived identifier into the wrapper so DOM IDs remain deterministic.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Shared Client-Only Map Components' (Protocol in workflow.md)

## Phase 4: Shared Alert Details Integration

- [ ] Task: Write failing detail-view tests before refactoring layout
    - [ ] Extend `resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx` to assert a police alert with coordinates renders the shared location-map section.
    - [ ] Add failing tests asserting fire alerts without coordinates render the unavailable-state card instead of placeholder copy.
    - [ ] Add assertions that the legacy `Interactive Map Loading...` text no longer appears for any branch.
    - [ ] Add assertions for stable IDs on the shared location section and its map/unavailable child container.
- [ ] Task: Move location rendering into the shared details layout
    - [ ] Refactor `resources/js/features/gta-alerts/components/AlertDetailsView.tsx` so the location section renders in the common layout immediately after `Official Briefing`.
    - [ ] Render the map when `alert.locationCoords` exists and the unavailable state otherwise.
    - [ ] Preserve all existing branch-specific metadata and specialized content blocks.
- [ ] Task: Keep fire-specific specialized content focused
    - [ ] Remove the map placeholder card from the fire specialized branch.
    - [ ] Keep `SceneIntelTimeline` and other fire-only content independent of the shared location section.
    - [ ] Confirm police, TTC, and GO branches do not need duplicated map logic after the refactor.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Shared Alert Details Integration' (Protocol in workflow.md)

## Phase 5: QA (Full Testing Suite)

- [ ] Task: Run targeted tests first for fast feedback
    - [ ] Run `vendor/bin/sail pnpm test -- mapDomainAlertToPresentation.test.ts AlertLocationMap.test.tsx AlertDetailsView.test.tsx`.
    - [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/GtaAlertsTest.php`.
- [ ] Task: Run full frontend and backend quality gates
    - [ ] Run `vendor/bin/sail pnpm types`.
    - [ ] Run `vendor/bin/sail pnpm lint:check`.
    - [ ] Run `vendor/bin/sail pnpm test`.
    - [ ] Run `vendor/bin/sail artisan test --compact`.
    - [ ] Run `vendor/bin/sail artisan test --coverage --min=90`.
- [ ] Task: Verify build and security behavior for the SSR app
    - [ ] Run `vendor/bin/sail pnpm run build:ssr` to catch SSR import regressions.
    - [ ] Run `vendor/bin/sail artisan test --compact tests/Feature/Security/SecurityHeadersTest.php`.
    - [ ] Only modify CSP/security-header code if the implementation proves a real regression.
- [ ] Task: Run final audits and manual verification
    - [ ] Run `vendor/bin/sail bin pint --dirty --format agent`.
    - [ ] Run `vendor/bin/sail composer audit` and `vendor/bin/sail pnpm audit`.
    - [ ] Verify in the browser that alerts with coordinates render a working map, alerts without coordinates render the unavailable state, and mobile scrolling remains usable.
- [ ] Task: Conductor - User Manual Verification 'Phase 5: QA (Full Testing Suite)' (Protocol in workflow.md)

## Phase 6: Documentation Phase

- [ ] Task: Document the finalized feature behavior
    - [ ] Update the relevant project docs in `docs/` with the final location-map behavior, coordinate eligibility rules, and the no-geocoding scope for v1.
    - [ ] Document the tile-provider seam and the reason no CSP expansion was required.
- [ ] Task: Update repository-facing operational notes only where warranted
    - [ ] Update `README.md` only if the map changes user-visible project capabilities that belong there.
    - [ ] Update `CLAUDE.md` only if the implementation introduces a lasting repository convention worth preserving.
- [ ] Task: Close out conductor artifacts for archival readiness
    - [ ] Reconcile any implementation deviations back into `spec.md` / `plan.md`.
    - [ ] Ensure the track is ready for registry/archive handoff after QA sign-off.
- [ ] Task: Conductor - User Manual Verification 'Phase 6: Documentation Phase' (Protocol in workflow.md)
