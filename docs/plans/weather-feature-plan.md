# Weather Feature Plan

**Resolves:** FEED-015 — Wire Footer Weather Stats to Live Data Source  
**Status:** Draft  
**Priority:** Low → Medium (user-facing quality improvement)

---

## Overview

Replace the hardcoded weather placeholder in `resources/js/features/gta-alerts/components/Footer.tsx` with live, location-aware weather data while preserving a GTA-specific location model.

This plan intentionally keeps the richer architecture:

- a GTA FSA allowlist table of roughly 200 postal forward sortation areas
- geolocation → nearest-FSA resolution
- server-side weather fetching and normalization
- fast cache + durable DB cache persistence
- client-side persisted user location state

The goal of this document is not to define the smallest possible delivery. It is to define the **full target specification** that later implementation plans can split into phases.

Primary weather sourcing should favor **Environment Canada** because it is the authoritative Canadian public weather source and already exposes human-readable location pages that can be fetched and parsed server-side. Google Weather should be treated as an optional fallback or later enhancement, not the default architecture.

---

## Goals

- Replace misleading static weather copy with real data.
- Keep weather scoped to the user’s selected GTA FSA.
- Support both manual FSA selection and browser geolocation.
- Persist the user’s chosen weather location across sessions.
- Normalize all upstream provider responses into a single backend DTO and API resource.
- Follow existing repo patterns for HTTP fetching, HTML parsing, controller design, and frontend resource mapping.

## Non-Goals

- Street-address geocoding.
- Hyperlocal forecast accuracy beyond FSA centroid precision.
- A full weather dashboard or forecast module in this ticket.
- Background warming for all ~200 FSAs on day one.
- Inventing alert severity tiers from ambiguous weather condition text.

---

## Current Codebase Constraints

The plan must align with the repository as it exists today:

- Backend is Laravel 12 / PHP 8.2+.
- Frontend is React 19 + TypeScript + Inertia.
- Existing JSON API contracts commonly use `snake_case`.
- Frontend domain layers already use transport schemas and mapper functions (for example under `resources/js/features/gta-alerts/domain/alerts/`).
- Existing scraping code already uses `Http::timeout(...)->retry(..., throw: false)` and `DOMDocument` / `DOMXPath` parsing (see `TtcAlertsFeedService`).
- Current cache default is not guaranteed to be Redis; the implementation must be **cache-store agnostic** and work with Laravel’s configured default store.
- `routes/web.php` already contains public `/api/*` endpoints for this feature area, so weather endpoints may follow that established pattern unless a later refactor consolidates API routes elsewhere.

These constraints drive several spec decisions below.

---

## Architecture

### End-to-End Data Flow

```text
[User opens LocationPicker]
        |
        +--> [Searches FSA / municipality / neighbourhood]
        |         -> GET /api/postal-codes?q={query}
        |         -> gta_postal_codes lookup
        |
        +--> [Uses browser geolocation]
                  -> frontend coarse GTA bounds check
                  -> POST /api/postal-codes/resolve-coords
                  -> nearest gta_postal_codes row

[Location confirmed]
        -> persisted in localStorage under gta_weather_location_v1

[Footer mounts or selected location changes]
        -> GET /api/weather?postal={FSA}
        |
        +--> [Fast cache hit]
        |         -> return cached payload
        |
        +--> [Fast cache miss]
        |         -> check weather_caches table
        |         -> if fresh: return persisted payload and re-warm fast cache
        |
        +--> [All caches miss or stale]
                  -> WeatherFetchService
                  -> EnvironmentCanadaWeatherProvider (primary)
                  -> optional fallback providers, in configured order
                  -> normalize into WeatherData DTO
                  -> write fast cache + weather_caches
                  -> return resource payload
```

### Source of Truth

- **Location identity:** `gta_postal_codes`
- **Current normalized weather payload:** `WeatherData` DTO
- **API transport contract:** `WeatherResource`
- **Fast cache:** Laravel cache store configured for runtime (`database`, `file`, `redis`, etc.)
- **Durable cache fallback:** `weather_caches`
- **Client persistence:** `localStorage` for chosen location and optional short-lived client cache

