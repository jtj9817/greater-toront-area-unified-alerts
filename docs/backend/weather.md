# Weather Feature

This document describes the architecture, API contract, and caching strategy for the Weather feature, which provides live weather conditions for GTA postal code areas.

## Overview

The Weather feature delivers current weather conditions for any Forward Sortation Area (FSA) within the Greater Toronto Area. It uses Environment Canada as the primary data source and implements a multi-layer caching strategy to minimize upstream API calls while ensuring fresh data.

Key design decisions:
- **FSA-based lookup:** Weather is resolved by 3-character postal code FSA (e.g., "M5V", "M4W") using the `gta_postal_codes` reference table.
- **Multi-layer caching:** Two-layer cache (fast in-memory + durable database) prevents redundant upstream fetches.
- **Provider fallback chain:** Configurable ordered list of weather providers; if one fails, the next is tried.
- **Guest-first:** No authentication required; location selection persists in `localStorage`.

---

## Architecture

```
Frontend (useWeather hook)
    ↓
GET /api/weather?fsa=M5V
    ↓
WeatherController
    ↓
WeatherCacheService
    ├── Layer 1: Laravel Cache (fast, in-memory)
    ├── Layer 2: WeatherCache DB table (durable, 30min TTL)
    └── Layer 3: WeatherFetchService → EnvironmentCanadaWeatherProvider
                ↓
            weather.gc.ca API
```

### Data Flow

1. **Location Selection:** User selects a location via postal code search or geolocation; FSA is stored in `localStorage`.
2. **Weather Request:** Frontend calls `GET /api/weather?fsa={FSA}`.
3. **Cache Resolution:** `WeatherCacheService` checks fast cache, then durable cache, then upstream.
4. **Provider Fetch:** If cache miss, `WeatherFetchService` tries providers in order until one succeeds.
5. **Response:** Weather data is returned as snake_case JSON; frontend maps to camelCase domain types.

---

## Backend Components

### DTOs

#### `App\Services\Weather\DTOs\WeatherData`

Readonly value object representing current weather conditions:

| Field | Type | Description |
|-------|------|-------------|
| `fsa` | string | 3-character FSA (e.g., "M5V") |
| `provider` | string | Provider name (e.g., "environment_canada") |
| `temperature` | float/null | Temperature in Celsius |
| `humidity` | float/null | Relative humidity percentage |
| `windSpeed` | string/null | Wind speed with units (e.g., "15 km/h") |
| `windDirection` | string/null | Cardinal direction (e.g., "NW") |
| `condition` | string/null | Weather condition text (e.g., "Partly Cloudy") |
| `alertLevel` | string/null | Severity: 'yellow', 'orange', 'red', or null |
| `alertText` | string/null | Human-readable alert description |
| `fetchedAt` | DateTimeImmutable | Timestamp of data retrieval |

### Contracts

#### `App\Services\Weather\Contracts\WeatherProvider`

Interface for weather data providers:

```php
interface WeatherProvider
{
    /** Fetch current weather conditions for the given FSA. */
    public function fetch(string $fsa): WeatherData;

    /** Return the stable machine-readable provider name. */
    public function name(): string;
}
```

### Services

#### `App\Services\Weather\WeatherFetchService`

Orchestrates provider fallback chain. Accepts an ordered array of providers; tries each in sequence until one succeeds. Throws `WeatherFetchException` if all providers fail.

#### `App\Services\Weather\WeatherCacheService`

Implements three-layer cache resolution:

1. **Fast Cache (Laravel Cache):** In-memory lookup by FSA; 30-minute TTL.
2. **Durable Cache (WeatherCache table):** Database-stored payload; same 30-minute TTL.
3. **Upstream Fetch:** Calls `WeatherFetchService` and populates both cache layers.

Cache hit order: Fast → Durable → Upstream. Each layer populates the layers above it on miss.

#### `App\Services\Weather\Providers\EnvironmentCanadaWeatherProvider`

Primary provider using the Environment Canada JSON API (`weather.gc.ca/api/app/v3/en/Location`).

**Coordinate Resolution:**
- Looks up FSA in `gta_postal_codes` table for centroid coordinates.
- Falls back to Toronto core coordinates (43.6532, -79.3832) if FSA not found.

**API Parsing:**
- Handles both object and array-wrapped JSON responses.
- Extracts observation data (temperature, humidity, wind, condition).
- Parses alert severity (`yellow`, `orange`, `red`) and banner text.

### Models

#### `App\Models\GtaPostalCode`

Reference table containing ~200 GTA postal code FSAs with centroid coordinates.

| Column | Type | Description |
|--------|------|-------------|
| `fsa` | string (PK) | 3-character FSA |
| `municipality` | string | City/municipality name |
| `neighbourhood` | string|null | Neighbourhood or area name |
| `lat` | float | Centroid latitude |
| `lng` | float | Centroid longitude |

