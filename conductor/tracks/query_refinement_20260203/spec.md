# Specification: Unified Alerts Query Refinement & Robustness

## Overview
This track focuses on strengthening the "Unified Alerts" subsystem by implementing the refinements and architectural improvements outlined in `docs/Unified-Alerts-Query-Test-Refinement.md`. The goal is to move from a working prototype to a robust, production-ready implementation with high-fidelity testing, strict total ordering, and an extensible, type-safe architecture.

## Functional Requirements

### 1. Test Refinement (Specification Coverage)
- Ensure `tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php` fully specifies the invariants and edge cases called out in `docs/Unified-Alerts-Query-Test-Refinement.md`, including:
    - empty dataset behavior
    - deterministic total ordering `(timestamp desc, source asc, external_id desc)`
    - deterministic pagination (including high tie density across page boundaries)
    - DTO integrity (at least one representative item per source)
    - explicit status handling contract (strict vs lenient; current preference is strict/fail-fast)
- Add missing tests for edge-case mapping behavior (malformed `meta`, missing timestamps) as needed so the behavior is explicit and protected by tests.
Note: The implementation plan includes a dated "audit findings snapshot" under Phase 1 to translate the doc’s recommendations into concrete deltas for this codebase.

### 2. Extract Mapping Into a Dedicated Mapper (Unit-Tested)
- **Dedicated Mapper Service:** Extract `UnifiedAlertsQuery::rowToDto()` and `decodeMeta()` into a `UnifiedAlertMapper` (or `UnifiedAlertHydrator`) class.
- **Unit Test Coverage:** The mapper should be exhaustively unit-tested (including nullability/type casting/JSON decoding rules) and reach 100% coverage so mapping behavior is stable and refactor-friendly.

### 3. Architectural Refactoring (Robustness & Extensibility)
- **Dependency Inversion for Providers:** Refactor `UnifiedAlertsQuery` to use Laravel's tagged container injection. It should accept an `iterable` of `AlertSelectProvider` objects, allowing new alert sources to be added without modifying the query service (Open/Closed Principle).
- **Unified Select Schema Invariants:** Ensure all providers strictly adhere to the expected column schema (especially string-casting `external_id` for consistent UNION ordering).

### 4. Cross-Driver Validation
- **MySQL Test Integration:** Configure the test suite to support running integration tests against a MySQL database (via Sail/Docker) to validate driver-specific SQL branches in `AlertSelectProvider` implementations that cannot be fully verified by SQLite.

### 5. Type-Safe Service Boundary
- **Type-Safe Contracts:**
    - Introduce an `AlertStatus` Enum (all, active, cleared) to harden the status contract at the service/controller boundary.
    - Introduce an `AlertSource` Enum (fire, police, transit) to make source identifiers consistent and closed-set.
    - Introduce a `UnifiedAlertsCriteria` value object to encapsulate query parameters (status, per-page, explicit page).
    - Introduce an `AlertId` value object to encapsulate the `{source}:{externalId}` convention and reduce duplicated string formatting logic.

## Acceptance Criteria
- [ ] `UnifiedAlertsQuery` uses tagged injection for providers.
- [ ] `UnifiedAlertMapper` exists and has 100% unit test coverage for mapping/parsing logic.
- [ ] `UnifiedAlertsQueryTest` passes with explicit assertions for DTO integrity, invariants, and tie-breaking (including high tie density across page boundaries).
- [ ] A test command or configuration exists to run the suite against MySQL.
- [ ] Overall test coverage for the modified/new files remains >= 90%.
- [ ] `AlertStatus`/`AlertSource`/`UnifiedAlertsCriteria`/`AlertId` exist, are used consistently at the service/controller boundary, and are covered by tests.

## Out of Scope
- Implementing "Real" Transit data (this track focuses on the infrastructure to support it).
- Frontend UI changes (beyond those strictly necessary to adapt to DTO or type changes).
- Keyset-based pagination (sticking with Offset pagination for now).
