# Phase 3 Preflight: Provider Contract + Tagged Injection Audit

Date: 2026-02-04

This document captures the Phase 3 audit and design decisions needed before refactoring providers and `UnifiedAlertsQuery` for dependency inversion (tagged injection).

## 1) Provider Contract Compliance Audit

### Unified select schema (expected columns)

Providers are expected to output the unified row schema (flat columns) consumed by `UnifiedAlertMapper`:

- `id` (string, non-empty)
- `source` (string, non-empty)
- `external_id` (string, non-empty)
- `is_active` (boolish)
- `timestamp` (required, parseable)
- `title` (string, non-empty)
- `location_name` (string|null)
- `lat` (numeric|null)
- `lng` (numeric|null)
- `meta` (json string|array|null)

### `external_id` string-casting

Goal: ensure UNION consistency and deterministic ordering by selecting `external_id` as an explicit string in *all* provider SELECTs.

Current state (as of 2026-02-04):
- `PoliceAlertSelectProvider` already casts `external_id`:
  - sqlite: `CAST(object_id AS TEXT)`
  - non-sqlite: `CAST(object_id AS CHAR)`
- `FireAlertSelectProvider` selects `event_num as external_id` without an explicit cast.
- `TransitAlertSelectProvider` is a placeholder returning no rows (`where 1 = 0`) and currently selects `NULL as external_id`.

Recommended refactor (Phase 3 implementation):
- Fire:
  - sqlite: `CAST(event_num AS TEXT) as external_id`
  - non-sqlite: `CAST(event_num AS CHAR) as external_id`
- Transit placeholder: keep empty, but when implementing real transit data, explicitly cast `external_id` to string as well.

## 2) Tagged Injection Feasibility (Laravel 12)

Laravel 12 provides parameter-level tagged resolution via `Illuminate\Container\Attributes\Tag`.

Verified in this codebase:
- `Illuminate\Container\Attributes\Tag` exists and resolves via `$container->tagged($tag)`.

### Proposed tag name

Use a stable container tag for select providers:

- Tag string: `alerts.select-providers`

### Proposed container registration

In `App\Providers\AppServiceProvider::register()` (Phase 3 implementation):

- Tag providers:
  - `App\Services\Alerts\Providers\FireAlertSelectProvider`
  - `App\Services\Alerts\Providers\PoliceAlertSelectProvider`
  - `App\Services\Alerts\Providers\TransitAlertSelectProvider`

### Proposed `UnifiedAlertsQuery` constructor shape

Refactor `UnifiedAlertsQuery` to accept providers via tagged injection:

- `#[Tag('alerts.select-providers')] iterable $providers`
- `UnifiedAlertMapper $mapper`

Notes:
- Keep the outer ordering `(timestamp desc, source asc, external_id desc)` unchanged.
- The UNION build should handle an empty provider iterable defensively (return empty results rather than throwing).

## 3) Test Plan (Phase 3 Implementation)

### Tagged injection resolution

Add a unit/feature test that:
- Registers a fake provider in the container and tags it with `alerts.select-providers`.
- Resolves `UnifiedAlertsQuery` from the container.
- Asserts the fake provider’s row appears in results (and that existing providers can be excluded/controlled in the test container).

### Provider contract regression tests

Update provider unit tests to assert `external_id` is selected as a string for each driver branch (`sqlite` vs `mysql`) by inspecting SQL or via returned row types where feasible.