### Why Two Cache Layers Are Kept

The two-layer cache is intentional and remains in scope:

1. **Fast cache layer** for normal request performance.
2. **Persistent DB cache layer** so weather can still be served if the fast cache is flushed or the configured store is ephemeral.

The document should refer to the first layer as **fast cache** or **Laravel cache store**, not Redis specifically. Redis may be used in production, but must not be assumed by the implementation.

---

## Canonical Data Contracts

### Backend DTO Shape

`App\Services\Weather\DTOs\WeatherData`

```php
readonly class WeatherData
{
    public function __construct(
        public string $postalCode,
        public string $displayName,
        public float $lat,
        public float $lng,
        public float $temperatureC,
        public ?float $apparentTemperatureC,
        public int $humidityPct,
        public float $windSpeedKmh,
        public string $windDirection,
        public string $condition,
        public string $conditionCode,
        public ?string $alertLevel,
        public ?string $alertHeadline,
        public \Carbon\CarbonImmutable $observedAt,
        public \Carbon\CarbonImmutable $fetchedAt,
        public string $provider,
    ) {}

    public function toArray(): array { ... }

    public static function fromArray(array $data): self { ... }
}
```

### HTTP Resource Shape

Public API responses should use `snake_case` consistently and be emitted through a dedicated resource class, for example `App\Http\Resources\WeatherResource`.

```json
{
  "postal_code": "M5V",
  "display_name": "Toronto (Entertainment District)",
  "lat": 43.6426,
  "lng": -79.3871,
  "temperature_c": 2.4,
  "apparent_temperature_c": -1.0,
  "humidity_pct": 63,
  "wind_speed_kmh": 15,
  "wind_direction": "W",
  "condition": "Mostly Cloudy",
  "condition_code": "cloudy",
  "alert_level": null,
  "alert_headline": null,
  "observed_at": "2026-03-24T21:00:00-04:00",
  "fetched_at": "2026-03-24T21:10:00-04:00",
  "provider": "environment_canada"
}
```

### Frontend Transport and Domain Mapping

Frontend must not consume the backend resource shape directly as an ad hoc object. Follow the existing alerts-domain pattern:

- `resource.ts` or equivalent for the raw API schema
- `types.ts` for frontend domain types
- `fromResource.ts` for `snake_case` → `camelCase` mapping

Recommended shape:

```ts
export interface WeatherResource {
    postal_code: string;
    display_name: string;
    lat: number;
    lng: number;
    temperature_c: number;
    apparent_temperature_c: number | null;
    humidity_pct: number;
    wind_speed_kmh: number;
    wind_direction: string;
    condition: string;
    condition_code: string;
    alert_level: 'yellow' | 'orange' | 'red' | null;
    alert_headline: string | null;
    observed_at: string;
    fetched_at: string;
    provider: string;
}

export interface WeatherData {
    postalCode: string;
    displayName: string;
    lat: number;
    lng: number;
    temperatureC: number;
    apparentTemperatureC: number | null;
    humidityPct: number;
    windSpeedKmh: number;
    windDirection: string;
    condition: string;
    conditionCode: string;
    alertLevel: 'yellow' | 'orange' | 'red' | null;
    alertHeadline: string | null;
    observedAt: string;
    fetchedAt: string;
    provider: string;
}
```

This removes ambiguity around casing and matches how the rest of the frontend is structured.

---

## Phase 1 — Backend Foundation

### 1.1 GTA Postal Code Reference Table

**Migration:** `create_gta_postal_codes_table`

```php
Schema::create('gta_postal_codes', function (Blueprint $table): void {
    $table->string('fsa', 3)->primary();
    $table->string('municipality');
    $table->string('neighbourhood')->nullable();
    $table->decimal('lat', 10, 7);
    $table->decimal('lng', 10, 7);
});
```

