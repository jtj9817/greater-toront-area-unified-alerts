# Implementation Plan: Unified Alerts Query Refinement & Robustness

## Phase 1: Type-Safe Foundations & Mapping [checkpoint: ]
- [ ] Task: Implement Core Enums and Criteria DTO.
    - [ ] Create `App\Enums\AlertStatus` (all, active, cleared).
    - [ ] Create `App\Enums\AlertSource` (fire, police, transit).
    - [ ] Create `App\Services\Alerts\DTOs\UnifiedAlertsCriteria` to encapsulate pagination and filtering parameters.
- [ ] Task: Implement UnifiedAlertMapper.
    - [ ] Write unit tests for `UnifiedAlertMapper` covering fire/police mapping, meta decoding (null/invalid/array), location formatting rules, and timestamp parsing.
    - [ ] Implement `App\Services\Alerts\Mappers\UnifiedAlertMapper` and migrate logic from `UnifiedAlertsQuery`.
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Foundations' (Protocol in workflow.md)

## Phase 2: Architectural Refactoring
- [ ] Task: Refactor Providers for Contract Compliance.
    - [ ] Update `AlertSelectProvider` interface and implementations to use `AlertSource` enum.
    - [ ] Ensure `external_id` is explicitly cast to string in all provider SQL selects for UNION consistency.
- [ ] Task: Refactor UnifiedAlertsQuery for Dependency Inversion.
    - [ ] Write tests for tagged provider injection and criteria-based pagination.
    - [ ] Refactor `UnifiedAlertsQuery` to accept `iterable` providers via tagged injection.
    - [ ] Update query logic to use `UnifiedAlertsCriteria` and `UnifiedAlertMapper`.
- [ ] Task: Update Controller and Frontend Integration.
    - [ ] Update `GtaAlertsController` to consume `AlertStatus` and use the new `Criteria` object.
    - [ ] Update `resources/js/features/gta-alerts/types.ts` if any transport changes occurred during Enum/DTO refactoring.
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Architectural Refactoring' (Protocol in workflow.md)

## Phase 3: Enhanced Feature Testing
- [ ] Task: Expand UnifiedAlertsQueryTest Suite.
    - [ ] Add DTO integrity assertions for Fire and Police sources.
    - [ ] Add tests for edge cases: Empty datasets, null/missing locations, and malformed meta JSON.
    - [ ] Add "High Tie Density" pagination tests ensuring no drift across page boundaries.
    - [ ] Implement and apply a reusable invariant helper to verify total order `(timestamp desc, source asc, external_id desc)`.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Enhanced Testing' (Protocol in workflow.md)

## Phase 4: Cross-Driver Verification
- [ ] Task: MySQL Testing Environment Setup.
    - [ ] Configure a test environment/command to run PHPUnit/Pest against a MySQL instance (via Sail).
- [ ] Task: Validate SQL Branches against MySQL.
    - [ ] Execute provider and unified query tests against MySQL and fix any driver-specific regressions (e.g., `CONCAT` vs `||`).
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Cross-Driver Verification' (Protocol in workflow.md)

## Phase 5: Quality Gate & Finalization
- [ ] Task: Final Coverage & Audit.
    - [ ] Verify >=90% coverage on all new/modified files (`AlertStatus`, `AlertSource`, `UnifiedAlertMapper`, `UnifiedAlertsQuery`).
    - [ ] Run Laravel Pint for style compliance.
    - [ ] Perform security audits (`composer audit`, `pnpm audit`).
- [ ] Task: Conductor - User Manual Verification 'Phase 5: Quality Gate' (Protocol in workflow.md)
