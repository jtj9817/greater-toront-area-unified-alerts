# Track Specification: Frontend Typed Alert Domain Refactor

## Overview
This track focuses on evolving the frontend alert domain from a loosely typed "kitchen sink" interface (`AlertItem`) to a strictly typed, source-aware domain layer using **Zod** for runtime validation and **Discriminated Unions** for modeling. This will ensure data integrity at the application boundary and provide a more robust foundation for source-specific UI behavior.

## Functional Requirements
- **Runtime Validation:** Implement strict Zod schemas for all alert sources (Fire, Police, Transit, GO Transit).
- **Hard Enforcement (Catch/Log/Discard):** Any incoming alert resource from the API that fails Zod validation must be **caught**, **logged**, and **discarded** (i.e., not rendered and not allowed to crash the UI). Note: `schema.parse()` throws unless wrapped; use `safeParse()` or `parse()` inside a try/catch at the boundary.
- **Discriminated Union Modeling:** Model alerts using a `kind` discriminator (e.g., `{ kind: 'fire', ... } | { kind: 'transit', ... }`).
- **Canonical Mapping Entry Point:** Implement a single entry point `fromResource(resource: UnifiedAlertResource): DomainAlert | null` (or an equivalent Result type) that performs boundary validation and returns `null` for invalid items after logging.
- **Decentralized Mapping:** Create source-specific mappers (e.g., `fire/mapper.ts`) that transform validated resources into typed domain objects.
- **UI Modernization:** 
    - Refactor `AlertDetailsView` from a Class-based component to a Functional Component using React Composition.
    - Update `AlertCard` and `FeedView` to consume the new domain model directly.
- **Service Facade:** Refactor `AlertService` to act as a thin facade that orchestrates the decentralized mappers.

## Non-Functional Requirements
- **Data Integrity:** Validate at the boundary and discard invalid items to ensure the UI never processes malformed data.
- **Testability:** Business logic (severity, icon selection) must be moved into pure functions within domain modules.
- **Maintainability:** Source-specific logic should be isolated to simplify adding new GTA sources in the future.

## Acceptance Criteria
- [ ] All alert sources have corresponding Zod schemas and TypeScript types.
- [ ] A canonical `fromResource(...)` entry point exists and is used at the boundary (service/facade layer).
- [ ] `AlertService` uses `fromResource(...)` + decentralized mappers to process API responses.
- [ ] Malformed alerts are caught, logged, and discarded (not rendered; no UI crash).
- [ ] `AlertDetailsView`, `AlertCard`, and `FeedView` are fully typed and use the new discriminated union.
- [ ] Pest/Vitest tests cover valid and invalid validation scenarios for each source.
- [ ] Test coverage for the refactored modules remains >90%.

## Notes on Presentation Categories
- The existing UI-facing categories like `'hazard'` / `'medical'` are derived presentation concerns. They should not be additional `DomainAlert.kind` variants unless the backend begins emitting them as separate sources. Instead, derive them from domain alerts via pure functions (or introduce a dedicated `ViewAlert` union if needed).

## Out of Scope
- Backend changes to the API structure.
- Implementation of new alert sources (this is a refactor of existing sources).
- Historical archive view refactoring (unless necessary for the core feed).