### Data Loading Strategy

The table should still be populated automatically during migration so a normal `php artisan migrate` results in a usable reference table without a second seeding step.

However, the 200-row dataset should **not** be hardcoded inline in the migration body. Instead:

- store the canonical dataset in a dedicated PHP data file, e.g. `database/data/gta_postal_codes.php`
- have the migration read that file and bulk insert the records
- keep the migration deterministic and self-contained from the app’s perspective

This preserves the “always populated after migrate” requirement without turning the migration file into an unreadable blob.

### Coverage Expectations

The allowlist should cover GTA-focused FSAs across:

| Region | Prefix Range | Notes |
|---|---|---|
| Toronto | `M*` | Full city coverage |
| Peel | `L4T`–`L7A` | Brampton / Mississauga / Caledon-adjacent GTA FSAs only |
| York | `L3P`–`L6G` | Markham / Richmond Hill / Vaughan / Aurora / Newmarket-adjacent GTA FSAs only |
| Durham | `L1G`–`L1Z` | Ajax / Pickering / Whitby / Oshawa / Clarington-adjacent GTA FSAs only |
| Halton | `L6H`–`L9T` | Oakville / Burlington / Milton / Halton Hills-adjacent GTA FSAs only |

The exact record count does not need to be exactly 200, but the plan assumes a table of approximately 200 curated rows.

### `App\Models\GtaPostalCode`

```php
class GtaPostalCode extends Model
{
    public $timestamps = false;
    protected $primaryKey = 'fsa';
    protected $keyType = 'string';
    public $incrementing = false;

    public static function normalizeFsa(string $value): string { ... }

    public static function isValid(string $fsa): bool { ... }

    public static function search(string $query, int $limit = 10): Collection { ... }

    public static function nearestTo(float $lat, float $lng): ?self { ... }

    public function displayName(): string { ... }
}
```

### Search Rules

Search must be cross-driver compatible.

Do **not** specify PostgreSQL-only `ILIKE` behavior in the plan.

Instead:

- normalize input to uppercase for FSA matching
- search `fsa`, `municipality`, and `neighbourhood` using driver-safe `LIKE`
- use `LOWER(...)` or pre-normalized values where necessary
- order exact FSA prefix hits ahead of municipality/neighbourhood partial matches
- return at most 10 rows

Example behavior:

1. exact/prefix FSA match first (`M5V`)
2. municipality match next (`Toronto`)
3. neighbourhood match last (`Entertainment`)

### Nearest-FSA Resolution Rules

`nearestTo()` should:

- reject coordinates outside a coarse GTA bounding box before running distance logic
- compute nearest centroid using a Haversine approximation suitable for a small dataset
- remain DB-driver compatible; if raw trig SQL proves inconsistent across SQLite/MySQL/PostgreSQL, the fallback is:
  - fetch candidate rows in bounds
  - compute distances in PHP
  - choose the nearest row in memory

Because the table is small, correctness is more important than forcing all distance math into SQL.

---

### 1.2 Durable Weather Cache Table

**Migration:** `create_weather_caches_table`

```php
Schema::create('weather_caches', function (Blueprint $table): void {
    $table->id();
    $table->string('postal_code', 3)->unique();
    $table->json('data');
    $table->timestamp('fetched_at');
    $table->timestamp('expires_at');
    $table->timestamps();

    $table->index('expires_at');
});
```

This table is a durable persistence layer below the fast cache store.

### `App\Models\WeatherCache`

```php
class WeatherCache extends Model
{
    protected $casts = [
        'data' => 'array',
        'fetched_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function isFresh(): bool
    {
        return $this->expires_at->isFuture();
    }

    public static function findValid(string $postalCode): ?self
    {
        return static::query()
            ->where('postal_code', $postalCode)
            ->where('expires_at', '>', now())
            ->first();
    }
}
```

---

### 1.3 Weather Provider Contract

**`App\Services\Weather\Contracts\WeatherProvider`**