**Key Methods:**
- `normalize(string $input): string` — Normalizes any postal code format to FSA.
- `search(string $query): Builder` — Searches by FSA, municipality, or neighbourhood.
- `nearestFsa(float $lat, float $lng): ?static` — Finds closest FSA by squared Euclidean distance.

#### `App\Models\WeatherCache`

Durable cache table for weather payloads.

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint (PK) | Auto-increment |
| `fsa` | string | FSA identifier |
| `provider` | string | Provider name |
| `payload` | json | Serialized WeatherData fields |
| `fetched_at` | datetime | Cache timestamp |
| `created_at` | timestamp | Record creation |
| `updated_at` | timestamp | Record update |

**TTL:** 30 minutes (`TTL_MINUTES` constant).

---

## API Endpoints

All weather endpoints are public (no authentication required) and rate-limited.

### GET /api/weather

Returns current weather conditions for the specified FSA.

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `fsa` | string | Yes | Postal code or FSA (e.g., "M5V" or "M5V 1A1") |

**Validation:**
- Must match Canadian postal code format: `A1A 1A1`
- FSA must exist in `gta_postal_codes` table

**Success Response (200):**

```json
{
  "data": {
    "fsa": "M5V",
    "provider": "environment_canada",
    "temperature": 18.5,
    "humidity": 65.0,
    "wind_speed": "15 km/h",
    "wind_direction": "NW",
    "condition": "Partly Cloudy",
    "alert_level": null,
    "alert_text": null,
    "fetched_at": "2026-03-26T14:30:00+00:00"
  }
}
```

**Error Responses:**

| Status | Condition |
|--------|-----------|
| `422 Unprocessable Entity` | Invalid postal code format or FSA not in GTA |
| `503 Service Unavailable` | All weather providers failed |
| `429 Too Many Requests` | Rate limit exceeded (60 requests/minute) |

### GET /api/postal-codes

Search for GTA postal codes by FSA, municipality, or neighbourhood.

**Query Parameters:**

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `q` | string | Yes | Search query (min 2 characters) |
| `limit` | integer | No | Max results (1-50, default 10) |

**Success Response (200):**

```json
{
  "data": [
    {
      "fsa": "M5V",
      "municipality": "Toronto",
      "neighbourhood": "Fashion District",
      "lat": 43.6441,
      "lng": -79.3972
    }
  ]
}
```

**Error Response:**

| Status | Condition |
|--------|-----------|
| `429 Too Many Requests` | Rate limit exceeded (60 requests/minute) |

### POST /api/postal-codes/resolve-coords

Find the nearest FSA to given coordinates (for geolocation feature).

**Request Body:**

```json
{
  "lat": 43.6532,
  "lng": -79.3832
}
```

**Validation:**
- `lat`: 43.0 to 44.5 (GTA bounds)
- `lng`: -80.5 to -78.5 (GTA bounds)

**Success Response (200):**

```json
{
  "data": {
    "fsa": "M5H",
    "municipality": "Toronto",
    "neighbourhood": "Financial District",
    "lat": 43.6509,
    "lng": -79.3843
  }
}
```

**Error Response:**

| Status | Condition |
|--------|-----------|
| `404 Not Found` | No postal code found |
| `422 Unprocessable Entity` | Coordinates outside GTA bounds |
| `429 Too Many Requests` | Rate limit exceeded (60 requests/minute) |

---

## Frontend Integration

### State Management

`resources/js/features/gta-alerts/hooks/useWeather.ts` manages location and weather state:

```ts
const {
  location,     // WeatherLocation | null
  weather,      // WeatherData | null
  isLoading,    // boolean — true only on initial fetch
  error,        // string | null
  setLocation,  // (location: WeatherLocation | null) => void
  refresh,      // () => void — revalidate current location
} = useWeather();
```

**localStorage Key:** `gta_weather_location_v1`

Stored location shape:
```ts
{
  fsa: "M5V",
  label: "Toronto — Fashion District",
  lat: 43.6441,
  lng: -79.3972
}
```

### Domain Types

`resources/js/features/gta-alerts/domain/weather/types.ts`:

```ts
type WeatherData = {
  fsa: string;
  provider: string;
  temperature: number | null;
  humidity: number | null;
  windSpeed: string | null;
  windDirection: string | null;
  condition: string | null;
  alertLevel: 'yellow' | 'orange' | 'red' | null;
  alertText: string | null;
  fetchedAt: string; // ISO 8601
};

type WeatherLocation = {
  fsa: string;
  label: string;
  lat: number;
  lng: number;
};
```

### API Validation

`resources/js/features/gta-alerts/domain/weather/resource.ts` defines Zod schemas:

- `WeatherResourceSchema` — Validates snake_case API response
- `PostalCodeResourceSchema` — Validates postal code search results

### Mapping

`resources/js/features/gta-alerts/domain/weather/fromResource.ts` converts snake_case API responses to camelCase domain objects with validation.

---

## Configuration

`config/weather.php`:

