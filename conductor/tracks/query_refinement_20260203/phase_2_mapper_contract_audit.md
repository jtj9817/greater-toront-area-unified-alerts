# Phase 2 Preflight: Mapper Contract Audit

Date: 2026-02-04

This document captures the mapping contracts enforced by `App\Services\Alerts\Mappers\UnifiedAlertMapper` and the places those rules are tested.

## Timestamp Contract

- **Required:** `timestamp` must be present and non-empty.
- **Parseable:** `timestamp` must be parseable by `CarbonImmutable::parse(...)`.
- **Failure mode:** If missing/empty, throw `InvalidArgumentException` ("Unified alert timestamp is required."). If unparseable, throw `InvalidArgumentException` ("Unified alert timestamp is not parseable.").
- **Enforcement location:** Mapper (`UnifiedAlertMapper::fromRow()`), so providers and the unified query fail-fast if they emit invalid timestamps.

## Unified Row Schema (Expected Properties)

Required (must be present and stringable to a non-empty string unless noted):
- `id` (string; non-empty)
- `source` (string; non-empty)
- `external_id` (string; non-empty)
- `is_active` (boolish; cast via `(bool)`)
- `timestamp` (string/date; required and parseable)
- `title` (string; non-empty)

Optional:
- `location_name` (string|null)
- `lat` (numeric|string|null)
- `lng` (numeric|string|null)
- `meta` (array|string|null)

## Meta Decoding Contract

`UnifiedAlertMapper::decodeMeta(mixed $value): array`

- If `$value` is an array: return as-is.
- If `$value` is a non-empty string:
  - Decode JSON with `JSON_THROW_ON_ERROR`.
  - If decoded value is an array: return it.
  - Otherwise (scalar/null): return `[]`.
- For `null`/empty string/invalid JSON: return `[]` (never leak JSON exceptions).

## Location Construction Rules

- If `location_name`, `lat`, and `lng` are all `null`: `location` is `null`.
- Otherwise create an `AlertLocation`:
  - `name`: `string|null`
  - `lat`/`lng`: cast to `float` when non-null (including `0.0` which must be preserved).

## Unit-Test Matrix & Test Placement

Unit coverage for mapper logic lives in:
- `tests/Unit/Services/Alerts/Mappers/UnifiedAlertMapperTest.php`

Provider tests reuse the mapper’s meta decoder to avoid drift:
- `tests/Unit/Services/Alerts/Providers/FireAlertSelectProviderTest.php`
- `tests/Unit/Services/Alerts/Providers/PoliceAlertSelectProviderTest.php`

