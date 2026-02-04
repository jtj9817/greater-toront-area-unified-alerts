# Implementation Plan: Unified Alerts Query Refinement & Robustness

## Phase 1: Test Refinement (Specification First) [checkpoint: 6aec17c]
- [x] (948eccc) Task: Audit `tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php` against `docs/Unified-Alerts-Query-Test-Refinement.md`.
    - [x] Ensure the suite explicitly covers: empty dataset, DTO integrity, ordering invariants, tie density across page boundaries, and invalid status handling.
    - [x] Audit findings snapshot (as of 2026-02-04):
        - [x] Present: empty dataset baseline test.
        - [x] Present: DTO integrity assertions for representative Fire + Police rows (basic fields).
        - [x] Present: ordering invariant helper for `(timestamp desc, source asc, external_id desc)`.
        - [x] Present: deterministic pagination coverage (page 2) and high tie density across a page boundary (no duplicates, merged IDs match expected).
        - [x] Present: strict invalid status contract (throws) + test.
        - [x] Resolved: ordering invariants assert non-empty `id/source/externalId` per item + `id` uniqueness within a page.
        - [x] Resolved: status filtering asserts `status=active/cleared` => all returned items satisfy `isActive === true/false`.
        - [x] Present: meta decoding edge cases at the unified mapping level (`null`/missing/empty string/invalid JSON => `[]`, no exception leakage).
        - [x] Present: timestamp parsing contract + tests for `timestamp` null/unparseable (fail-fast exception).
        - [x] Present: location edge cases (name null but lat/lng present; lat/lng = 0 remains floats and is not treated as falsey).
        - [ ] Missing: cross-driver execution (actually running provider + unified query tests against MySQL; current tests only assert generated SQL strings).
        - [ ] Missing: performance/index assumptions (at least doc/notes, optionally lightweight assertions).
    - [x] Add missing edge-case tests (as needed):
        - [x] Add per-page uniqueness + non-empty identifier invariants (`id`, `source`, `externalId`) for unified results.
        - [x] Add status invariants: `status=active` => all `isActive === true`, `status=cleared` => all `isActive === false`.
        - [x] Invalid/empty `meta` JSON decodes to an empty array (no exceptions leak).
        - [x] Missing/malformed timestamps: define expected behavior (prefer fail-fast exception) and test it.
        - [x] Provider output invariants (required columns + types) are asserted in at least one place (shared helper or contract test).
    - [ ] Optional: Reduce reliance on global pagination state by passing an explicit page number to `paginate()` (requires a service API adjustment).
- [x] (6aec17c) Task: Conductor - User Manual Verification 'Phase 1: Test Refinement' (Protocol in workflow.md)
    - [x] (ec2da56) Script: `tests/manual/verify_query_refinement_phase_1_test_refinement.php`
    - [x] Log: `storage/logs/manual_tests/query_refinement_phase_1_test_refinement_2026_02_04_205926.log` (also attached to checkpoint commit via git notes)

## Phase 2: Extract Mapping Into a Dedicated Mapper (Unit-Tested)
- [x] (3fe7223) Task: Phase 2 Preflight - Mapper Contract Audit (no implementation).
    - [x] Decide/document timestamp contract (keep fail-fast vs filter-out) and where it is enforced (providers vs mapper vs query).
    - [x] Define unified row schema requirements (required columns, nullability expectations, and type normalization rules).
    - [x] Define `meta` decoding contract (null/empty/invalid JSON => `[]`; arrays pass-through; scalar JSON => `[]`) and align provider tests to it.
    - [x] Define location construction rules (name-only, coords-only, and `0.0` coords must remain floats).
    - [x] Define unit-test matrix for `UnifiedAlertMapper::fromRow()` and which mapping assertions remain in `UnifiedAlertsQueryTest`.
    - [x] Decide how provider unit tests will reuse mapper meta decoding (mapper helper vs shared test utility) to avoid drift.
    - [x] Notes: `conductor/tracks/query_refinement_20260203/phase_2_mapper_contract_audit.md`
- [x] (3fe7223) Task: Implement `UnifiedAlertMapper` (or `UnifiedAlertHydrator`) and migrate mapping logic from `UnifiedAlertsQuery`.
    - [x] Implement `App\Services\Alerts\Mappers\UnifiedAlertMapper` with `fromRow(object $row): UnifiedAlert`.
    - [x] Ensure `decodeMeta()` and location/timestamp normalization rules live in the mapper and are pure/deterministic.
    - [x] Write unit tests covering meta decoding (null/empty/invalid/array), location creation rules, and timestamp parsing behavior.
    - [x] Reduce meta-decoding drift: update provider unit tests to decode via the mapper (or a shared helper used by both), instead of duplicating ad-hoc `json_decode` logic per test file.
    - [x] Coverage gate: `UnifiedAlertMapper` should reach 100% unit test coverage.
        - [x] Note: coverage runner unavailable in this environment (no Xdebug/PCOV). `php artisan test` passes.
- [x] (3fe7223) Task: Conductor - User Manual Verification 'Phase 2: Mapper Extraction' (Protocol in workflow.md)
    - [x] Script: `tests/manual/verify_query_refinement_phase_2_mapper_extraction.php`
    - [x] Log: `storage/logs/manual_tests/query_refinement_phase_2_mapper_extraction_2026_02_04_213705.log`

## Phase 3: Provider Extensibility + Query Refactor (Dependency Inversion)
- [x] (ae995e1) Task: Phase 3 Preflight - Provider Contract + Tagged Injection Audit (no implementation).
    - [x] Audit provider unified schema for required columns and nullability expectations.
    - [x] Audit `external_id` string-casting:
        - [x] Police: already casts `external_id` (`CAST(object_id AS TEXT/CHAR)`).
        - [x] Fire: does not explicitly cast `external_id` (should cast `event_num` to TEXT/CHAR).
        - [x] Transit: placeholder returns no rows; real implementation must cast `external_id` too.
    - [x] Confirm Laravel 12 supports tagged injection via `Illuminate\Container\Attributes\Tag`.
    - [x] Decide tag key: `alerts.select-providers`.
    - [x] Notes: `conductor/tracks/query_refinement_20260203/phase_3_preflight_provider_di_audit.md`
- [x] (498d66b) Task: Refactor Providers for Contract Compliance.
    - [x] Ensure `external_id` is explicitly cast/selected as a string in all provider SQL selects for UNION consistency.
        - [x] Fire: change `event_num as external_id` to explicit CAST (sqlite TEXT, non-sqlite CHAR).
        - [x] Transit (placeholder): no-op today, but enforce casting when real transit provider ships.
    - [x] Optional: introduce an `AlertSource` enum for consistent source keys (deferred to Phase 5; no Phase 3 changes needed).
- [x] (498d66b) Task: Refactor `UnifiedAlertsQuery` for Dependency Inversion (tagged injection).
    - [x] Register and tag providers in `AppServiceProvider::register()` under `alerts.select-providers`.
    - [x] Write tests for tagged provider injection (inject a controlled set of providers, including a fake provider for edge-case rows).
    - [x] Refactor `UnifiedAlertsQuery` to accept `iterable` providers via tagged injection (Open/Closed Principle).
    - [x] Update query logic to use `UnifiedAlertMapper` for DTO creation.
- [x] (949565c) Task: Conductor - User Manual Verification 'Phase 3: Provider Extensibility' (Protocol in workflow.md)
    - [x] Script: `tests/manual/verify_query_refinement_phase_3_provider_extensibility.php`
    - [x] Log: `storage/logs/manual_tests/query_refinement_phase_3_provider_extensibility_2026_02_04_221957.log`

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
