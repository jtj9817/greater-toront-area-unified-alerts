# Implementation Plan: Weather Feature (GTA Alerts) (FEED-015)

Source plan: `docs/plans/weather-feature-plan.md`.

## Phase 1: Backend Foundation (Postal Codes, Durable Cache, Contracts)

- [ ] Task: Establish `gta_postal_codes` as the canonical location reference
    - [ ] Write failing Pest coverage for `GtaPostalCode` normalization, driver-safe search ordering, coarse GTA bounds rejection, and nearest-FSA resolution.
    - [ ] Create `database/data/gta_postal_codes.php` with the curated GTA FSA dataset (approx 200 rows) and validate uniqueness / coordinate completeness.
    - [ ] Create the `gta_postal_codes` migration with the final schema and deterministic bulk insert from the data file.
    - [ ] Implement `App\Models\GtaPostalCode` helpers: `normalizeFsa()`, `search()`, `nearestTo()`, and `displayName()`.
    - [ ] Confirm the model returns stable ordering and display labels suitable for both API responses and frontend selection UIs.
- [ ] Task: Establish `weather_caches` as the durable weather fallback store
    - [ ] Write failing Pest coverage for freshness checks, expiry handling, and provider-aware lookup behavior (`isFresh()`, `findValid()`).
    - [ ] Create the `weather_caches` migration with the persisted payload, freshness timestamps, and lookup indexes required by the cache service.
    - [ ] Implement `App\Models\WeatherCache` with the minimum query helpers needed by `WeatherCacheService`.
    - [ ] Verify the persisted payload shape round-trips cleanly to and from the planned DTO contract.
- [ ] Task: Define backend weather contracts before provider work begins
    - [ ] Create `App\Services\Weather\DTOs\WeatherData` with explicit serialization / hydration support (`toArray()` / `fromArray()`).
    - [ ] Create `App\Services\Weather\Contracts\WeatherProvider` with a single normalized fetch contract.
    - [ ] Create `App\Services\Weather\Exceptions\WeatherFetchException` for provider-level failures.
    - [ ] Lock the canonical condition-code and alert-level expectations so all providers emit the same normalized shape.
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Backend Foundation' (Protocol in `conductor/workflow.md`)

## Phase 2: Provider + Cache Services

- [ ] Task: Define weather runtime configuration and provider ordering
    - [ ] Add `config/weather.php` with provider order, cache TTLs, durable freshness windows, and upstream timeout / retry settings.
    - [ ] Add the corresponding `.env` entries with safe local defaults and explicit fallback-provider toggles.
    - [ ] Bind provider aliases / dependencies so `WeatherFetchService` can resolve configured providers without controller knowledge.
- [ ] Task: Implement `EnvironmentCanadaWeatherProvider`
    - [ ] Write `EnvironmentCanadaWeatherProviderTest` with saved HTML fixtures for a normal page, a page with explicit alert messaging, and malformed / incomplete upstream markup.
    - [ ] Implement the fetch/parsing pipeline with `Http::timeout(...)->retry(..., throw: false)` plus `DOMDocument` / `DOMXPath`.
    - [ ] Normalize all required weather fields into `WeatherData`, including a finite `condition_code` slug set.
    - [ ] Set `alert_level` only when the upstream source clearly exposes an alert signal; otherwise return `null`.
    - [ ] Fail with a provider-specific `WeatherFetchException` when the upstream response is unusable.
- [ ] Task: (Optional) Implement `GoogleMapsWeatherProvider` fallback
    - [ ] Gate the provider entirely behind explicit config so the default implementation path remains Environment Canada only.
    - [ ] Mirror the same `WeatherProvider` contract, DTO normalization, and failure semantics as the primary provider.
- [ ] Task: Implement `WeatherFetchService`
    - [ ] Write `WeatherFetchServiceTest` covering configured provider order, stop-on-first-success behavior, and throw-when-all-fail behavior.
    - [ ] Implement `App\Services\Weather\WeatherFetchService` to resolve provider instances from config rather than hard-coding dependencies.
    - [ ] Preserve enough failure context for logging / 503 handling without leaking provider-specific parsing details to the API layer.
- [ ] Task: Implement `WeatherCacheService`
    - [ ] Write `WeatherCacheServiceTest` covering fast-cache hit, durable-cache fallback, fast-cache rewarm after durable hit, and upstream fetch on full miss.
    - [ ] Implement cache-key generation, TTL rules, and DTO persistence using Laravel Cache plus `WeatherCache`.
    - [ ] Ensure the service returns a single normalized `WeatherData` result regardless of whether the source was fast cache, durable cache, or upstream fetch.
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Provider + Cache Services' (Protocol in `conductor/workflow.md`)

## Phase 3: Backend API Endpoints

- [ ] Task: Implement `PostalCodeSearchController`
    - [ ] Write `PostalCodeSearchControllerTest` for valid queries, invalid / too-short queries, normalized FSA lookups, and result capping.
    - [ ] Add the throttled `GET /api/postal-codes` route following the existing public `/api/*` routing pattern.
    - [ ] Return only the fields the frontend selector needs (`fsa`, `display_name`, `lat`, `lng`) with stable ordering.
- [ ] Task: Implement `PostalCodeResolveCoordsController`
    - [ ] Write `PostalCodeResolveCoordsControllerTest` for valid coordinates, out-of-GTA bounds rejection, and nearest-FSA resolution.
    - [ ] Add the throttled `POST /api/postal-codes/resolve-coords` route.
    - [ ] Reuse the model-level bounds / nearest lookup helpers so controller logic stays thin.
