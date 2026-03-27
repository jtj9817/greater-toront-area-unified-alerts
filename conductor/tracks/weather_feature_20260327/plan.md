# Implementation Plan: Weather Feature (GTA Alerts) (FEED-015)

Source plan: `docs/plans/weather-feature-plan.md`.

## Phase 1: Backend Foundation (Postal Codes, Durable Cache, Contracts)

- [ ] Task: Set up `gta_postal_codes` reference table and model
    - [ ] Write tests for `GtaPostalCode` model covering FSA normalization, driver-safe search ordering, coarse GTA bounds rejection, and nearest-FSA resolution.
    - [ ] Create data file `database/data/gta_postal_codes.php` (approx 200 GTA-focused rows).
    - [ ] Create migration for `gta_postal_codes` and bulk insert the data file contents during migration.
    - [ ] Implement `App\Models\GtaPostalCode` including `normalizeFsa()`, `search()`, `nearestTo()`, and `displayName()`.
- [ ] Task: Set up `weather_caches` durable cache table and model
    - [ ] Write tests for `WeatherCache` model (e.g. `isFresh()`, `findValid()`).
    - [ ] Create migration for `weather_caches` table.
    - [ ] Implement `App\Models\WeatherCache`.
- [ ] Task: Define weather data contracts
    - [ ] Create `App\Services\Weather\DTOs\WeatherData`.
    - [ ] Create `App\Services\Weather\Contracts\WeatherProvider`.
    - [ ] Create `App\Services\Weather\Exceptions\WeatherFetchException`.
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Backend Foundation' (Protocol in `conductor/workflow.md`)

## Phase 2: Provider + Cache Services

- [ ] Task: Implement `EnvironmentCanadaWeatherProvider`
    - [ ] Add `config/weather.php` and `.env` properties for providers, cache TTL, and request timeouts.
    - [ ] Write `EnvironmentCanadaWeatherProviderTest` using saved HTML fixtures to parse required fields.
    - [ ] Implement provider with `Http::timeout(...)->retry(..., throw: false)` + `DOMDocument`/`DOMXPath`, normalizing into `WeatherData`.
    - [ ] Normalize Environment Canada condition text into a finite `condition_code` slug set.
    - [ ] Ensure `alert_level` is only set from an explicit upstream signal (otherwise `null`).
- [ ] Task: (Optional) Implement `GoogleMapsWeatherProvider` fallback
    - [ ] Only if enabled by explicit config; must implement the same `WeatherProvider` interface and normalize into `WeatherData`.
- [ ] Task: Implement `WeatherFetchService`
    - [ ] Write `WeatherFetchServiceTest` to ensure configured provider order, stop-on-first-success, and throw-when-all-fail.
    - [ ] Implement `App\Services\Weather\WeatherFetchService`.
- [ ] Task: Implement `WeatherCacheService`
    - [ ] Write `WeatherCacheServiceTest` covering fast-cache hit, durable-cache fallback, and upstream fetch on full miss.
    - [ ] Implement `App\Services\Weather\WeatherCacheService` using Laravel Cache store + `WeatherCache`.
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Provider + Cache Services' (Protocol in `conductor/workflow.md`)

## Phase 3: Backend API Endpoints

- [ ] Task: Implement `PostalCodeSearchController`
    - [ ] Write `PostalCodeSearchControllerTest` for valid and invalid queries and result capping.
    - [ ] Implement `GET /api/postal-codes` endpoint and route (throttled).
- [ ] Task: Implement `PostalCodeResolveCoordsController`
    - [ ] Write `PostalCodeResolveCoordsControllerTest` for bounds checking and nearest-FSA resolution.
    - [ ] Implement `POST /api/postal-codes/resolve-coords` endpoint and route (throttled).
- [ ] Task: Implement `WeatherController`
    - [ ] Write `WeatherControllerTest` for 200s (`WeatherResource`), 422s, and 503s (including `snake_case` payload).
    - [ ] Create `App\Http\Resources\WeatherResource`.
    - [ ] Implement `GET /api/weather` endpoint and route (throttled).
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Backend API Endpoints' (Protocol in `conductor/workflow.md`)

## Phase 4: Frontend State + Components

- [ ] Task: Define frontend domain types and resource mapping
    - [ ] Create `resources/js/features/gta-alerts/domain/weather/resource.ts`, `types.ts`, and `fromResource.ts`.
- [ ] Task: Implement `useWeather` hook
    - [ ] Write `useWeather.test.ts` for `localStorage` persistence, API fetching, and stale-while-revalidate behavior.
    - [ ] Implement `resources/js/features/gta-alerts/hooks/useWeather.ts` using `localStorage` key `gta_weather_location_v1`.
- [ ] Task: Implement `LocationPicker` component
    - [ ] Write `LocationPicker.test.tsx` for search, geolocation resolve, bounds rejection, and error messaging.
    - [ ] Implement `resources/js/features/gta-alerts/components/LocationPicker.tsx`.
- [ ] Task: Integrate Footer + App UI
    - [ ] Write `Footer.test.tsx` verifying conditions display and alert badge/headline rendering.
    - [ ] Update `resources/js/features/gta-alerts/components/Footer.tsx` to render live weather data.
    - [ ] Update `resources/js/features/gta-alerts/App.tsx` to host `useWeather` state and render `LocationPicker`.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Frontend State + Components' (Protocol in `conductor/workflow.md`)

## Phase 5: QA Phase

- [ ] Task: Execute automated quality gates
    - [ ] Run `./vendor/bin/sail artisan test` (and weather-specific test filters as needed).
    - [ ] Run `./vendor/bin/sail artisan test --coverage --min=90` for the new modules.
    - [ ] Run `./vendor/bin/sail composer lint` and JS quality gates (`pnpm run types`, `pnpm run lint`, `pnpm run format`).
    - [ ] Run dependency security audits and record any pre-existing issues.
- [ ] Task: Conductor - User Manual Verification 'Phase 5: QA Phase' (Protocol in `conductor/workflow.md`)

## Phase 6: Documentation & Closeout

- [ ] Task: Final documentation
    - [ ] Document the finalized architecture under `docs/` (e.g. `docs/backend/weather.md`).
    - [ ] Update `README.md` and `CLAUDE.md` if new commands/patterns were introduced.
- [ ] Task: Track closeout
    - [ ] Move the track folder into `conductor/archive/` when complete.
    - [ ] Update `conductor/tracks.md` to mark the track archived and link the archive folder.
- [ ] Task: Conductor - User Manual Verification 'Phase 6: Documentation & Closeout' (Protocol in `conductor/workflow.md`)

