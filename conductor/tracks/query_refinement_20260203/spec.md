# Specification: Unified Alerts Query Refinement & Robustness

## Overview
This track focuses on strengthening the "Unified Alerts" subsystem by implementing the refinements and architectural improvements outlined in `docs/Unified-Alerts-Query-Test-Refinement.md`. The goal is to move from a working prototype to a robust, production-ready implementation with high-fidelity testing, strict total ordering, and an extensible, type-safe architecture.

## Functional Requirements

### 1. Architectural Refactoring (Robustness & Extensibility)
- **Dependency Inversion for Providers:** Refactor `UnifiedAlertsQuery` to use Laravel's tagged container injection. It should accept an `iterable` of `AlertSelectProvider` objects, allowing new alert sources to be added without modifying the query service (Open/Closed Principle).
- **Dedicated Mapper Service:** Extract `rowToDto` and `decodeMeta` logic into a `UnifiedAlertMapper` class. This enables exhaustive unit testing of mapping logic, including edge cases for nullability, type casting, and JSON decoding.
- **Type-Safe Contracts:** 
    - Introduce `AlertStatus` and `AlertSource` Enums to replace "stringly-typed" status and source identifiers.
    - Introduce a `UnifiedAlertsCriteria` value object to encapsulate query parameters (status, per-page, page), making the `paginate()` signature cleaner and more extensible.
- **Unified Select Schema Invariants:** Ensure all providers strictly adhere to the expected column schema (especially string-casting `external_id` for consistent UNION ordering).

### 2. Enhanced Testing Suite (Reliability & Specification)
- **DTO Integrity:** Expand `UnifiedAlertsQueryTest` to validate that all DTO fields (source, title, location, meta, timestamps) are correctly populated for both Fire and Police sources.
- **Edge Case Validation:** Add targeted tests for:
    - Null/Missing locations (Fire/Police variations).
    - Invalid or empty `meta` JSON.
    - Missing or malformed timestamps.
- **Total Order Invariants:** Implement invariant-based assertions to verify the total ordering tuple `(timestamp desc, source asc, external_id desc)` and global ID uniqueness across paginated results.
- **Boundary Conditions:**
    - Test "Empty Dataset" behavior (zero results across all providers).
    - Test "High Tie Density" scenarios where many alerts share the same timestamp across page boundaries to ensure zero pagination drift.

### 3. Cross-Driver Validation
- **MySQL Test Integration:** Configure the test suite to support running integration tests against a MySQL database (via Sail/Docker) to validate driver-specific SQL branches in `AlertSelectProvider` implementations that cannot be fully verified by SQLite.

## Acceptance Criteria
- [ ] `UnifiedAlertsQuery` uses tagged injection for providers.
- [ ] `UnifiedAlertMapper` exists and has 100% unit test coverage for mapping/parsing logic.
- [ ] `AlertStatus` and `AlertSource` Enums are used throughout the unified alerts subsystem.
- [ ] `UnifiedAlertsQueryTest` passes with expanded assertions for DTO integrity, invariants, and tie-breaking.
- [ ] A test command or configuration exists to run the suite against MySQL.
- [ ] Overall test coverage for the modified/new files remains >= 90%.

## Out of Scope
- Implementing "Real" Transit data (this track focuses on the infrastructure to support it).
- Frontend UI changes (beyond those strictly necessary to adapt to DTO or type changes).
- Keyset-based pagination (sticking with Offset pagination for now).
