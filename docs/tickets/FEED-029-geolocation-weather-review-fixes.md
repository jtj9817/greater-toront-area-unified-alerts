# FEED-029: Geolocation Resolve CSRF and Weather Request Race Fixes

**Type:** Bug  
**Priority:** P1  
**Status:** Closed  
**Component:** GTA Alerts Frontend (Location Picker / Weather Hook)

---

## Summary

Review findings identified four regressions in the location and weather flow:

1. `POST /api/postal-codes/resolve-coords` requests from geolocation did not include CSRF token headers.
2. In-flight weather requests were not canceled when switching from one non-null location to another.
3. Geolocation error UI remained visible after successful manual location selection.
4. Geolocation loading spinner class concatenation was malformed and prevented animation.

All four findings were fixed in priority order with minimal scoped changes.

---

## Findings (Priority Order)

### P1 - Send CSRF token with resolve-coords request

**Reviewer reference:** `resources/js/features/gta-alerts/components/LocationPicker.tsx:148-154`  
**Issue:** Geolocation `POST` request omitted `X-CSRF-TOKEN` header.

**Resolution:**
- Added `csrfToken()` helper to read `meta[name="csrf-token"]`.
- Injected `X-CSRF-TOKEN` header when token is present.
- Kept existing `Content-Type`, `Accept`, and `X-Requested-With` headers unchanged.

---

### P2 - Cancel in-flight weather fetch when selecting new location

**Reviewer reference:** `resources/js/features/gta-alerts/hooks/useWeather.ts:177-179`  
**Issue:** `setLocation` only aborted request when `next === null`, allowing stale requests to race.

**Resolution:**
- Moved `abortControllerRef.current?.abort()` to execute on every `setLocation(next)` call.
- Existing reset behavior (`setWeather(null)`, `setError(null)`) retained.

---

### P3 - Clear geoError after successful manual location selection

**Reviewer reference:** `resources/js/features/gta-alerts/components/LocationPicker.tsx:241-244`  
**Issue:** Manual result selection did not clear prior geolocation error banner.

**Resolution:**
- Added `setGeoError(null)` in the result click handler immediately after successful `onSelect(...)`.

---

### P3 - Fix missing space before `animate-spin` class

**Reviewer reference:** `resources/js/features/gta-alerts/components/LocationPicker.tsx:271`  
**Issue:** Class token concatenation produced invalid class text (`text-xsanimate-spin`).

**Resolution:**
- Updated icon class expression to include a delimiter: `text-xs ${isGeoLoading ? 'animate-spin' : ''}`.

---

## Test Coverage Added/Updated

- `LocationPicker` tests now assert:
- geolocation resolve request includes `X-CSRF-TOKEN` when meta token exists.
- geolocation error is cleared after manual location selection.
- loading icon receives `animate-spin` class while geolocation is in progress.

- `useWeather` tests now assert:
- in-flight weather request is aborted when switching to a different non-null location.

---

## Files Changed

- `resources/js/features/gta-alerts/components/LocationPicker.tsx`
- `resources/js/features/gta-alerts/components/LocationPicker.test.tsx`
- `resources/js/features/gta-alerts/hooks/useWeather.ts`
- `resources/js/features/gta-alerts/hooks/useWeather.test.ts`
- `tests/manual/verify_weather_feature_phase_3_api_endpoints.php` (Pint-only pre-existing style fix required for green `composer test`)
- `docs/tickets/FEED-029-geolocation-weather-review-fixes.md`

---

## Boundary Check (Laravel -> Inertia -> React)

- No data shape changes were introduced across Laravel serialization, Inertia props, or React TypeScript contracts.
- Changes are limited to request headers, request-cancel behavior, and local UI state/class handling.

---

## Verification

### Targeted tests (changed frontend files)
- `CI=true LARAVEL_BYPASS_ENV_CHECK=1 pnpm exec vitest run resources/js/features/gta-alerts/components/LocationPicker.test.tsx resources/js/features/gta-alerts/hooks/useWeather.test.ts` -> **PASS**

### Full suite
- `composer test` -> **PASS**

### Required quality gates
- `./vendor/bin/pint` -> **PASS**
- `composer lint && pnpm run lint && pnpm run format && pnpm run types` -> **PASS**

---

## Acceptance Criteria Check

- All reported findings resolved and verified -> **PASS**
- Full suite passes (`composer test`) -> **PASS**
- No new lint/format/type errors -> **PASS**
- No unintended behavior changes outside cited findings -> **PASS**