```php
interface WeatherProvider
{
    public function key(): string;

    /**
     * @throws WeatherFetchException
     */
    public function fetch(GtaPostalCode $postalCode): WeatherData;
}
```

Passing the `GtaPostalCode` model instead of loose lat/lng + FSA arguments reduces duplication and gives the provider access to:

- normalized `fsa`
- `municipality`
- `neighbourhood`
- centroid coordinates
- canonical display name

---

### 1.4 Primary Provider: Environment Canada

**`App\Services\Weather\Providers\EnvironmentCanadaWeatherProvider`**

Environment Canada should be the default and primary provider.

#### Retrieval Strategy

Use a location URL keyed by centroid coordinates, for example:

`https://weather.gc.ca/en/location/index.html?coords={lat},{lng}`

Observed current pages contain parseable fields for:

- current condition text
- current temperature
- humidity
- wind direction and speed
- “Last updated” / observed time
- alert headline such as “No alerts in effect”

#### Parsing Strategy

Use the same style already present in the codebase for TTC scraping:

- `Http::timeout(...)->retry(..., throw: false)`
- validate non-empty response bodies
- parse with `DOMDocument` / `DOMXPath`
- normalize whitespace and extracted text before mapping

#### Required Extracted Fields

At minimum, the parser must extract:

- condition
- temperature in °C
- humidity percentage
- wind direction
- wind speed in km/h
- observed timestamp
- alert headline or alert absence text

#### Optional Extracted Fields

If stable and parseable:

- apparent temperature / wind chill / humidex
- explicit alert colour tier

#### Alert-Level Rule

`alert_level` must only be populated from an explicit Environment Canada signal.  
It must **not** be inferred from condition words like “rain”, “snow”, or “thunderstorm”.

If only “No alerts in effect” or generic alert text is available, populate:

- `alert_level = null`
- `alert_headline` with parsed text when useful

#### Condition-Code Normalization

Map EC phrases into a finite internal slug set, for example:

| EC Text Pattern | condition_code |
|---|---|
| Clear / Mainly Clear | `clear` |
| Sunny | `sunny` |
| Partly Cloudy / A mix of sun and cloud | `partly-cloudy` |
| Mostly Cloudy / Cloudy | `cloudy` |
| Rain / Periods of rain | `rain` |
| Showers | `showers` |
| Snow / Flurries / Snowshower | `snow` |
| Fog / Mist | `fog` |
| Thunderstorm | `thunderstorm` |
| Otherwise unknown | `unknown` |

This mapping should live in one dedicated normalization method so all providers converge on the same internal slugs.

---

### 1.5 Optional Fallback Providers

The full architecture may still support additional providers, but they are secondary:

1. `EnvironmentCanadaWeatherProvider` — primary
2. `GoogleMapsWeatherProvider` — optional fallback when explicitly enabled

Open-Meteo is no longer part of the default target architecture in this document. It may be introduced later only if Environment Canada proves too unstable for scraping and Google is not desirable.

### `GoogleMapsWeatherProvider`

If retained:

- it must implement the same `WeatherProvider` interface
- it must normalize into the same `WeatherData` DTO
- it must be enabled only by explicit config
- it must not be assumed to expose alert tiers compatible with Environment Canada

---

### 1.6 WeatherFetchService

**`App\Services\Weather\WeatherFetchService`**

Responsibilities:

- resolve providers from config
- try them in configured order
- record the provider used in the DTO
- retry transient HTTP failures once, consistent with existing feed-fetch patterns
- stop on first successful normalized payload
- throw `WeatherFetchException` if all providers fail

Suggested config-driven order:

```php
'providers' => ['environment_canada'],
```

or

```php
'providers' => ['environment_canada', 'google'],
```

---

### 1.7 WeatherCacheService

**`App\Services\Weather\WeatherCacheService`**

Responsibilities:

- normalize requested FSA
- validate allowlisted location
- check fast cache first
- then check `weather_caches`
- then fetch upstream
- write both cache layers on success

