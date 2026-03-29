# FEED-043: Bugfix — Footer Weather Detail Panel State Not Synced to Weather Availability

## Summary

When the footer weather detail panel is open and the underlying `weather` prop later becomes unavailable (or no longer has any detail rows), the panel's open state can become stale. This leaves document-level event listeners attached and can cause the panel to reappear automatically when weather data returns, even though the user did not click to reopen it.

## Component

- GTA Alerts Frontend (Footer weather bar / detail panel)

## Linked Issues

- Relates to: [FEED-042 — Feels Like Algorithm + Weather Detail Panel](FEED-042-feels-like-weather-detail-panel.md)

## Findings

### P2 - Reset detail panel open state when weather data becomes unavailable

**Reviewer reference:** `resources/js/features/gta-alerts/components/Footer.tsx:92-95`  
**Issue:** `isPanelOpen` is not synchronized with weather availability. When `weather` becomes `null` (or yields zero detail rows), the panel stops rendering due to `weather` checks, but `isPanelOpen` remains `true`. The document listeners remain attached and the panel can auto-reopen when `weather` becomes available again.  
**Risk:** Unexpected UI behavior (auto-reopen), stale event listeners, and a disabled trigger preventing the user from closing a now-hidden panel.

**Fix:**

- Force-close the panel whenever `canOpenPanel` becomes false (no weather or no detail rows).
- Gate panel rendering with `canOpenPanel` to prevent rendering an empty panel during the closing render.

**Files:**

- `resources/js/features/gta-alerts/components/Footer.tsx`
- `resources/js/features/gta-alerts/components/Footer.test.tsx`

## Verification

- `vendor/bin/sail pnpm exec vitest run resources/js/features/gta-alerts/components/Footer.test.tsx` -> **PASS**
- `vendor/bin/sail composer test` -> **PASS**
- `vendor/bin/sail bin pint app/Services/Weather/FeelsLikeCalculator.php app/Services/Weather/Providers/EnvironmentCanadaWeatherProvider.php` -> **PASS**
- `vendor/bin/sail composer lint` -> **PASS**
- `vendor/bin/sail pnpm run lint` -> **PASS**
- `vendor/bin/sail pnpm run format` -> **PASS**
- `vendor/bin/sail pnpm run types` -> **PASS**

## Resolution

- [x] Close panel whenever `canOpenPanel` becomes false (prevents stale open state)
- [x] Add regression test preventing auto-reopen across `weather` prop changes
- [x] All required tests and quality gates pass
