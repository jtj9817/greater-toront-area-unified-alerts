# Weather Feature Plan

**Resolves:** FEED-015 — Wire Footer Weather Stats to Live Data Source
**Status:** Draft
**Priority:** Low → Medium (user-facing quality improvement)

---

## Overview

Replace the hardcoded weather placeholder in `Footer.tsx` with live, location-aware weather data. Users select a GTA postal code (FSA) or use their device's Geolocation API to confirm their location. The chosen location is persisted in `localStorage`. Weather is fetched server-side, cached in Redis, and backed by a `weather_caches` Eloquent model for persistence and fallback. GTA validation is enforced on both backend (FSA allowlist table) and frontend (geolocation bounding box).

---

## Architecture

### Data Flow

```
[User opens LocationPicker]
        |
        +--> [Types postal code] --> GET /api/postal-codes?q={query} --> gta_postal_codes table
        |
        +--> [Uses Geolocation API] --> browser coords --> GTA bounding box check
                  --> POST /api/postal-codes/resolve-coords --> nearest FSA lookup

[Location confirmed] --> stored in localStorage (key: gta_weather_location)

[Footer mounts / location changes]
        |
        v
GET /api/weather?postal={FSA}
        |
        +--> [Redis HIT] --> return cached WeatherData (TTL: 30 min)
        |
        +--> [Redis MISS] --> check weather_caches DB (freshness check)
                  |
                  +--> [DB fresh] --> re-warm Redis --> return WeatherData
                  |
                  +--> [DB stale/miss] --> WeatherFetchService
                              --> GoogleMapsWeatherProvider (if GOOGLE_MAPS_API_KEY set)
                              --> OpenMeteoWeatherProvider   (free fallback)
                              --> store in Redis + weather_caches DB
                              --> return WeatherData
```

### Transport Shape

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
  "observed_at": "2026-03-24T21:00:00-04:00",
  "fetched_at": "2026-03-24T21:10:00-04:00"
}
```

`alert_level` mirrors Environment Canada's colour-coded tier: `"yellow"`, `"orange"`, `"red"`, or `null`.

---

## Phase 1 — Backend Foundation

### 1.1 GTA Postal Code Table

**Migration:** `create_gta_postal_codes_table`

```php
Schema::create('gta_postal_codes', function (Blueprint $table) {
    $table->string('fsa', 3)->primary();         // e.g. "M5V"
    $table->string('municipality');               // e.g. "Toronto"
    $table->string('neighbourhood')->nullable();  // e.g. "Entertainment District"
    $table->decimal('lat', 10, 7);
    $table->decimal('lng', 10, 7);
    // No timestamps — read-only reference table seeded at migration time
});
```

**Model:** `App\Models\GtaPostalCode`

```php
class GtaPostalCode extends Model
{
    public $timestamps = false;
    protected $primaryKey = 'fsa';
    protected $keyType = 'string';
    public $incrementing = false;

    // Haversine distance in km from a given lat/lng
    public static function nearestTo(float $lat, float $lng): ?self { ... }