Corrected sample:

```php
class WeatherCacheService
{
    public function __construct(
        private WeatherFetchService $fetcher,
    ) {}

    public function get(string $fsa): WeatherData
    {
        $normalizedFsa = GtaPostalCode::normalizeFsa($fsa);
        $cacheKey = "weather:{$normalizedFsa}";

        if ($cached = Cache::get($cacheKey)) {
            return WeatherData::fromArray($cached);
        }

        if ($row = WeatherCache::findValid($normalizedFsa)) {
            Cache::put($cacheKey, $row->data, $this->expiresAt());

            return WeatherData::fromArray($row->data);
        }

        $postalCode = GtaPostalCode::query()->findOrFail($normalizedFsa);
        $data = $this->fetcher->fetch($postalCode);

        $this->store($normalizedFsa, $data);

        return $data;
    }

    private function store(string $fsa, WeatherData $data): void
    {
        $payload = $data->toArray();
        $expiresAt = $this->expiresAt();

        Cache::put("weather:{$fsa}", $payload, $expiresAt);

        WeatherCache::updateOrCreate(
            ['postal_code' => $fsa],
            [
                'data' => $payload,
                'fetched_at' => $data->fetchedAt,
                'expires_at' => $expiresAt,
            ],
        );
    }

    private function expiresAt(): \Carbon\CarbonImmutable
    {
        return now()->toImmutable()->addMinutes(
            (int) config('weather.cache_ttl_minutes', 30)
        );
    }
}
```

Notes:

- `Cache::put()` should use a `DateTimeInterface` expiry or seconds integer, not a custom `DateInterval` contract invented in the plan.
- `expires_at` should be persisted as an actual timestamp, not calculated from a non-existent `totalSeconds` property.

---

### 1.8 Public API Endpoints

Routes may be added to `routes/web.php` to match the existing public `/api/feed` convention:

```php
Route::get('api/postal-codes', PostalCodeSearchController::class)
    ->middleware('throttle:60,1')
    ->name('api.postal-codes.search');

Route::post('api/postal-codes/resolve-coords', PostalCodeResolveCoordsController::class)
    ->middleware('throttle:30,1')
    ->name('api.postal-codes.resolve-coords');

Route::get('api/weather', WeatherController::class)
    ->middleware('throttle:60,1')
    ->name('api.weather');
```

#### `GET /api/postal-codes?q={query}`

Handled by `PostalCodeSearchController`.

Validation:

- required `q`
- trimmed string
- min length 2
- max length 50

Behavior:

- search on FSA prefix, municipality, neighbourhood
- return up to 10 rows
- return normalized `data` array

Example:

```json
{
  "data": [
    {
      "fsa": "M5V",
      "municipality": "Toronto",
      "neighbourhood": "Entertainment District",
      "lat": 43.6426,
      "lng": -79.3871,
      "display_name": "Toronto (Entertainment District)"
    }
  ]
}
```

#### `POST /api/postal-codes/resolve-coords`

Handled by `PostalCodeResolveCoordsController`.

Validation:

- `lat`: numeric
- `lng`: numeric
- server-side GTA bounds check required even if frontend already checked

Behavior:

- reject out-of-bounds requests with 422
- resolve nearest FSA centroid
- return canonical location payload

Example:

```json
{
  "data": {
    "fsa": "M5V",
    "municipality": "Toronto",
    "neighbourhood": "Entertainment District",
    "lat": 43.6426,
    "lng": -79.3871,
    "display_name": "Toronto (Entertainment District)"
  }
}
```

#### `GET /api/weather?postal={FSA}`

Handled by `WeatherController`.

Validation:

- required `postal`
- normalize and uppercase before lookup
- must exist in `gta_postal_codes`

Behavior:

- return `WeatherResource`
- 422 on invalid FSA
- 503 on upstream failure after all providers fail
- include `Retry-After` header on 503 when useful

---

## Phase 2 — Frontend

