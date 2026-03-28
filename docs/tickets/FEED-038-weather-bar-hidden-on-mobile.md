# FEED-038: Weather Bar Hidden on Mobile Viewport

**Type:** Bug
**Priority:** P2
**Status:** Open
**Component:** GTA Alerts Frontend (Weather / Footer / Mobile Layout)

---

## Summary

The footer weather bar is not visible on mobile viewports. On desktop, weather data (or "No location selected") renders in the page footer. On mobile, the bottom navigation bar (Feed, Inbox, Saved, Zones, Settings) replaces the footer entirely, leaving users with no way to see weather information after selecting a location.

The weather location **prompt** ("Enable local weather for your area?") does appear correctly on mobile in the header/banner region. The issue is that once a user selects a location, the resulting weather data has nowhere to render on mobile.

---

## Reproduction

1. Open `http://localhost:8080/` at a mobile viewport (375x667).
2. Observe the bottom navigation bar occupies the footer area.
3. Select a location via the weather prompt ("Use my location").
4. Weather data is not visible anywhere on the page.

**Desktop comparison:** At 1440x900, the footer displays weather info below the feed content, above the bottom edge.

---

## Evidence

- **Accessibility snapshot (mobile):** The only weather-related DOM element is the prompt paragraph `"Enable local weather for your area?"`. No `contentinfo` (footer) landmark containing weather data is present.
- **Desktop footer** renders: location name, temperature, conditions, and links (Incident Archives, Privacy Policy, System Status).
- **Mobile bottom nav** replaces the footer entirely with navigation icons.

---

## Proposed Fix

Surface weather data in the **weather prompt banner region** (header area) on mobile viewports. After a user selects a location, the banner should transition from the onboarding prompt to a compact weather display showing location, temperature, and conditions. This keeps weather visible without conflicting with the mobile bottom navigation.

### Approach

- Reuse the existing banner region (`ref=e55` area in the header) that currently houses the location prompt.
- After location selection, render a compact weather summary (e.g., "Toronto M5V | 12C Partly Cloudy") in the same banner slot.
- Desktop behavior remains unchanged (footer weather bar).

---

## Acceptance Criteria

- [ ] On mobile (<=768px), weather data is visible in the header/banner region after a location is selected.
- [ ] The weather prompt banner transitions to a compact weather display post-selection.
- [ ] Desktop layout is unaffected -- footer weather bar continues to render as before.
- [ ] "Not now" dismissal still hides the banner (no weather shown until user revisits settings).
- [ ] Weather data updates reactively (matches `useWeather` hook state).