    // Check if an FSA belongs to the GTA allowlist
    public static function isValid(string $fsa): bool { ... }
}
```

**Seeder:** `GtaPostalCodeSeeder`

Seeded from a static PHP array of all valid GTA FSAs with centroids. Covers:

| Region | Prefix | Example FSAs |
|---|---|---|
| City of Toronto | M | M1B, M4G, M5V, M6H, M9W, ... (~100 FSAs) |
| Peel (Brampton/Mississauga) | L4T–L7A | L4T, L5N, L6R, L7A, ... |
| York (Markham/Richmond Hill/Vaughan) | L3P–L6G | L3R, L4B, L4K, L6C, ... |
| Durham (Oshawa/Whitby/Ajax/Pickering) | L1G–L1Z | L1G, L1N, L1T, L1V, ... |
| Halton (Burlington/Oakville) | L6H–L9T | L6H, L6J, L7L, L9T, ... |

Approximately 200 FSAs total. The seeder runs in the same migration file (not a separate `db:seed` step) to ensure the table is always populated after schema creation.

### 1.2 WeatherCache Model

**Migration:** `create_weather_caches_table`

```php
Schema::create('weather_caches', function (Blueprint $table) {
    $table->id();
    $table->string('postal_code', 3)->unique(); // FK to gta_postal_codes.fsa
    $table->json('data');                        // full WeatherData payload
    $table->timestamp('fetched_at');
    $table->timestamp('expires_at');
    $table->timestamps();

    $table->index('expires_at');
});
```

**Model:** `App\Models\WeatherCache`

```php
class WeatherCache extends Model
{
    protected $casts = [
        'data'       => 'array',
        'fetched_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function isFresh(): bool
    {
        return $this->expires_at->isFuture();
    }

    public static function findValid(string $postalCode): ?self
    {
        return static::where('postal_code', $postalCode)
            ->where('expires_at', '>', now())
            ->first();
    }
}
```

The DB cache is a **persistence layer** beneath Redis: if Redis is flushed or unavailable, a warm DB record prevents an upstream API call.

### 1.3 WeatherData DTO

**`App\Services\Weather\DTOs\WeatherData`**

```php
readonly class WeatherData
{
    public function __construct(
        public string  $postalCode,
        public float   $temperatureC,
        public ?float  $apparentTemperatureC,
        public int     $humidityPct,
        public float   $windSpeedKmh,
        public string  $windDirection,    // "N", "NE", "E", ... "NW"
        public string  $condition,        // human-readable, e.g. "Mostly Cloudy"
        public string  $conditionCode,    // slug, e.g. "cloudy", "rain", "snow"
        public ?string $alertLevel,       // null | "yellow" | "orange" | "red"
        public Carbon  $observedAt,
        public Carbon  $fetchedAt,
    ) {}

    public function toArray(): array { ... }
    public static function fromArray(array $data): self { ... }
}
```

### 1.4 Weather Provider Interface & Implementations

**`App\Services\Weather\Contracts\WeatherProvider`**

```php
interface WeatherProvider
{
    /**
     * @throws WeatherFetchException
     */
    public function fetch(float $lat, float $lng, string $postalCode): WeatherData;
}
```

**`App\Services\Weather\Providers\GoogleMapsWeatherProvider`**

Uses `https://weather.googleapis.com/v1/currentConditions:lookup` (POST). Requires `GOOGLE_MAPS_API_KEY` env variable. Maps the response's `weatherCondition`, `temperature`, `relativeHumidity`, `wind`, and `uvIndex` fields to `WeatherData`. Determines `alertLevel` from condition severity codes in the response.

**`App\Services\Weather\Providers\OpenMeteoWeatherProvider`**

Uses `https://api.open-meteo.com/v1/forecast` (GET). No API key required. Maps Open-Meteo's WMO weather codes to `conditionCode` and human-readable `condition` strings. Used automatically when `GOOGLE_MAPS_API_KEY` is not configured.

WMO code mapping (subset):

| WMO Code | conditionCode | condition |
|---|---|---|
| 0 | clear | Clear |
| 1–3 | cloudy | Partly / Mostly Cloudy |
| 45, 48 | fog | Fog |
| 51–57 | drizzle | Drizzle |
| 61–67 | rain | Rain |
| 71–77 | snow | Snow |
| 80–82 | showers | Rain Showers |
| 85–86 | snow-showers | Snow Showers |
| 95–99 | thunderstorm | Thunderstorm |

**`App\Services\Weather\WeatherFetchService`**

Resolves the correct provider via the container, executes the fetch, and handles retry on transient failure (1 retry, 500 ms delay). Registered in `AppServiceProvider` binding `WeatherProvider` to `GoogleMapsWeatherProvider` when `GOOGLE_MAPS_API_KEY` is present, else `OpenMeteoWeatherProvider`.

### 1.5 WeatherCacheService

**`App\Services\Weather\WeatherCacheService`**

Orchestrates the cache lookup chain: Redis → DB → upstream fetch.

```php
class WeatherCacheService
{
    public function __construct(
        private WeatherFetchService $fetcher,
        private GtaPostalCode       $postalCodes,
    ) {}

    public function get(string $fsa): WeatherData
    {
        // 1. Redis HIT
        if ($cached = Cache::get("weather:{$fsa}")) {
            return WeatherData::fromArray($cached);
        }

        // 2. DB HIT (still fresh)
        if ($row = WeatherCache::findValid($fsa)) {
            $data = WeatherData::fromArray($row->data);
            Cache::put("weather:{$fsa}", $row->data, $this->redisTtl());
            return $data;
        }

        // 3. Upstream fetch
        $postal = GtaPostalCode::findOrFail($fsa);
        $data   = $this->fetcher->fetch($postal->lat, $postal->lng, $fsa);

        $this->store($fsa, $data);
        return $data;
    }

    private function store(string $fsa, WeatherData $data): void
    {
        $ttl     = $this->redisTtl();
        $payload = $data->toArray();

        Cache::put("weather:{$fsa}", $payload, $ttl);

        WeatherCache::updateOrCreate(
            ['postal_code' => $fsa],
            [
                'data'       => $payload,
                'fetched_at' => $data->fetchedAt,
                'expires_at' => now()->addSeconds($ttl->totalSeconds),
            ]
        );
    }

    private function redisTtl(): \DateInterval
    {
        $minutes = (int) config('weather.cache_ttl_minutes', 30);
        return now()->addMinutes($minutes)->diff(now());
    }
}
```

### 1.6 API Endpoints

**Routes added to `routes/web.php`:**

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

**`GET /api/postal-codes?q={query}`** — `PostalCodeSearchController`

Searches `gta_postal_codes` on `fsa` prefix or `municipality`/`neighbourhood` ILIKE. Returns up to 10 results:
```json
{
  "data": [
    { "fsa": "M5V", "municipality": "Toronto", "neighbourhood": "Entertainment District", "lat": 43.6426, "lng": -79.3871 }
  ]
}
```

**`POST /api/postal-codes/resolve-coords`** — `PostalCodeResolveCoordsController`

Accepts `{ lat, lng }`. Validates coords fall within the GTA bounding box (43.40–44.25°N, 79.10–80.00°W). Returns the nearest FSA using a Haversine ORDER BY approximation on the `gta_postal_codes` table, or a 422 if outside GTA bounds.

```json
{ "data": { "fsa": "M5V", "municipality": "Toronto", "neighbourhood": "Entertainment District" } }
```

**`GET /api/weather?postal={FSA}`** — `WeatherController`

Validates `postal` is a known FSA via `GtaPostalCode::isValid()`. Returns the `WeatherData` DTO as JSON via `WeatherCacheService::get()`. On upstream fetch failure, returns a 503 with a `retry_after` header.

---

## Phase 2 — Frontend

### 2.1 TypeScript Types

**`resources/js/features/gta-alerts/domain/weather/types.ts`**

```ts
export interface WeatherLocation {
    fsa: string;
    municipality: string;
    neighbourhood: string | null;
    lat: number;
    lng: number;
}

export interface WeatherData {
    postalCode: string;
    temperatureC: number;
    apparentTemperatureC: number | null;
    humidityPct: number;
    windSpeedKmh: number;
    windDirection: string;
    condition: string;
    conditionCode: string;
    alertLevel: 'yellow' | 'orange' | 'red' | null;
    observedAt: string;
    fetchedAt: string;
}
```

### 2.2 useWeather Hook

**`resources/js/features/gta-alerts/hooks/useWeather.ts`**

Responsibilities:
- Read/write `WeatherLocation` from `localStorage` key `gta_weather_location`
- Fetch weather from `/api/weather?postal={fsa}` when location is set
- Enforce a 30-minute client-side TTL (stored alongside data in `localStorage`) to avoid refetching on every mount
- Expose `setLocation(loc)` which persists to storage and triggers a fresh fetch
- Return `{ weather, location, setLocation, isLoading, error, clearLocation }`

```ts
const STORAGE_KEY = 'gta_weather_location';
const CLIENT_TTL_MS = 30 * 60 * 1000;

interface StoredState {
    location: WeatherLocation;
    weather: WeatherData;
    cachedAt: number; // Date.now()
}
```

On mount: read from storage → if `cachedAt` is within TTL, use stored weather; otherwise re-fetch.

### 2.3 LocationPicker Component

**`resources/js/features/gta-alerts/components/LocationPicker.tsx`**

A modal/popover triggered from the footer. Two input modes:

**Mode A — Postal code search:**
- `<input>` with debounced query → `GET /api/postal-codes?q={query}`
- Renders a dropdown list of matching FSAs
- Selecting a result calls `setLocation(result)` and closes the picker

**Mode B — Geolocation:**
- "Use My Location" button calls `navigator.geolocation.getCurrentPosition()`
- On success: sends `POST /api/postal-codes/resolve-coords` with `{ lat, lng }`
- On 422 (outside GTA): shows "Your location is outside the Greater Toronto Area"
- On success: calls `setLocation(result)` and closes picker
- On permission denied: shows inline error message

**States:** idle / loading / results / error / success

### 2.4 Updated Footer Component

`Footer.tsx` becomes a presentational component that accepts:

```ts
interface FooterProps {
    weather?: WeatherData | null;
    location?: WeatherLocation | null;
    onOpenLocationPicker: () => void;
    isLoadingWeather?: boolean;
}
```

Weather display logic:
- **No location set:** renders `Set Location` button with a pin icon
- **Loading:** renders a skeleton placeholder in place of weather text
- **Weather available:** renders `{temperature}°C | Humidity: {humidity}% | Wind: {speed}km/h {dir}`
- **Alert level set:** renders a colour-coded badge (yellow/orange/red) using existing `text-hazard` / design token naming, consistent with the Environment Canada colour-coded alert system

The `LocationPicker` modal is rendered in `App.tsx` (portal at root level) and its open/close state is managed there.

### 2.5 App.tsx Integration

- Instantiate `useWeather()` hook at the `App` component level
- Pass `weather` and `location` props down to `<Footer />`
- Manage `isLocationPickerOpen` state at `App` level
- Pass `onOpenLocationPicker` callback to `Footer`
- Render `<LocationPicker>` conditionally at the end of the component tree

No new Inertia props are required — all weather data is fetched client-side after mount.

---

## Phase 3 — Configuration & Environment

### New Config File: `config/weather.php`

```php
return [
    'provider' => env('WEATHER_PROVIDER', 'auto'), // 'google' | 'openmeteo' | 'auto'
    'google_api_key' => env('GOOGLE_MAPS_API_KEY'),
    'cache_ttl_minutes' => env('WEATHER_CACHE_TTL_MINUTES', 30),
];
```

### New `.env` Variables (optional)

```dotenv
GOOGLE_MAPS_API_KEY=         # Optional. If absent, Open-Meteo is used.
WEATHER_CACHE_TTL_MINUTES=30 # How long to cache weather per location
```

---

## Phase 4 — Testing

### Backend

**Unit: `WeatherFetchServiceTest`**
- Mocks `GoogleMapsWeatherProvider` HTTP response → asserts correct `WeatherData` DTO fields
- Mocks `OpenMeteoWeatherProvider` WMO codes → asserts correct `conditionCode` and `condition` strings for key codes (0, 3, 61, 71, 95)
- Asserts `WeatherFetchException` on non-2xx response

**Unit: `WeatherCacheServiceTest`**
- Redis HIT → `WeatherData` returned without DB or upstream calls
- Redis MISS + DB HIT → `WeatherData` returned, Redis re-warmed
- Redis MISS + DB MISS → upstream fetch called, both caches written
- `WeatherCache::findValid()` returns null for expired rows

**Feature: `WeatherControllerTest`**
- `GET /api/weather?postal=M5V` → 200 with `WeatherData` shape
- `GET /api/weather?postal=A1A` (non-GTA) → 422
- `GET /api/weather` (missing postal) → 422
- Upstream failure → 503 with `Retry-After` header

**Feature: `PostalCodeSearchControllerTest`**
- `GET /api/postal-codes?q=M5V` → returns matching FSAs
- `GET /api/postal-codes?q=Toronto` → matches by municipality name
- `GET /api/postal-codes?q=A` (too short) → 422

**Feature: `PostalCodeResolveCoordsControllerTest`**
- Coords inside GTA → 200 with nearest FSA
- Coords outside GTA (e.g., Hamilton at `43.25,-79.85`) → 422

### Frontend (Vitest)

**`useWeather.test.ts`**
- No stored location → `weather` is null, fetch not called
- Stored location within TTL → returns cached `weather` without fetch
- Stored location past TTL → fetch called, storage updated
- `setLocation()` → persists to `localStorage`, triggers fresh fetch
- `clearLocation()` → removes `localStorage` key, resets state

**`LocationPicker.test.tsx`**
- Renders postal code input
- Debounced input triggers `/api/postal-codes` mock
- Selecting a result calls `onConfirm` with correct location
- Geolocation success path → calls resolve-coords mock → calls `onConfirm`
- Geolocation coords outside GTA (422 response) → displays error message
- Geolocation permission denied → displays permission error

---

## Sequence of Implementation

1. **Migration + Seeder** — `gta_postal_codes` table with all FSAs; `weather_caches` table
2. **Models** — `GtaPostalCode`, `WeatherCache`
3. **DTO** — `WeatherData`
4. **Provider interface + implementations** — `OpenMeteoWeatherProvider` first (no API key needed to develop/test), then `GoogleMapsWeatherProvider`
5. **`WeatherFetchService` + `WeatherCacheService`**
6. **Controllers** — `PostalCodeSearchController`, `PostalCodeResolveCoordsController`, `WeatherController`
7. **Routes** — add to `routes/web.php`
8. **Backend tests**
9. **Frontend types** — `WeatherLocation`, `WeatherData`
10. **`useWeather` hook**
11. **`LocationPicker` component**
12. **`Footer` refactor** — accept props, render live data, wire `onOpenLocationPicker`
13. **`App.tsx` integration** — hook instantiation, state wiring, modal rendering
14. **Frontend tests**

---

## Open Questions

1. **Alert level**: The `alertLevel` field (`yellow`/`orange`/`red`) is only available if the weather provider returns severity data. Open-Meteo does not include this; Google Maps Weather API includes condition codes but not explicit EC alert tiers. A separate scrape of `https://weather.gc.ca/rss/city/on-143_e.xml` (Environment Canada RSS) may be needed if alert display is required. This can be deferred to a follow-up ticket.

2. **Scheduled refresh**: `weather_caches` rows expire silently; there is no proactive background refresh. Under low traffic, a cache miss triggers a live fetch (adds ~200–500 ms to the API response). If proactive warming is desired, add an `artisan weather:warm-cache` command scheduled every 25 minutes that refreshes all postal codes with a recent `fetched_at`.

3. **Google Maps API key scope**: The `GOOGLE_MAPS_API_KEY` used here would be a server-side key with the Weather API enabled only. It is separate from any client-side Maps JS API key.

4. **FSA centroid accuracy**: Centroids in the seeder are approximate geometric centres. Users searching by postal code see the FSA name, not a precise street address, which is acceptable for weather granularity.

---

## Files Created / Modified

### New
- `database/migrations/xxxx_create_gta_postal_codes_table.php`
- `database/migrations/xxxx_create_weather_caches_table.php`
- `app/Models/GtaPostalCode.php`
- `app/Models/WeatherCache.php`
- `app/Services/Weather/DTOs/WeatherData.php`
- `app/Services/Weather/Contracts/WeatherProvider.php`
- `app/Services/Weather/Providers/OpenMeteoWeatherProvider.php`
- `app/Services/Weather/Providers/GoogleMapsWeatherProvider.php`
- `app/Services/Weather/WeatherFetchService.php`
- `app/Services/Weather/WeatherCacheService.php`
- `app/Services/Weather/Exceptions/WeatherFetchException.php`
- `app/Http/Controllers/Weather/WeatherController.php`
- `app/Http/Controllers/Weather/PostalCodeSearchController.php`
- `app/Http/Controllers/Weather/PostalCodeResolveCoordsController.php`
- `config/weather.php`
- `resources/js/features/gta-alerts/domain/weather/types.ts`
- `resources/js/features/gta-alerts/hooks/useWeather.ts`
- `resources/js/features/gta-alerts/hooks/useWeather.test.ts`
- `resources/js/features/gta-alerts/components/LocationPicker.tsx`
- `resources/js/features/gta-alerts/components/LocationPicker.test.tsx`
- `tests/Unit/WeatherFetchServiceTest.php`
- `tests/Unit/WeatherCacheServiceTest.php`
- `tests/Feature/WeatherControllerTest.php`
- `tests/Feature/PostalCodeSearchControllerTest.php`
- `tests/Feature/PostalCodeResolveCoordsControllerTest.php`

### Modified
- `resources/js/features/gta-alerts/components/Footer.tsx` — accept props, render live data
- `resources/js/features/gta-alerts/App.tsx` — wire `useWeather`, pass props to Footer
- `routes/web.php` — add three new API routes
- `app/Providers/AppServiceProvider.php` — bind `WeatherProvider` based on config
- `.env.example` — document `GOOGLE_MAPS_API_KEY`, `WEATHER_CACHE_TTL_MINUTES`
