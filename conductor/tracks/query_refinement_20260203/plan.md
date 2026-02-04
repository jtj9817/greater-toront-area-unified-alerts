# Implementation Plan: Unified Alerts Query Refinement & Robustness

## Phase 1: Test Refinement (Specification First) [checkpoint: ]
- [ ] Task: Audit `tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php` against `docs/Unified-Alerts-Query-Test-Refinement.md`.
    - [ ] Ensure the suite explicitly covers: empty dataset, DTO integrity, ordering invariants, tie density across page boundaries, and invalid status handling.
    - [ ] Audit findings snapshot (as of 2026-02-04):
        - [x] Present: empty dataset baseline test.
        - [x] Present: DTO integrity assertions for representative Fire + Police rows (basic fields).
        - [x] Present: ordering invariant helper for `(timestamp desc, source asc, external_id desc)`.
        - [x] Present: deterministic pagination coverage (page 2) and high tie density across a page boundary (no duplicates, merged IDs match expected).
        - [x] Present: strict invalid status contract (throws) + test.
        - [~] Partial: ordering invariants do not explicitly assert non-empty `id/source/externalId` per item, nor `id` uniqueness within a page.
        - [~] Partial: status filtering validates IDs, but does not assert the invariant that all returned items satisfy `isActive === true/false`.
        - [ ] Missing: meta decoding edge cases at the unified mapping level (`null`/missing/empty string/invalid JSON => `[]`, no exception leakage).
        - [ ] Missing: timestamp parsing contract + tests for `timestamp` null/unparseable (fail-fast vs filter vs fallback).
        - [ ] Missing: location edge cases (name null but lat/lng present; lat/lng = 0 must remain floats and not be treated as falsey).
        - [ ] Missing: cross-driver execution (actually running provider + unified query tests against MySQL; current tests only assert generated SQL strings).
        - [ ] Missing: performance/index assumptions (at least doc/notes, optionally lightweight assertions).
    - [ ] Add missing edge-case tests (as needed):
        - [ ] Add per-page uniqueness + non-empty identifier invariants (`id`, `source`, `externalId`) for unified results.
        - [ ] Add status invariants: `status=active` => all `isActive === true`, `status=cleared` => all `isActive === false`.
        - [ ] Invalid/empty `meta` JSON decodes to an empty array (no exceptions leak).
        - [ ] Missing/malformed timestamps: define expected behavior (prefer fail-fast exception) and test it.
        - [ ] Provider output invariants (required columns + types) are asserted in at least one place (shared helper or contract test).
    - [ ] Optional: Reduce reliance on global pagination state by passing an explicit page number to `paginate()` (requires a service API adjustment).
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Test Refinement' (Protocol in workflow.md)

## Phase 2: Extract Mapping Into a Dedicated Mapper (Unit-Tested)
- [ ] Task: Implement `UnifiedAlertMapper` (or `UnifiedAlertHydrator`) and migrate mapping logic from `UnifiedAlertsQuery`.
    - [ ] Implement `App\Services\Alerts\Mappers\UnifiedAlertMapper` with `fromRow(object $row): UnifiedAlert`.
    - [ ] Ensure `decodeMeta()` and location/timestamp normalization rules live in the mapper and are pure/deterministic.
    - [ ] Write unit tests covering meta decoding (null/empty/invalid/array), location creation rules, and timestamp parsing behavior.
    - [ ] Reduce meta-decoding drift: update provider unit tests to decode via the mapper (or a shared helper used by both), instead of duplicating ad-hoc `json_decode` logic per test file.
    - [ ] Coverage gate: `UnifiedAlertMapper` should reach 100% unit test coverage.
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Mapper Extraction' (Protocol in workflow.md)

## Phase 3: Provider Extensibility + Query Refactor (Dependency Inversion)
- [ ] Task: Refactor Providers for Contract Compliance.
    - [ ] Ensure `external_id` is explicitly cast/selected as a string in all provider SQL selects for UNION consistency.
    - [ ] Optional: introduce an `AlertSource` enum for consistent source keys (only if it measurably improves correctness).
- [ ] Task: Refactor `UnifiedAlertsQuery` for Dependency Inversion (tagged injection).
    - [ ] Write tests for tagged provider injection (inject a controlled set of providers, including a fake provider for edge-case rows).
    - [ ] Refactor `UnifiedAlertsQuery` to accept `iterable` providers via tagged injection (Open/Closed Principle).
    - [ ] Update query logic to use `UnifiedAlertMapper` for DTO creation.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Provider Extensibility' (Protocol in workflow.md)

## Phase 4: Cross-Driver Verification
- [ ] Task: MySQL Testing Environment Setup.
    - [ ] Configure a test environment/command to run PHPUnit/Pest against a MySQL instance (via Sail/Docker).
    - [ ] Optional but recommended: add CI coverage for MySQL for the provider + unified query tests.
- [ ] Task: Validate SQL Branches against MySQL.
    - [ ] Execute provider and unified query tests against MySQL and fix any driver-specific regressions (e.g., `CONCAT` vs `||`).
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Cross-Driver Verification' (Protocol in workflow.md)

## Phase 5: Type-Safe Service Boundary (Enums/Criteria)
- [ ] Task: Introduce type-safe query contracts.
    - [ ] Create `App\Enums\AlertStatus` (all, active, cleared) and use it at the controller/service boundary.
    - [ ] Create `App\Enums\AlertSource` (fire, police, transit) and use it as the canonical source identifier throughout the subsystem.
    - [ ] Create `App\Services\Alerts\DTOs\UnifiedAlertsCriteria` to encapsulate query params (status, per-page, explicit page).
    - [ ] Update `UnifiedAlertsQuery` + `GtaAlertsController` call sites to accept criteria (or equivalent) rather than string/int primitives.
    - [ ] Update any affected frontend types under `resources/js/features/gta-alerts/` if transport changes occur.
- [ ] Task: Conductor - User Manual Verification 'Phase 5: Type-Safe Boundary' (Protocol in workflow.md)

## Phase 6: Quality Gate & Finalization
- [ ] Task: Final Coverage & Audit.
    - [ ] Verify >=90% coverage on all new/modified files (notably `UnifiedAlertMapper`, `UnifiedAlertsQuery`, `AlertStatus`, `AlertSource`, `UnifiedAlertsCriteria`).
    - [ ] Run Laravel Pint for style compliance.
    - [ ] Perform security audits (`composer audit`, `pnpm audit`).
- [ ] Task: Conductor - User Manual Verification 'Phase 6: Quality Gate' (Protocol in workflow.md)
