# FEED-034: First-Visit Weather Location Prompt Gating

**Type:** Bug  
**Priority:** P1  
**Status:** Closed  
**Component:** GTA Alerts Frontend (Weather Onboarding / Location Picker)

---

## Summary

Implemented a first-visit weather onboarding flow that prompts users once for location access, persists onboarding decisions, and prevents repeat intrusive prompts. The implementation reuses the existing `LocationPicker` geolocation path and keeps weather fetch behavior intact.

---

## Findings (Priority Order)

### P1 - No first-visit-only prompt gate exists for weather location onboarding

**Reviewer reference:** `resources/js/features/gta-alerts/App.tsx:465-468`, `resources/js/features/gta-alerts/hooks/useWeather.ts:16-22`, `resources/js/features/gta-alerts/components/LocationPicker.tsx:142-154`  
**Issue:** There is no first-visit detection state and no automatic onboarding prompt path. `useWeather` only restores a previously selected location from `localStorage`, and geolocation is invoked only from manual button click.

**Resolution:**
- Added first-visit onboarding state to `useWeather` via `shouldPromptForLocation`.
- Added onboarding persistence key `gta_weather_location_prompt_v1`.
- Added prompt controls in `App` (`Use my location`, `Not now`) shown only when onboarding has not been handled and no location is selected.

---

### P2 - No persisted prompt decision state (accepted/declined/deferred) separate from selected location

**Reviewer reference:** `resources/js/features/gta-alerts/hooks/useWeather.ts:9-56`  
**Issue:** Only selected location is persisted (`gta_weather_location_v1`). There is no durable state to track whether onboarding has already been shown/handled.

**Resolution:**
- Added `markLocationPromptHandled(result)` to `useWeather`.
- Persisted onboarding decisions as handled states (`accepted`, `declined`, `deferred`).
- Ensured selecting a location marks onboarding as `accepted`.

---

### P3 - Missing test coverage for first-visit onboarding behavior contract

**Reviewer reference:** `resources/js/features/gta-alerts/components/LocationPicker.test.tsx:214-228`, `resources/js/features/gta-alerts/hooks/useWeather.test.ts:70-94`  
**Issue:** Existing tests verify manual geolocation behavior and stored-location hydration, but do not assert:
- prompt shown only for first-time visitors,
- no repeat prompt after prior decision,
- fallback behavior when permission is denied.

**Resolution:**
- Added/updated frontend tests to cover:
- prompt visible on true first visit,
- prompt hidden when onboarding already handled,
- `Not now` persists deferred handling and dismisses prompt,
- prompt `Use my location` triggers geolocation,
- onboarding persistence and gating behavior in `useWeather`,
- ref-driven geolocation trigger and result callback coverage in `LocationPicker`.

---

## Boundary Check (Laravel -> Inertia -> React)

- Changes are frontend-local state/UI behavior only.
- No Laravel serialization updates were required.
- No Inertia shared/page prop contracts changed.
- No backend resource TypeScript contract changes were required.

---

## Files Changed

- `resources/js/features/gta-alerts/hooks/useWeather.ts`
- `resources/js/features/gta-alerts/hooks/useWeather.test.ts`
- `resources/js/features/gta-alerts/components/LocationPicker.tsx`
- `resources/js/features/gta-alerts/components/LocationPicker.test.tsx`
- `resources/js/features/gta-alerts/App.tsx`
- `resources/js/features/gta-alerts/App.test.tsx`
- `docs/tickets/FEED-034-first-visit-weather-location-prompt.md`

---

## Verification

### Narrow test scope first

- `pnpm exec vitest run resources/js/features/gta-alerts/hooks/useWeather.test.ts resources/js/features/gta-alerts/components/LocationPicker.test.tsx resources/js/features/gta-alerts/App.test.tsx` -> **PASS**

### Full suite

- `composer test` -> **PASS**

### Required quality gates

- `./vendor/bin/pint` -> **PASS**
- `composer lint && pnpm run lint && pnpm run format && pnpm run types` -> **PASS**

### Environment note

- Sail commands could not be executed in this run because Docker was unavailable (`vendor/bin/sail up -d` returned "Docker is not running."). Equivalent local commands above were executed successfully.

---

## Acceptance Criteria

- [x] First-time visitor sees a location onboarding prompt exactly once.
- [x] Returning visitor is not auto-prompted after prior handled decision.
- [x] Successful accept path stores location and weather fetch proceeds as before.
- [x] Decline/defer path avoids repeated intrusive prompts and preserves manual location selection.
- [x] Existing weather display, search, feed, and saved-alert behavior remain unchanged.
- [x] All targeted tests and full suite checks pass.

---

## Notes

These fixes are part of Phase 6: Weather Onboarding Consent Flow.
