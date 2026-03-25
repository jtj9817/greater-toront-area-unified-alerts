# FEED-027: Weather Cache TTL Boundary Inconsistency Between `isFresh()` and `findValid()`

**Type:** Bug  
**Priority:** P2  
**Status:** Resolved  
**Component:** Backend - Weather cache model

---

## Summary

`WeatherCache::findValid()` and `WeatherCache::isFresh()` used different TTL-boundary semantics. At the exact cutoff time, `findValid()` could return a record that `isFresh()` immediately considered expired.

---

## Findings (Priority Order)

### P2 - Make `findValid` TTL check consistent with `isFresh`

**Reviewer reference:** `app/Models/WeatherCache.php:39`  
**Issue:** `findValid()` used `fetched_at >= now()->subMinutes($ttl)`, while `isFresh()` used strict `gt(...)`.  
**Risk:** Stale-at-boundary entries could be served when retrieval uses `findValid()`.

---

## Root Cause

Two cache-validity helpers implemented different comparisons for the same TTL contract:
- `isFresh()` treated boundary records as expired (`>`)
- `findValid()` treated boundary records as valid (`>=`)

---

## Resolution

Updated `findValid()` to use strict boundary semantics:
- `where('fetched_at', '>=', ...)` -> `where('fetched_at', '>', ...)`

Added regression coverage for the exact TTL boundary.

---

## Files Changed

- `app/Models/WeatherCache.php`
- `tests/Unit/Models/WeatherCacheTest.php`
- `docs/tickets/FEED-027-weather-cache-ttl-boundary-consistency.md`

---

## Verification

### Targeted test
- `php artisan test --filter=WeatherCacheTest` -> **PASS**

### Full suite
- `composer test` -> **PASS**

### Quality gates
- `./vendor/bin/pint` -> **PASS**
- `composer lint && pnpm run lint && pnpm run format && pnpm run types` -> **PASS**

---

## Notes

- No Laravel -> Inertia -> React data shape changes were introduced.
- Fix is scoped to cache TTL validity semantics and unit-test coverage.
- These fixes are part of Phase 1.
