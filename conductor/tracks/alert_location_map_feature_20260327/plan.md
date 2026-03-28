# Implementation Plan: Alert Location Map (Leaflet + OpenStreetMap)

## Phase 1: Contract Guardrails and Runtime Foundation [checkpoint: 5210db6]

- [x] Task: Lock down the existing location transport contract before UI work
    - [x] Extend `tests/Feature/GtaAlertsTest.php` with a fixture that includes real coordinates and assert `alerts.data.*.location` still exposes `name`, `lat`, and `lng`.
    - [x] Add an Inertia partial-reload assertion using `reloadOnly('alerts', ...)` to prove the feature rides the existing `alerts` prop instead of adding a new top-level page prop.
    - [x] Confirm the current `UnifiedAlertResource` contract is sufficient; do not change controllers/resources unless the new tests prove a gap.
- [x] Task: Add Leaflet runtime dependencies through Sail
    - [x] Add `leaflet` and `react-leaflet` to `package.json` dependencies.
    - [x] Add `@types/leaflet` to `package.json` devDependencies.
    - [x] Update the lockfile and ensure dependency installation is reproducible inside `vendor/bin/sail`.
- [x] Task: Define the SSR-safe loading seam before importing Leaflet
    - [x] Plan for `resources/js/features/gta-alerts/components/AlertLocationMap.tsx` to be the SSR-safe wrapper only.
    - [x] Plan for `resources/js/features/gta-alerts/components/AlertLocationMap.client.tsx` to own all `leaflet` / `react-leaflet` imports.
    - [x] Import `leaflet/dist/leaflet.css` only from the client-side module so CSS stays coupled to the lazy-loaded map chunk instead of the global app entry.
- [x] Task: Conductor - User Manual Verification 'Phase 1: Contract Guardrails and Runtime Foundation' (Protocol in workflow.md) [01c6300]

## Phase 2: Presentation Boundary and Coordinate Eligibility

- [x] Task: Write failing mapper tests for normalized coordinates first [9f7debf]
    - [x] Update `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.test.ts` to assert valid coordinates become `locationCoords`.
    - [x] Add failing cases for `null`, partial, non-finite, out-of-range, and `0,0` coordinates resolving to `locationCoords: null`.
    - [x] Keep an assertion that the human-readable `location` label still falls back to `Unknown location` when needed.
- [x] Task: Implement normalized presentation coordinates [9f7debf]
    - [x] Add `AlertPresentationCoordinates` and `locationCoords` to `resources/js/features/gta-alerts/domain/alerts/view/types.ts`.
    - [x] Implement a single normalization/eligibility seam inside `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.ts`.
    - [x] Ensure UI components consume `locationCoords` and never have to inspect raw `alert.location.lat/lng`.
- [x] Task: Protect the surrounding frontend contract [9f7debf]
    - [x] Run and fix any impacted alert-domain tests so the new presentation field does not regress existing domain parsing.
    - [x] Only touch `resources/js/features/gta-alerts/domain/alerts/resource.ts` or related transport code if the contract tests expose a real mismatch.
- [x] Task: Conductor - User Manual Verification 'Phase 2: Presentation Boundary and Coordinate Eligibility' (Protocol in workflow.md) [d841233]

## Phase 3: Shared Client-Only Map Components

- [x] Task: Write failing component tests before building the map surface [1f9587c]
    - [x] Create `resources/js/features/gta-alerts/components/AlertLocationMap.test.tsx`.
    - [x] Mock `react-leaflet` primitives so the tests assert wrapper behavior, tile attribution text, stable IDs, and fallback semantics without real Leaflet runtime.
    - [x] Add explicit tests for the unavailable-state component showing the location label and truthful copy.
- [x] Task: Implement shared Leaflet runtime setup [1f9587c]
    - [x] Create `resources/js/features/gta-alerts/lib/leaflet.ts` to configure default marker assets for Vite-bundled image URLs.
    - [x] Keep marker configuration in one place so future map surfaces reuse the same setup.
