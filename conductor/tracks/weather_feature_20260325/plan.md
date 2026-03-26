# Implementation Plan: Weather Feature (GTA Alerts)

## Phase 1: Database Foundation & Domain

- [x] Task: Set up `gta_postal_codes` reference table and model [5cfe724]
    - [x] Write tests for `GtaPostalCode` model covering FSA normalization, search logic across drivers, and nearest-FSA resolution.
    - [x] Create data file `database/data/gta_postal_codes.php` with approx 200 rows.
    - [x] Create migration for `gta_postal_codes` and configure it to insert the data file contents.
    - [x] Implement `App\Models\GtaPostalCode`.
- [x] Task: Set up `weather_caches` durable cache table and model [5cfe724]
    - [x] Write tests for `WeatherCache` model (e.g. `isFresh()`, `findValid()`).
    - [x] Create migration for `weather_caches` table.
    - [x] Implement `App\Models\WeatherCache`.
- [x] Task: Define Weather Data Contracts [5cfe724]
    - [x] Create `App\Services\Weather\DTOs\WeatherData` struct.
    - [x] Create `App\Services\Weather\Contracts\WeatherProvider` interface.
    - [x] Create `App\Services\Weather\Exceptions\WeatherFetchException`.
- [x] Task: Conductor - User Manual Verification 'Phase 1: Database Foundation & Domain' (Protocol in workflow.md) [09a5da6]

## Phase 2: Weather Provider & Cache Service

- [x] Task: Implement `EnvironmentCanadaWeatherProvider` [bcd8b2b]
    - [x] Add `config/weather.php` and `.env` properties for providers and timeouts.
    - [x] Write `EnvironmentCanadaWeatherProviderTest` using HTML fixtures to extract temperature, humidity, wind, and attempt to parse color-coded alert badges.
    - [x] Implement the provider using `Http` and `DOMDocument` to fulfill the `WeatherProvider` interface.
- [x] Task: Implement `WeatherFetchService` [1cb6faf]
    - [x] Write `WeatherFetchServiceTest` to ensure it respects provider order and throws when all fail.
    - [x] Implement `App\Services\Weather\WeatherFetchService` to resolve and execute providers.
- [x] Task: Implement `WeatherCacheService` [63331d4]
    - [x] Write `WeatherCacheServiceTest` to verify fast cache hits, durable cache fallback, and upstream fetching on full miss.
    - [x] Implement `App\Services\Weather\WeatherCacheService` using both Laravel Cache and `WeatherCache`.
- [x] Task: Conductor - User Manual Verification 'Phase 2: Weather Provider & Cache Service' (Protocol in workflow.md) [7db62b2]

## Phase 3: Backend API Endpoints [checkpoint: 6a71e05]

- [x] Task: Implement `PostalCodeSearchController` [528f927]
    - [x] Write `PostalCodeSearchControllerTest` for valid and invalid queries.
    - [x] Implement `GET /api/postal-codes` endpoint and route.
- [x] Task: Implement `PostalCodeResolveCoordsController` [528f927]
    - [x] Write `PostalCodeResolveCoordsControllerTest` for bounds checking and resolving.
    - [x] Implement `POST /api/postal-codes/resolve-coords` endpoint and route.
- [x] Task: Implement `WeatherController` [528f927]
    - [x] Write `WeatherControllerTest` for successful responses (`WeatherResource`), 422s, and 503s.
    - [x] Create `App\Http\Resources\WeatherResource`.
    - [x] Implement `GET /api/weather` endpoint and route.
- [x] Task: Conductor - User Manual Verification 'Phase 3: Backend API Endpoints' (Protocol in workflow.md)

## Phase 4: Frontend State & Components

- [ ] Task: Define Frontend Domain Types
    - [ ] Create `resources/js/features/gta-alerts/domain/weather/resource.ts`, `types.ts`, and `fromResource.ts`.
- [ ] Task: Implement `useWeather` Hook
    - [ ] Write `useWeather.test.ts` to test `localStorage` interaction, API fetching, keeping stale data visible during fetch, and empty default state.
    - [ ] Implement `resources/js/features/gta-alerts/hooks/useWeather.ts`.
- [ ] Task: Implement `LocationPicker` Component
    - [ ] Write `LocationPicker.test.tsx` testing search, geolocation trigger, and bounding box rejection.
    - [ ] Implement `resources/js/features/gta-alerts/components/LocationPicker.tsx`.
- [ ] Task: Integrate Footer & App UI
    - [ ] Write `Footer.test.tsx` verifying current conditions display and the parsed color-coded alert badges (if present).
    - [ ] Update `resources/js/features/gta-alerts/components/Footer.tsx` to receive weather props.
    - [ ] Update `resources/js/features/gta-alerts/App.tsx` to host `useWeather` state and render `LocationPicker`.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Frontend State & Components' (Protocol in workflow.md)

## Phase 5: QA Phase

- [ ] Task: Execute automated quality gates
    - [ ] Run `pnpm run types`, `pnpm run lint`, and `pnpm run format`.
    - [ ] Run `./vendor/bin/sail artisan test --coverage` and verify >90% coverage for new modules.
    - [ ] Run dependency security audits.
- [ ] Task: Conductor - User Manual Verification 'Phase 5: QA Phase' (Protocol in workflow.md)

## Phase 6: Documentation Phase

- [ ] Task: Final Documentation
    - [ ] Update `docs/backend/weather.md` or similar to document the finalized architecture.
    - [ ] Ensure `CLAUDE.md` is updated if new key patterns were introduced.
- [ ] Task: Conductor - User Manual Verification 'Phase 6: Documentation Phase' (Protocol in workflow.md)
