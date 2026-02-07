# Implementation Plan: Frontend Typed Alert Domain Refactor

## Phase 1: Foundation & Schema Definition
Define the core Zod schemas and TypeScript types that will form the backbone of the new domain layer.

- [x] Task: Install Zod dependency
- [x] Task: Create core domain directory structure (`resources/js/features/gta-alerts/domain/alerts/`)
- [x] Task: Implement Zod schemas and types for Fire alerts
- [x] Task: Implement Zod schemas and types for Police alerts
- [x] Task: Implement Zod schemas and types for Transit alerts (TTC)
- [x] Task: Implement Zod schemas and types for GO Transit alerts
- [x] Task: Define the `DomainAlert` Discriminated Union type
- [x] Task: Implement canonical mapper `fromResource(resource: UnifiedAlertResource): DomainAlert | null` (wrap `parse()` with try/catch or use `safeParse()`; log+discard invalid items)
- [x] Task: Conductor - User Manual Verification 'Phase 1: Foundation & Schema Definition' (Protocol in workflow.md)

## Phase 2: Decentralized Mapping & Validation
Implement the source-specific mappers and the centralized validation orchestration.

- [x] Task: Write tests for Fire alert mapper (valid/invalid scenarios)
- [x] Task: Implement Fire alert mapper using Zod validation
- [x] Task: Write tests for Police alert mapper (valid/invalid scenarios)
- [x] Task: Implement Police alert mapper using Zod validation
- [x] Task: Write tests for Transit alert mapper (valid/invalid scenarios)
- [x] Task: Implement Transit alert mapper using Zod validation
- [x] Task: Write tests for GO Transit alert mapper (valid/invalid scenarios)
- [x] Task: Implement GO Transit alert mapper using Zod validation
- [x] Task: Refactor `AlertService` to orchestrate mapping via `fromResource(...)` and enforce "Hard Enforcement" (catch/log/discard invalid items; never throw into UI rendering)
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Decentralized Mapping & Validation' (Protocol in workflow.md)

## Phase 3: Logic Migration
Move business logic (severity, icon selection, etc.) from `AlertService` into domain-specific pure functions.

- [ ] Task: Move Fire-specific logic to Fire domain module
- [ ] Task: Move Police-specific logic to Police domain module
- [ ] Task: Move Transit/GO-specific logic to Transit domain module
- [ ] Task: Update logic to consume the new Discriminated Union types
- [ ] Task: Define derived presentation categories (e.g., hazard/medical) as pure functions or a dedicated `ViewAlert` mapping layer (do not add to `DomainAlert.kind`)
- [ ] Task: Verify unit tests for all migrated logic
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Logic Migration' (Protocol in workflow.md)

## Phase 4: UI Modernization (Components)
Refactor UI components to consume the new domain model and modernize their implementation.

- [ ] Task: Update `AlertCard` to consume `DomainAlert` and handle source-specific rendering
- [ ] Task: Update `FeedView` to handle the new model and update filtering/searching logic
- [ ] Task: Refactor `AlertDetailsView` from Class to Functional Component using React Composition
- [ ] Task: Implement pattern matching (switch on `kind`) in `AlertDetailsView` for detail sections
- [ ] Task: Update `App.tsx` to handle the updated service output
- [ ] Task: Conductor - User Manual Verification 'Phase 4: UI Modernization (Components)' (Protocol in workflow.md)

## Phase 5: Quality & Documentation
Final verification, cleanup, and documentation updates.

- [ ] Task: Execute full test suite (`sail artisan test` and `pnpm test`)
- [ ] Task: Verify test coverage is >90% for all modified frontend modules
- [ ] Task: Run linting and type checking (`sail pnpm run build` equivalent)
- [ ] Task: Update `docs/frontend/types.md` to reflect the new architecture
- [ ] Task: Delete legacy `AlertItem` interface and deprecated mapping code
- [ ] Task: Move track to archive and update Tracks Registry
- [ ] Task: Conductor - User Manual Verification 'Phase 5: Quality & Documentation' (Protocol in workflow.md)