### 2.1 Frontend Weather Types

Recommended files:

- `resources/js/features/gta-alerts/domain/weather/resource.ts`
- `resources/js/features/gta-alerts/domain/weather/types.ts`
- `resources/js/features/gta-alerts/domain/weather/fromResource.ts`

Location domain type:

```ts
export interface WeatherLocation {
    fsa: string;
    municipality: string;
    neighbourhood: string | null;
    lat: number;
    lng: number;
    displayName: string;
}
```

### 2.2 `useWeather` Hook

**`resources/js/features/gta-alerts/hooks/useWeather.ts`**

Responsibilities:

- read/write chosen `WeatherLocation` from `localStorage`
- optionally keep a client-side weather snapshot with a short TTL
- fetch `/api/weather?postal={fsa}` when location is known and cache is stale
- expose loading and error state
- avoid duplicate requests during rapid remounts

Recommended storage key:

```ts
const STORAGE_KEY = 'weather_location_example';
```

Recommended stored shape:

```ts
interface StoredWeatherState {
    location: WeatherLocation;
    weather: WeatherData | null;
    cachedAt: number | null;
}
```

Behavior rules:

- if no location exists, do not fetch
- if a cached weather payload exists and is still within TTL, use it immediately
- if stale, render stale data while revalidating if desired, or render loading state; either approach is acceptable if the UX is explicitly chosen
- `clearLocation()` must remove the storage key and clear in-memory state

### 2.3 `LocationPicker` Component

**`resources/js/features/gta-alerts/components/LocationPicker.tsx`**

Capabilities:

- search GTA FSAs and display names
- resolve browser geolocation to nearest FSA
- confirm and persist a location
- handle denial and out-of-GTA cases gracefully

#### Search UX Rules

- debounced input, roughly 200–300 ms
- show results only after minimum query length
- show empty state when no matches exist
- use accessible listbox / dialog semantics consistent with current UI primitives

#### Geolocation UX Rules

- browser permission request happens only on explicit click
- do a coarse client-side bounding-box rejection before making the API call when coordinates are obviously outside GTA
- still always send successful in-bounds client coordinates through the server resolver

### 2.4 Footer Integration

`Footer.tsx` should become presentational and receive props.

```ts
interface FooterProps {
    weather?: WeatherData | null;
    location?: WeatherLocation | null;
    onOpenLocationPicker: () => void;
    isLoadingWeather?: boolean;
    weatherError?: string | null;
}
```

Display behavior:

- **no location** → show `Set Location`
- **loading** → show skeleton or placeholder
- **success** → show temperature, humidity, wind, and optional alert badge
- **error with existing stale data** → keep stale data visible if available
- **error with no data** → show graceful fallback, not raw exception text

Suggested text output:

`1°C | Humidity: 75% | Wind: 10 km/h WSW`

### 2.5 `App.tsx` Integration

- instantiate `useWeather()` in `App.tsx`
- pass weather state into `Footer`
- manage location picker open/close state at app level
- render modal near the app root

No server-rendered Inertia props are required for this feature.

---

## Phase 3 — Configuration & Environment

### `config/weather.php`

```php
return [
    'providers' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('WEATHER_PROVIDERS', 'environment_canada'))
    ))),

    'cache_ttl_minutes' => (int) env('WEATHER_CACHE_TTL_MINUTES', 30),

    'request_timeout_seconds' => (int) env('WEATHER_REQUEST_TIMEOUT_SECONDS', 15),

    'gta_bounds' => [
        'min_lat' => 43.40,
        'max_lat' => 44.25,
        'min_lng' => -80.00,
        'max_lng' => -79.10,
    ],

    'google' => [
        'api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],
];
```

### `.env` Additions

```dotenv
WEATHER_PROVIDERS=environment_canada
WEATHER_CACHE_TTL_MINUTES=30
WEATHER_REQUEST_TIMEOUT_SECONDS=15
GOOGLE_MAPS_API_KEY=
```

Notes:

