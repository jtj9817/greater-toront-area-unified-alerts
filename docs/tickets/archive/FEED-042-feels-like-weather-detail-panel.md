# FEED-042 — Feels Like Algorithm + Weather Detail Panel

**Date:** 2026-03-28
**Status:** Closed
**Priority:** Medium
**Components:** Backend, Frontend
**Implemented By:** commit `fd9ec535` ("feat(weather): FEED-042 — Feels Like algorithm, extended observations, detail panel")

## Summary

Add a computed **Feels Like** temperature to the weather feature using standard meteorological formulas (Wind Chill, Humidex), expose all non-empty Environment Canada observation fields through the API, and surface them in a click-triggered detail panel anchored to the footer weather bar.

---

## Background

The footer weather bar (`id="gta-alerts-footer-weather"`) currently shows temperature, humidity, and wind as a single inline string. The Environment Canada API returns a richer observation payload — dewpoint, pressure, visibility, wind gust, wind bearing, tendency, and station name — none of which are currently parsed, stored, or displayed. Additionally, the API's own `feelsLike` field is frequently empty; calculating it locally from the available raw values is both more reliable and more accurate.

---

## Acceptance Criteria

### Algorithm

- [x] **Wind Chill** is applied when `T ≤ 10 °C` and wind speed `> 4.8 km/h`:
  ```
  Wind Chill = 13.12 + 0.6215·T − 11.37·W^0.16 + 0.3965·T·W^0.16
  ```
  where `T` = temperature (°C), `W` = wind speed (km/h).
- [x] **Humidex** is applied when `T ≥ 20 °C` and dewpoint is available:
  ```
  Humidex = T + 0.5555 × (6.11 × exp(5417.753 × (1/273.16 − 1/(273.15 + Td))) − 10)
  ```
  where `Td` = dewpoint (°C).
- [x] Returns `null` when neither condition is met (e.g., `10 < T < 20 °C`).
- [x] Result is rounded to one decimal place.

### Backend

- [x] `WeatherData` DTO gains fields: `feelsLike`, `dewpoint`, `pressure`, `visibility`, `windGust`, `tendency`, `observedAt` (all nullable).
- [x] `EnvironmentCanadaWeatherProvider` parses all new fields from `observation` and computes `feelsLike`.
- [x] `WeatherCacheService` persists and hydrates all new fields through the DB payload column.
- [x] `WeatherResource` exposes all new fields in the JSON response (snake_case keys).
- [x] Raw numeric wind speed (km/h) is used internally for the Wind Chill calculation; it is not added as a separate public DTO field.

### Frontend

- [x] `WeatherData` domain type and `WeatherResourceSchema` include all new fields.
- [x] `fromWeatherResource` maps all new fields (snake_case → camelCase).
- [x] Clicking the `id="gta-alerts-footer-weather"` element opens a detail panel anchored above the footer.
- [x] The panel lists every field that is non-null **and** non-empty string, excluding the timestamp (`fetchedAt`).
- [x] `feelsLike` is shown prominently at the top of the panel when available.
- [x] The panel closes on click-outside and on `Escape`, consistent with the `MinimalModeToggle` pattern.
- [x] The footer weather bar shows a visual affordance (cursor pointer, subtle indicator) that it is clickable.
- [x] All new fields have correct `id` attributes for testability.

### Tests

- [x] PHP unit tests cover Wind Chill and Humidex across boundary cases (at-threshold, above, below, null inputs).
- [x] Updated provider fixture includes dewpoint, pressure, visibility, windGust, tendency, observedAt.
- [x] Frontend Footer tests cover: panel opens on click, panel closes on outside click / Escape, `feelsLike` is shown, null/empty fields are omitted from panel.

---

## Fields Sourced from Environment Canada `observation`

| API key | DTO field | Resource key | Notes |
|---|---|---|---|
| `temperature.metricUnrounded` | `temperature` | `temperature` | Already implemented |
| `humidity` | `humidity` | `humidity` | Already implemented |
| `windSpeed.metric` | *(internal float)* | — | Used for Wind Chill only |
| `windSpeed` formatted | `windSpeed` | `wind_speed` | Already implemented |
| `windDirection` | `windDirection` | `wind_direction` | Already implemented |
| `condition` | `condition` | `condition` | Already implemented |
| `dewpoint.metricUnrounded` | `dewpoint` | `dewpoint` | **New** |
| `pressure.metric` | `pressure` | `pressure` | **New** |
| `visibility.metric` | `visibility` | `visibility` | **New** |
| `windGust.metric` formatted | `windGust` | `wind_gust` | **New** |
| `tendency` | `tendency` | `tendency` | **New** |
| `observedAt` | `observedAt` | `observed_at` | **New** |
| *(computed)* | `feelsLike` | `feels_like` | **New** |

---

## Out of Scope

- Displaying `fetchedAt` timestamp anywhere in the UI.
- Adding imperial unit support.
- Hourly forecast data.
- Historical trend charts.

---

## Implementation Notes

- The Wind Chill formula is Environment Canada's official formula (same as the NWS formula).
- Humidex is the Canadian standard for apparent temperature in warm/humid conditions.
- Wind speed for calculation purposes is parsed from `observation.windSpeed.metric` as a raw float before formatting it into the `"N km/h"` string.
- The detail panel follows the `MinimalModeToggle` popup pattern: absolute positioning above the footer, `useEffect`-based click-outside + Escape handling, no Radix UI.
- Existing `WeatherCache` DB rows will hydrate without the new fields (nullable fallback); no migration required since `payload` is a JSON column.
