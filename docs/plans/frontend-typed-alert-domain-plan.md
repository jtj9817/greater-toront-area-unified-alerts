# Frontend Typed Alert Domain Plan

## Context

The backend already provides a unified alert contract through `UnifiedAlertsQuery`.
In Phase 4 of TTC integration, frontend behavior was improved for transit severity,
icon mapping, and richer descriptions using `meta`.

We discussed whether to introduce source-specific frontend types (especially for
transit) while keeping backend unification intact.

## Question

Should we create a dedicated transit alert type in the frontend now, or defer it?

## Decision

Defer this refactor to a follow-up track.

Rationale:
- Current TTC Phase 4 requirements are complete and validated.
- Introducing a typed per-source domain model is architectural improvement,
  not a blocker for transit integration acceptance criteria.
- Doing this cleanly is better when applied consistently across transit, fire,
  and police instead of transit-only partial specialization.

## Proposed Architecture (Follow-up)

Keep a unified backend boundary and specialize in a frontend domain layer:

1. Input boundary: `UnifiedAlertResource` (API shape)
2. Mapping boundary: parse `meta` into source-specific typed models
3. UI domain: discriminated union of typed alerts
4. Presentation: source-aware render/mapping functions using typed data

Example shape:

```ts
// transport-layer API type
interface UnifiedAlertResource {
  source: 'fire' | 'police' | 'transit';
  meta: Record<string, unknown>;
  // ...
}

// domain-layer union
type DomainAlert = FireAlert | PoliceAlert | TransitAlert;
```

## OOP Analysis

### Pros

- Single Responsibility: each source mapper handles only its own translation.
- Open/Closed: adding new source behavior extends strategy classes/functions
  without repeatedly editing one central conditional block.
- Encapsulation: transit-specific rules live in a transit-focused type/mapper.
- Testability: each mapper can be unit-tested independently.

### Cons

- More classes/modules and mapping code to maintain.
- Potential drift if backend metadata changes and mapper updates lag.
- If only transit is specialized, design consistency degrades.

## FP Analysis

### Pros

- Pure mapping functions (`resource -> domain`) are deterministic and easy to test.
- Discriminated unions provide exhaustive handling for `source`.
- Reduced runtime uncertainty by validating/parsing unknown `meta` once.
- Easier composition of small transform functions for severity/icon/description.

### Cons

- Upfront cost to design parser and domain union.
- Extra boilerplate for parsing and narrowing unknown metadata.
- Requires team discipline to keep transformations pure and centralized.

## Unified Alerts Principle Check

This refactor does **not** violate unified alerts principles if the boundary remains:
- Unified contract from backend
- Specialization after boundary in frontend domain mapping

Unification is preserved at integration points while improving internal type safety.

## Incremental Rollout Plan

1. Add a `domain/alerts` module for typed models and parsers.
2. Create `toDomainAlert(resource: UnifiedAlertResource): DomainAlert`.
3. Migrate transit logic first behind compatibility mapping.
4. Migrate fire and police for symmetry.
5. Replace conditional-heavy service logic with source-specific helpers.
6. Expand tests for parser validity and exhaustive source handling.

## Scope Recommendation

- **Now (current TTC track):** keep existing implementation as-is.
- **Next track:** execute typed-domain refactor across all sources.

This keeps delivery momentum while reducing long-term complexity safely.