- `WEATHER_PROVIDERS` is ordered.
- `environment_canada` should be first by default.
- Google should be opt-in.

---

## Phase 4 — Testing

### Backend Unit Tests

#### `EnvironmentCanadaWeatherProviderTest`

- parses a representative Toronto location HTML fixture
- extracts condition, temperature, humidity, wind, observed time
- handles “No alerts in effect”
- tolerates minor markup noise / whitespace variation
- throws `WeatherFetchException` on empty body or unparseable required fields

Use saved HTML fixtures so tests do not rely on live Environment Canada markup.

#### `GoogleMapsWeatherProviderTest`

- only needed if the provider remains in scope
- verifies mapping into the normalized DTO

#### `WeatherFetchServiceTest`

- tries providers in configured order
- stops at first success
- falls through to next provider on failure
- throws when all providers fail

#### `WeatherCacheServiceTest`

- fast cache hit bypasses DB and upstream fetch
- fast cache miss + DB hit returns persisted data and rewrites fast cache
- double miss triggers upstream fetch and writes both caches
- expired DB row is ignored
- invalid FSA is rejected

### Backend Feature Tests

#### `WeatherControllerTest`

- `GET /api/weather?postal=M5V` returns 200 and expected resource shape
- invalid postal returns 422
- upstream failure returns 503
- response payload uses `snake_case`

#### `PostalCodeSearchControllerTest`

- FSA prefix queries work
- municipality queries work
- neighbourhood queries work
- short query rejects with 422
- results are capped

#### `PostalCodeResolveCoordsControllerTest`

- valid GTA coords resolve nearest FSA
- out-of-bounds coords reject
- exact shape of returned location payload is stable

### Frontend Vitest Coverage

#### `useWeather.test.ts`

- no saved location means no fetch
- saved fresh weather is reused
- stale weather re-fetches
- `setLocation()` persists and fetches
- `clearLocation()` resets state
- malformed localStorage payload fails safely

#### `LocationPicker.test.tsx`

- search input renders
- debounced search hits `/api/postal-codes`
- result selection confirms location
- geolocation happy path resolves coords
- out-of-GTA response shows message
- permission denied shows message

#### `Footer.test.tsx`

- no location state
- loading state
- weather state
- alert badge rendering
- error fallback rendering

### Validation Commands

Because this repo already defines quality gates, the eventual implementation phase should validate with:

- `composer run lint`
- `php artisan test`
- `pnpm run format`
- `pnpm run lint`
- `pnpm run types`
- relevant Vitest weather tests

Full command selection can be tightened again when implementation starts.

---

## Sequence of Implementation

This document remains the comprehensive target plan. Future phase plans may split this sequence into smaller deliverables.

1. Create `gta_postal_codes` migration and data file.
2. Create `weather_caches` migration.
3. Add `GtaPostalCode` and `WeatherCache` models.
4. Add `WeatherData` DTO and `WeatherFetchException`.
5. Implement `EnvironmentCanadaWeatherProvider`.
6. Optionally implement `GoogleMapsWeatherProvider`.
7. Implement `WeatherFetchService`.
8. Implement `WeatherCacheService`.
9. Add `WeatherResource`.
10. Add `PostalCodeSearchController`.
11. Add `PostalCodeResolveCoordsController`.
12. Add `WeatherController`.
13. Add routes.
14. Add backend unit and feature tests.
15. Add frontend weather transport/domain types and mapper.
16. Implement `useWeather`.
17. Implement `LocationPicker`.
18. Refactor `Footer.tsx`.
19. Wire `App.tsx`.
20. Add frontend tests.

---

## Risks and Unknowns

### 1. Environment Canada Markup Stability

The plan assumes the location page remains parseable with server-side HTML scraping. This is likely workable, but brittle compared with a formal API contract. The implementation should isolate parsing logic so selector changes are easy to repair.

### 2. Alert Tier Availability

Current observed Environment Canada pages clearly expose “No alerts in effect” and alert links, but not necessarily a stable machine-readable yellow/orange/red severity token on the location page itself.