```php
return [
    // Ordered list of provider classes
    'providers' => [
        App\Services\Weather\Providers\EnvironmentCanadaWeatherProvider::class,
    ],

    // HTTP timeout for provider requests
    'timeout_seconds' => env('WEATHER_TIMEOUT_SECONDS', 10),

    // Environment Canada provider settings
    'environment_canada' => [
        'base_url' => env('WEATHER_EC_BASE_URL', 'https://weather.gc.ca'),
        'api_path' => '/api/app/v3/en/Location',
        'default_coords' => [
            'lat' => env('WEATHER_EC_DEFAULT_LAT', 43.6532),
            'lng' => env('WEATHER_EC_DEFAULT_LNG', -79.3832),
        ],
    ],
];
```

### Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `WEATHER_TIMEOUT_SECONDS` | 10 | HTTP timeout for weather API calls |
| `WEATHER_EC_BASE_URL` | https://weather.gc.ca | Environment Canada base URL |
| `WEATHER_EC_DEFAULT_LAT` | 43.6532 | Fallback latitude |
| `WEATHER_EC_DEFAULT_LNG` | -79.3832 | Fallback longitude |

---

## Caching Strategy

The Weather feature uses a three-tier cache to balance freshness with performance:

### Layer 1: Fast Cache (Laravel Cache)
- **Driver:** Configured Laravel cache (typically Redis or file)
- **Key Format:** `weather.current.{FSA}`
- **TTL:** 30 minutes
- **Purpose:** Sub-millisecond lookups for repeat requests

### Layer 2: Durable Cache (Database)
- **Table:** `weather_caches`
- **TTL:** 30 minutes (`fetched_at > now() - 30min`)
- **Purpose:** Survives cache clears; reduces upstream calls during deployments

### Layer 3: Upstream Provider
- **Source:** Environment Canada API
- **Timeout:** 10 seconds (configurable)
- **Fallback:** Default coordinates if FSA not in database

### Cache Population Flow

1. Request arrives for FSA "M5V"
2. Check Laravel Cache → Hit: return immediately
3. Check `WeatherCache::findValid()` → Hit: populate fast cache, return
4. Call `WeatherFetchService::fetch()` → Success: populate both caches, return
5. All providers fail → Return 503 error

---

## Error Handling

### Backend Exceptions

`App\Services\Weather\Exceptions\WeatherFetchException`:

```php
throw new WeatherFetchException(
    fsa: 'M5V',
    provider: 'environment_canada',
    reason: 'HTTP connection failed: timeout',
    previous: $e
);
```

Exception includes FSA, provider name, and human-readable reason for logging.

### Frontend Error States

| Error | UX Behavior |
|-------|-------------|
| Initial fetch fails | `isLoading` stays true, `error` set, stale data not shown |
| Background refresh fails | Existing weather remains visible, `error` set |
| Invalid location | 422 response, location cleared from state |
| Provider unavailable | 503 response, "temporarily unavailable" message |

### Request Cancellation

`useWeather` uses `AbortController` to cancel in-flight requests when:
- User changes location
- Component unmounts
- Refresh triggered while previous fetch pending

---

## File Reference

### Backend

- `app/Models/GtaPostalCode.php`
- `app/Models/WeatherCache.php`
- `app/Services/Weather/Contracts/WeatherProvider.php`
- `app/Services/Weather/DTOs/WeatherData.php`
- `app/Services/Weather/Exceptions/WeatherFetchException.php`
- `app/Services/Weather/WeatherFetchService.php`
- `app/Services/Weather/WeatherCacheService.php`
- `app/Services/Weather/Providers/EnvironmentCanadaWeatherProvider.php`
- `app/Http/Controllers/Weather/WeatherController.php`
- `app/Http/Controllers/Weather/PostalCodeSearchController.php`
- `app/Http/Controllers/Weather/PostalCodeResolveCoordsController.php`
- `app/Http/Resources/WeatherResource.php`
- `config/weather.php`
- `routes/web.php` (weather routes)

### Frontend

- `resources/js/features/gta-alerts/hooks/useWeather.ts`
- `resources/js/features/gta-alerts/domain/weather/types.ts`
- `resources/js/features/gta-alerts/domain/weather/resource.ts`
- `resources/js/features/gta-alerts/domain/weather/fromResource.ts`

### Tests

- `tests/Feature/Weather/WeatherControllerTest.php`
- `tests/Feature/Weather/PostalCodeSearchControllerTest.php`
- `tests/Feature/Weather/PostalCodeResolveCoordsControllerTest.php`
- `tests/Feature/Services/Weather/WeatherCacheServiceTest.php`
- `tests/Feature/Services/Weather/EnvironmentCanadaWeatherProviderTest.php`
- `tests/Unit/Models/WeatherCacheTest.php`
- `tests/Unit/Services/Weather/WeatherFetchServiceTest.php`

### Database

- `database/migrations/*_create_gta_postal_codes_table.php`
- `database/migrations/*_create_weather_caches_table.php`
