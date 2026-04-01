# Weather Feature (Frontend)

Frontend documentation for the Weather feature. See `docs/backend/weather.md` for the server-side architecture, caching strategy, and API contract.

## Overview

The weather feature displays live conditions for the user's selected GTA Forward Sortation Area (FSA). It is surfaced in the `Footer` component on all viewports. No authentication is required; location selection persists in `localStorage`.

## Domain Types

- Directory: `resources/js/features/gta-alerts/domain/weather/`

### WeatherData

The domain object consumed by components. All fields camelCase. Produced by `fromWeatherResource(raw)`.

| Field | Type | Notes |
|---|---|---|
| `fsa` | `string` | 3-character postal prefix |
| `provider` | `string` | Data source name |
| `temperature` | `number \| null` | Actual temperature °C |
| `feelsLike` | `number \| null` | Wind Chill or Humidex (apparent temperature °C) |
| `humidity` | `number \| null` | Relative humidity % |
| `windSpeed` | `string \| null` | Formatted speed string |
| `windDirection` | `string \| null` | Cardinal direction |
| `windGust` | `string \| null` | Gust formatted as "N km/h" |
| `condition` | `string \| null` | Text description (e.g. "Mostly Cloudy") |
| `dewpoint` | `number \| null` | °C |
| `pressure` | `number \| null` | kPa |
| `visibility` | `number \| null` | km |
| `tendency` | `string \| null` | Pressure tendency (e.g. "falling") |
| `alertLevel` | `'yellow' \| 'orange' \| 'red' \| null` | Environment Canada alert severity |
| `alertText` | `string \| null` | Alert description |
| `stationName` | `string \| null` | Observation station name |
| `fetchedAt` | `string` | ISO 8601 timestamp of last fetch |

### WeatherLocation

Represents a resolved GTA location. Persisted in `localStorage` under `gta_weather_location_v1`.

```ts
{ fsa: string; label: string; lat: number; lng: number }
```

`label` is constructed from FSA + neighbourhood + municipality by `LocationPicker`.

### Transport Layer

- Schema: `resources/js/features/gta-alerts/domain/weather/resource.ts`
- `WeatherResourceSchema` validates the snake_case JSON from `GET /api/weather?fsa=...`
- `fromWeatherResource(raw)`: converts to camelCase `WeatherData`; returns `null` and logs on validation failure
- `PostalCodeResourceSchema` validates entries from `GET /api/postal-codes?q=...`

## useWeather Hook

- File: `resources/js/features/gta-alerts/hooks/useWeather.ts`

Manages location selection and weather fetch lifecycle. Mounted once at the top of `App.tsx` and threaded down as props.

### Return value

| Field | Type | Description |
|---|---|---|
| `location` | `WeatherLocation \| null` | Currently selected FSA location |
| `weather` | `WeatherData \| null` | Live weather for that location |
| `isLoading` | `boolean` | True only on initial fetch (no stale data, no error) |
| `error` | `string \| null` | Last fetch error message |
| `shouldPromptForLocation` | `boolean` | True on first visit before any location choice |
| `setLocation` | `(loc \| null) => void` | Select or clear location; persists to localStorage |
| `markLocationPromptHandled` | `(result) => void` | Record user's response to first-visit prompt |
| `refresh` | `() => void` | Re-fetch without clearing stale data |

### Behaviour

- Location is persisted in `localStorage` under `gta_weather_location_v1` and rehydrated on mount.
- `isLoading` is derived: `location !== null && weather === null && error === null`. Background refreshes keep stale data visible (footer never blanks out on reload).
- `refresh()` does not clear `weather`; stale data remains while the new fetch is in flight.
- First-visit prompt state is tracked separately in `localStorage` under `gta_weather_location_prompt_v1` as `{ handled: true, result: 'accepted' | 'declined' | 'deferred' }`.
- Each `setLocation` call aborts any in-flight fetch before starting a new one, preventing stale-response races.

## Footer Component

- File: `resources/js/features/gta-alerts/components/Footer.tsx`

Receives `weather: WeatherData | null` as a prop. Renders a single-line weather bar in the footer strip. When `weather` is non-null and detail rows are available, the bar is a toggle button that opens a `WeatherDetailPanel`.

### WeatherBar (inline in Footer)

- Displays temperature, optional Feels Like, humidity, and wind on one line.
- Shows a coloured alert badge (`yellow`, `orange`, `red`) when `weather.alertLevel` is non-null.
- When no location is selected: shows "No location selected" placeholder.

### WeatherDetailPanel (inline in Footer)

Popping up above the footer bar, keyed to `${fsa}-${fetchedAt}` to auto-close on stale data.

Rendered rows (only non-null, non-empty values shown):

| Row ID suffix | Label | Value source |
|---|---|---|
| `feels-like` | Feels Like | `feelsLike` °C |
| `condition` | Condition | `condition` |
| `dewpoint` | Dewpoint | `dewpoint` °C |
| `pressure` | Pressure | `pressure` kPa |
| `visibility` | Visibility | `visibility` km |
| `wind-gust` | Wind Gust | `windGust` |
| `tendency` | Tendency | `tendency` (capitalised) |
| `station-name` | Station | `stationName` |

Closes on Escape key or click-outside. Uses `aria-expanded` / `aria-haspopup` on the trigger button.

Element IDs: `gta-alerts-footer-weather`, `gta-alerts-footer-weather-panel`, `gta-alerts-footer-weather-detail-{row-id}`, `gta-alerts-footer-weather-alert`, `gta-alerts-footer-weather-no-location`.

## LocationPicker Component

- File: `resources/js/features/gta-alerts/components/LocationPicker.tsx`

Provides two ways to select a GTA location:

1. **Text search** — calls `GET /api/postal-codes?q=...&limit=10` on input (minimum 2 characters, debounced via AbortController). Results are validated with `PostalCodeResourceSchema` and rendered in a `role="listbox"` dropdown.

2. **Browser geolocation** — calls `navigator.geolocation.getCurrentPosition`, then POSTs `{ lat, lng }` to `POST /api/postal-codes/resolve-coords`. A 422 response means the user is outside the GTA bounding box. The button shows a spinner while in flight.

The component is a `forwardRef` that exposes `LocationPickerHandle.requestGeolocation()` so parent components can programmatically trigger geolocation (used for the first-visit prompt flow).

Props: `onSelect(location)`, `selectedLocation?`, `onGeolocationResult?(result)` where result is `'success' | 'denied' | 'error'`.

## Feels Like Display

`feelsLike` is computed server-side by `EnvironmentCanadaWeatherProvider` from the Environment Canada data. The frontend treats it as an opaque number — it displays as `"(Feels Like N °C)"` inline on the weather bar whenever non-null. The backend uses the Wind Chill formula for temperatures below 0°C and the Humidex formula above. See `docs/backend/weather.md` for the calculation details.
