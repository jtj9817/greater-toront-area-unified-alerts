# Phase 5 Preflight: Type-Safe Service Boundary Audit

Date: 2026-02-05

This document captures the Phase 5 audit and preflight decisions for **Type-Safe Service Boundary** in `conductor/tracks/query_refinement_20260203/plan.md`.

## Goal (Phase 5)

Introduce type-safe query contracts:

- `AlertStatus` enum (`all`, `active`, `cleared`)
- `AlertSource` enum (`fire`, `police`, `transit`)
- `UnifiedAlertsCriteria` value object (status, per-page, explicit page)
- Update `UnifiedAlertsQuery` + `GtaAlertsController` to accept criteria/enums rather than primitives
- Update frontend types under `resources/js/features/gta-alerts/` **only if** the transport shape changes

## Current State (as of 2026-02-05)

### 1) Status contract is string-based and duplicated

- `GtaAlertsController` validates `status` with `Rule::in(['all', 'active', 'cleared'])`.
- `UnifiedAlertsQuery::paginate()` accepts a string and throws on invalid values.
- Frontend types (`resources/js/pages/gta-alerts.tsx`, `resources/js/features/gta-alerts/*`) use the same string union.

### 2) Source contract is string-based and unvalidated

- Providers emit string constants in SQL: `'fire'`, `'police'`, `'transit'`.
- `UnifiedAlertMapper` treats `source` as a non-empty string; no enum validation.
- `UnifiedAlert` DTO and `UnifiedAlertResource` expose `source` as a string.
- Frontend expects `source` to be `'fire' | 'police' | 'transit'`.

### 3) No criteria object

- Pagination uses `paginate(perPage: 50, status: $status)` and relies on `Paginator::resolveCurrentPage()`.
- No explicit `perPage` or `page` validation logic exists at the query boundary.

### 4) Spec/plan mismatch

`spec.md` includes an `AlertId` value object, but Phase 5 in `plan.md` does not.

## Risks / Drift Points

- Allowed status values are duplicated in controller, query, tests, and frontend.
- Source identifiers are duplicated across SQL, DTOs, and TS types without a single source of truth.
- Switching DTO fields to enums without explicit serialization can alter the Inertia payload shape.
- Query-level invalid status tests will need updates once the query boundary is fully typed.

## Preflight Decisions (Required Before Implementation)

### 1) Validation layer

Keep request-level validation for `status` to preserve session error behavior. Prefer `Rule::enum(AlertStatus::class)` + `nullable`, defaulting to `AlertStatus::All`.

### 2) Enum usage scope

Decide whether:

- `UnifiedAlert::source` becomes `AlertSource` (strict validation in mapper), or
- `AlertSource` is only used as constants and DTOs remain string-based.

### 3) Criteria contract

Proposed `UnifiedAlertsCriteria` fields:

- `AlertStatus $status` (default `all`)
- `int $perPage` (default 50)
- `?int $page` (optional explicit page)

Decide per-page bounds (e.g., `1..200`) and whether to clamp or reject invalid values.

### 4) Serialization compatibility

If DTOs store enums, `UnifiedAlertResource` should emit `$enum->value` to keep the payload unchanged for the frontend.

### 5) AlertId value object (spec mismatch)

Decide to implement `AlertId` during Phase 5 (align with `spec.md`) or explicitly defer in the plan.

## Impacted Files (Audit)

Backend:

- `app/Http/Controllers/GtaAlertsController.php`
- `app/Services/Alerts/UnifiedAlertsQuery.php`
- `app/Services/Alerts/Mappers/UnifiedAlertMapper.php` (if `source` becomes enum)
- `app/Services/Alerts/DTOs/UnifiedAlert.php` (if `source` becomes enum)
- `app/Http/Resources/UnifiedAlertResource.php`
- `app/Services/Alerts/Providers/*AlertSelectProvider.php` (if using enum constants)
- `app/Enums/AlertStatus.php` (new)
- `app/Enums/AlertSource.php` (new)
- `app/Services/Alerts/DTOs/UnifiedAlertsCriteria.php` (new)

Frontend (only if transport changes occur):

- `resources/js/pages/gta-alerts.tsx`
- `resources/js/features/gta-alerts/types.ts`
- `resources/js/features/gta-alerts/App.tsx`
- `resources/js/features/gta-alerts/components/FeedView.tsx`

Tests likely to update:

- `tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php`
- `tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTaggedInjectionTest.php`
- `tests/Feature/UnifiedAlerts/UnifiedAlertsMySqlDriverTest.php`
- `tests/Feature/GtaAlertsTest.php`
- `tests/manual/verify_query_refinement_phase_*` scripts that pass string `status`

## Test Plan (Phase 5)

- Add unit tests for `AlertStatus` and `AlertSource` (values, parsing helpers).
- Add unit tests for `UnifiedAlertsCriteria` defaults, bounds, and explicit page handling.
- Update UnifiedAlertsQuery tests to use criteria/enums and keep ordering/status invariants.
- Update controller tests to confirm invalid status validation and correct serialization of `filters.status`.
