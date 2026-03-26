# FEED-033: Prevent Stale Weather Responses From Overwriting Current Location State

**Type:** Bug  
**Priority:** P2  
**Status:** Closed  
**Component:** GTA Alerts Frontend (`useWeather` hook)

---

## Summary

`useWeather` could accept a late success/error response from a previously selected location and apply it to current state. Even with `AbortController`, late completions can still occur in edge cases and briefly show incorrect weather data.

---

## Findings (Priority Order)

### P2 - Ignore stale responses that do not match the current location FSA

**Reviewer reference:** `resources/js/features/gta-alerts/hooks/useWeather.ts`  
**Issue:** Success/error handlers always updated state when a response arrived, without validating it was still for the active location.  
**Risk:** Rapid location changes could transiently display weather for the wrong area.

---

## Root Cause

The hook canceled in-flight requests but did not guard asynchronous handlers against late arrivals from an older `fsa` request context.

---

## Resolution

- Captured the requested `fsa` per fetch invocation.
- Added a guard in both success and error handlers:
- If `locationRef.current?.fsa !== requestFsa`, ignore the response and do not mutate state.
- Added a regression test that simulates two pending requests and confirms a late first response cannot overwrite the second location’s weather.

---

## Files Changed

- `resources/js/features/gta-alerts/hooks/useWeather.ts`
- `resources/js/features/gta-alerts/hooks/useWeather.test.ts`
- `docs/tickets/FEED-033-weather-stale-response-overwrite.md`

---

## Boundary Check (Laravel -> Inertia -> React)

- No serialization or contract shape changes across Laravel, Inertia, or TypeScript models.
- Fix is strictly client-side state-safety logic in the React weather hook.

---

## Verification

### Targeted test (changed frontend files)
- `vendor/bin/sail pnpm exec vitest run resources/js/features/gta-alerts/hooks/useWeather.test.ts` -> **PASS**

### Full suite
- `vendor/bin/sail composer test` -> **PASS**

### Required quality gates
- `vendor/bin/sail bin pint` -> **PASS**
- `vendor/bin/sail composer lint && vendor/bin/sail pnpm run lint && vendor/bin/sail pnpm run format && vendor/bin/sail pnpm run types` -> **PASS**

---

## Acceptance Criteria Check

- All findings resolved and verified by tests -> **PASS**
- Full suite passes -> **PASS**
- No new lint/format/type errors -> **PASS**
- No unintended behavior changes outside scope -> **PASS**

These fixes are part of Phase 4: Frontend State & Components.
