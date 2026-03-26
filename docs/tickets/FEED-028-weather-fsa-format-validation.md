# FEED-028: Reject Malformed Weather Postal Inputs Before FSA Normalization

**Type:** Bug  
**Priority:** P2  
**Status:** Closed  
**Component:** Backend - Weather API

---

## Summary

`GET /api/weather` accepted malformed `fsa` strings (for example, `M5VXYZ`) because validation allowed any short string and normalization truncated to the first 3 alphanumeric characters. This could silently resolve to a different intended location and return weather data for the wrong FSA.

---

## Findings (Priority Order)

### P2 - Reject malformed postal input before FSA normalization

**Reviewer reference:** `app/Http/Controllers/Weather/WeatherController.php:17-21`  
**Issue:** The endpoint validated `fsa` too loosely and normalized by truncation.  
**Risk:** Mistyped/noisy postal input sharing a valid prefix could return weather for an unintended GTA area.

---

## Root Cause

Request validation only enforced `required|string|max:10`, which did not enforce Canadian FSA/postal structure before calling `GtaPostalCode::normalize(...)`.

---

## Resolution

- Tightened `fsa` validation in `WeatherController` to require either:
- FSA format (`A1A`)
- Full postal code format (`A1A 1A1` or `A1A1A1`, case-insensitive)
- Added feature-test regression coverage proving malformed input (`M5VXYZ`) returns `422` and does not call `WeatherCacheService::get`.

---

## Files Changed

- `app/Http/Controllers/Weather/WeatherController.php`
- `tests/Feature/Weather/WeatherControllerTest.php`
- `docs/tickets/FEED-028-weather-fsa-format-validation.md`

---

## Verification

### Targeted test
- `php artisan test --filter=WeatherControllerTest` -> **PASS**

### Full suite
- `composer test` -> **PASS**

### Quality gates
- `./vendor/bin/pint` -> **PASS**
- `composer lint && pnpm run lint && pnpm run format && pnpm run types` -> **PASS**

---

## Boundary Check

- No Laravel -> Inertia -> React data shape changes were introduced.
- Behavior change is limited to stricter request validation for malformed weather postal input.

These fixes are part of Phase 3.