- [ ] Task: Implement `WeatherController`
    - [ ] Write `WeatherControllerTest` for successful responses (`WeatherResource`), 422 validation failures, and 503 upstream failures.
    - [ ] Create `App\Http\Resources\WeatherResource` that emits the canonical `snake_case` transport contract.
    - [ ] Add the throttled `GET /api/weather` route and normalize the incoming postal code before lookup.
    - [ ] Delegate all fetch / cache behavior to `WeatherCacheService` so the controller only validates, invokes, and maps the result.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Backend API Endpoints' (Protocol in `conductor/workflow.md`)

## Phase 4: Frontend State + Components

- [ ] Task: Define frontend weather transport and domain mapping
    - [ ] Create `resources/js/features/gta-alerts/domain/weather/resource.ts`, `types.ts`, and `fromResource.ts`.
    - [ ] Lock the frontend `WeatherData` / selected-location shape so the hook, footer, and picker share one contract.
    - [ ] Add mapper coverage that proves the backend `snake_case` payload becomes the expected frontend `camelCase` domain object.
- [ ] Task: Implement `useWeather` hook
    - [ ] Write `useWeather.test.ts` covering `localStorage` hydration, location persistence, API fetching, loading / error states, and stale-while-revalidate behavior.
    - [ ] Implement `resources/js/features/gta-alerts/hooks/useWeather.ts` using `localStorage` key `gta_weather_location_v1`.
    - [ ] Expose explicit actions for selecting a location and manually refreshing weather without duplicating fetch logic in components.
- [ ] Task: Implement `LocationPicker` component
    - [ ] Write `LocationPicker.test.tsx` for search requests, result selection, geolocation resolve, GTA bounds rejection, and permission / fetch error messaging.
    - [ ] Implement `resources/js/features/gta-alerts/components/LocationPicker.tsx` with clear loading, empty, and failure states.
    - [ ] Keep the component responsible only for location selection UX; weather fetching remains owned by `useWeather`.
- [ ] Task: Integrate Footer + App UI
    - [ ] Write `Footer.test.tsx` for empty, loading, success, and alert-badge / headline rendering states.
    - [ ] Update `resources/js/features/gta-alerts/components/Footer.tsx` to render normalized live weather data and stale-state messaging.
    - [ ] Update `resources/js/features/gta-alerts/App.tsx` to host `useWeather` state, render `LocationPicker`, and pass the selected weather state into `Footer`.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Frontend State + Components' (Protocol in `conductor/workflow.md`)

## Phase 5: QA Phase

- [ ] Task: Run targeted weather regressions before the full sweep
    - [ ] Run the weather-specific Pest tests first with focused filters so backend failures are isolated before the full suite.
    - [ ] Run the weather-specific Vitest files (`useWeather`, `LocationPicker`, `Footer`, and any mapper tests) before broader frontend validation.
    - [ ] Resolve any deterministic fixture / snapshot issues before proceeding to full-suite execution.
- [ ] Task: Run the full automated test suites
    - [ ] Run `CI=true ./vendor/bin/sail artisan test --compact` for the full Laravel / Pest suite.
    - [ ] Run `CI=true ./vendor/bin/sail pnpm run test:ci` for the full frontend Vitest suite.
    - [ ] Run `XDEBUG_MODE=coverage ./vendor/bin/sail artisan test --coverage --min=90` and verify the weather-related backend modules remain above the project coverage threshold.
- [ ] Task: Run formatting, static checks, and security gates
    - [ ] Run `./vendor/bin/sail bin pint --format agent` for PHP formatting.
    - [ ] Run `./vendor/bin/sail pnpm run lint:check`, `./vendor/bin/sail pnpm run format:check`, and `./vendor/bin/sail pnpm run types`.
    - [ ] Run `./vendor/bin/sail composer audit` and `./vendor/bin/sail pnpm audit --audit-level high`, recording any pre-existing findings separately from weather changes.
- [ ] Task: Conductor - User Manual Verification 'Phase 5: QA Phase' (Protocol in `conductor/workflow.md`)

## Phase 6: Documentation Phase

- [ ] Task: Finalize technical and operational documentation
    - [ ] Document the implemented architecture under `docs/` (for example `docs/backend/weather.md`), including provider flow, cache layers, and API contracts.
    - [ ] Document required environment variables, cache TTL knobs, and the intended operational behavior when upstream providers fail.
    - [ ] Record any intentional deviations from `docs/plans/weather-feature-plan.md` so the implementation history stays explicit.
- [ ] Task: Update project-facing developer documentation
    - [ ] Update `README.md` with any new setup, testing, or troubleshooting commands introduced by the weather feature.
    - [ ] Update `CLAUDE.md` only if the implementation introduces durable new repo patterns or operator guidance.
- [ ] Task: Close the Conductor track cleanly
    - [ ] Ensure the completed `plan.md` includes the final task SHAs / checkpoint SHAs required by the Conductor workflow.
    - [ ] Update `conductor/tracks.md` to reflect completion, then move the track folder into `conductor/archive/` when the work is merged.
- [ ] Task: Conductor - User Manual Verification 'Phase 6: Documentation Phase' (Protocol in `conductor/workflow.md`)