- [x] Task: Implement the client-only map module [1f9587c]
    - [x] Build `AlertLocationMap.client.tsx` with `MapContainer`, `TileLayer`, `Marker`, and `Popup`.
    - [x] Use the public OSM raster tile URL and visible attribution.
    - [x] Add an explicit height and isolated wrapper to avoid map collapse and z-index bleed.
    - [x] Use the existing `resources/js/hooks/use-mobile.tsx` hook instead of ad-hoc `window.matchMedia(...)` checks to reduce scroll-trap behavior on small viewports.
- [x] Task: Implement the SSR-safe wrapper and unavailable state [1f9587c]
    - [x] Build `AlertLocationMap.tsx` as the lazy wrapper that never imports Leaflet directly.
    - [x] Export or colocate an `AlertLocationUnavailable` component for non-renderable coordinates.
    - [x] Pass a stable `idBase` or equivalent alert-derived identifier into the wrapper so DOM IDs remain deterministic.
- [x] Task: Conductor - User Manual Verification 'Phase 3: Shared Client-Only Map Components' (Protocol in workflow.md) [3ae4bef]

## Phase 4: Shared Alert Details Integration

- [x] Task: Write failing detail-view tests before refactoring layout [c01e16a]
    - [x] Extend `resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx` to assert a police alert with coordinates renders the shared location-map section.
    - [x] Add failing tests asserting fire alerts without coordinates render the unavailable-state card instead of placeholder copy.
    - [x] Add assertions that the legacy `Interactive Map Loading...` text no longer appears for any branch.
    - [x] Add assertions for stable IDs on the shared location section and its map/unavailable child container.
- [x] Task: Move location rendering into the shared details layout [c01e16a]
    - [x] Refactor `resources/js/features/gta-alerts/components/AlertDetailsView.tsx` so the location section renders in the common layout immediately after `Official Briefing`.
    - [x] Render the map when `alert.locationCoords` exists and the unavailable state otherwise.
    - [x] Preserve all existing branch-specific metadata and specialized content blocks.
- [x] Task: Keep fire-specific specialized content focused [c01e16a]
    - [x] Remove the map placeholder card from the fire specialized branch.
    - [x] Keep `SceneIntelTimeline` and other fire-only content independent of the shared location section.
    - [x] Confirm police, TTC, and GO branches do not need duplicated map logic after the refactor.
- [x] Task: Conductor - User Manual Verification 'Phase 4: Shared Alert Details Integration' (Protocol in workflow.md) [fd758d0]

## Phase 5: QA (Full Testing Suite) [checkpoint: 633f518]

- [x] Task: Run targeted tests first for fast feedback
    - [x] Run `vendor/bin/sail pnpm test -- mapDomainAlertToPresentation.test.ts AlertLocationMap.test.tsx AlertDetailsView.test.tsx`.
    - [x] Run `vendor/bin/sail artisan test --compact tests/Feature/GtaAlertsTest.php`.
- [x] Task: Run full frontend and backend quality gates
    - [x] Run `vendor/bin/sail pnpm types`.
    - [x] Run `vendor/bin/sail pnpm lint:check`.
    - [x] Run `vendor/bin/sail pnpm test`.
    - [x] Run `vendor/bin/sail artisan test --compact`.
    - [x] Run `vendor/bin/sail artisan test --coverage --min=90`.
- [x] Task: Verify build and security behavior for the SSR app
    - [x] Run `vendor/bin/sail pnpm run build:ssr` to catch SSR import regressions.
    - [x] Run `vendor/bin/sail artisan test --compact tests/Feature/Security/SecurityHeadersTest.php`.
    - [x] Only modify CSP/security-header code if the implementation proves a real regression.
- [x] Task: Run final audits and manual verification
    - [x] Run `vendor/bin/sail bin pint --dirty --format agent`.
    - [x] Run `vendor/bin/sail composer audit` and `vendor/bin/sail pnpm audit`.
    - [x] Verify in the browser that alerts with coordinates render a working map, alerts without coordinates render the unavailable state, and mobile scrolling remains usable.
- [x] Task: Conductor - User Manual Verification 'Phase 5: QA (Full Testing Suite)' (Protocol in workflow.md) [633f518]

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
