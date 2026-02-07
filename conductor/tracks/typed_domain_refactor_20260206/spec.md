# Track Specification: Frontend Typed Alert Domain Refactor

## Overview
This track focuses on evolving the frontend alert domain from a loosely typed "kitchen sink" interface (`AlertItem`) to a strictly typed, source-aware domain layer using **Zod** for runtime validation and **Discriminated Unions** for modeling. This will ensure data integrity at the application boundary and provide a more robust foundation for source-specific UI behavior.

## Functional Requirements
- **Runtime Validation:** Implement strict Zod schemas for all alert sources (Fire, Police, Transit, GO Transit). 
- **Hard Enforcement:** Any incoming alert from the API that fails to validate against its respective schema must be discarded to prevent UI inconsistencies.
- **Discriminated Union Modeling:** Model alerts using a `kind` discriminator (e.g., `{ kind: 'fire', ... } | { kind: 'transit', ... }`).
- **Decentralized Mapping:** Create source-specific mappers (e.g., `fire/mapper.ts`) that use Zod to transform raw API resources into typed domain objects.
- **UI Modernization:** 
    - Refactor `AlertDetailsView` from a Class-based component to a Functional Component using React Composition.
    - Update `AlertCard` and `FeedView` to consume the new domain model directly.
- **Service Facade:** Refactor `AlertService` to act as a thin facade that orchestrates the decentralized mappers.

## Non-Functional Requirements
- **Data Integrity:** "Fail fast" at the boundary to ensure the UI never processes malformed data.
- **Testability:** Business logic (severity, icon selection) must be moved into pure functions within domain modules.
- **Maintainability:** Source-specific logic should be isolated to simplify adding new GTA sources in the future.

## Acceptance Criteria
- [ ] All alert sources have corresponding Zod schemas and TypeScript types.
- [ ] `AlertService` uses decentralized mappers to process API responses.
- [ ] Malformed alerts are logged and discarded, not rendered.
- [ ] `AlertDetailsView`, `AlertCard`, and `FeedView` are fully typed and use the new discriminated union.
- [ ] Pest/Vitest tests cover valid and invalid validation scenarios for each source.
- [ ] Test coverage for the refactored modules remains >90%.

## Out of Scope
- Backend changes to the API structure.
- Implementation of new alert sources (this is a refactor of existing sources).
- Historical archive view refactoring (unless necessary for the core feed).