Therefore:

- `alert_level` is part of the normalized model
- but it may legitimately be `null` unless a stable explicit source is identified
- `alert_headline` should carry any useful parsed human-readable alert text

If exact EC colour-coded tiers require a second source later, that can be layered into this architecture without discarding the rest of the plan.

### 3. FSA Centroid Precision

Centroids are approximate. Weather should be treated as locality-level, not address-level.

### 4. Cross-Driver Distance Computation

SQL trig support varies. The implementation should prioritize deterministic nearest-match behavior over SQL cleverness.

### 5. Background Refresh Policy

This plan does not require proactive warming, but it leaves room for a later command such as `weather:warm-cache`.

### 6. Provider Fallback Policy

If Google remains as a fallback, clear operator intent is required. It should not silently override Environment Canada as the default source of truth.

---

## Open Questions for Future Phase Plans

1. Should the first implementation expose only current conditions in the footer, or also short forecast metadata for the selected FSA?
2. If `alert_level` cannot be reliably parsed from Environment Canada current pages, should the footer initially show only `alert_headline`/alert presence and defer colour badges?
3. Should stale cached weather remain visible during revalidation, or should the footer switch to a loading skeleton on every refresh?
4. Should the app preselect a default GTA location for first-time visitors, or require explicit user selection?
5. At what traffic threshold does proactive warming of recently requested FSAs become worthwhile?

---

## Files Created / Modified

### New

- `database/data/gta_postal_codes.php`
- `database/migrations/xxxx_create_gta_postal_codes_table.php`
- `database/migrations/xxxx_create_weather_caches_table.php`
- `app/Models/GtaPostalCode.php`
- `app/Models/WeatherCache.php`
- `app/Services/Weather/DTOs/WeatherData.php`
- `app/Services/Weather/Contracts/WeatherProvider.php`
- `app/Services/Weather/Providers/EnvironmentCanadaWeatherProvider.php`
- `app/Services/Weather/Providers/GoogleMapsWeatherProvider.php` (optional fallback)
- `app/Services/Weather/WeatherFetchService.php`
- `app/Services/Weather/WeatherCacheService.php`
- `app/Services/Weather/Exceptions/WeatherFetchException.php`
- `app/Http/Controllers/Weather/WeatherController.php`
- `app/Http/Controllers/Weather/PostalCodeSearchController.php`
- `app/Http/Controllers/Weather/PostalCodeResolveCoordsController.php`
- `app/Http/Resources/WeatherResource.php`
- `config/weather.php`
- `resources/js/features/gta-alerts/domain/weather/resource.ts`
- `resources/js/features/gta-alerts/domain/weather/types.ts`
- `resources/js/features/gta-alerts/domain/weather/fromResource.ts`
- `resources/js/features/gta-alerts/hooks/useWeather.ts`
- `resources/js/features/gta-alerts/hooks/useWeather.test.ts`
- `resources/js/features/gta-alerts/components/LocationPicker.tsx`
- `resources/js/features/gta-alerts/components/LocationPicker.test.tsx`
- `resources/js/features/gta-alerts/components/Footer.test.tsx`
- `tests/Unit/Weather/EnvironmentCanadaWeatherProviderTest.php`
- `tests/Unit/Weather/GoogleMapsWeatherProviderTest.php`
- `tests/Unit/Weather/WeatherFetchServiceTest.php`
- `tests/Unit/Weather/WeatherCacheServiceTest.php`
- `tests/Feature/Weather/WeatherControllerTest.php`
- `tests/Feature/Weather/PostalCodeSearchControllerTest.php`
- `tests/Feature/Weather/PostalCodeResolveCoordsControllerTest.php`

### Modified

- `resources/js/features/gta-alerts/components/Footer.tsx`
- `resources/js/features/gta-alerts/App.tsx`
- `routes/web.php`
- `app/Providers/AppServiceProvider.php`
- `.env.example`
