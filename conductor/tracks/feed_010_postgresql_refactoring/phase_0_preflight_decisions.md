# Phase 0 Preflight: Driver Matrix + Typed Transport Invariants

Date: 2026-02-26

This document records Phase 0 decisions for FEED-010 before implementation changes.

## 1) Supported Driver Matrix (Decision)

- `sqlite`: treat as test/dev fallback, no provider-level native FTS; rely on compatibility fallback behavior.
- `mysql`: treat as first-class local/dev SQL branch with provider-level FTS plus substring `LIKE` fallback.
- `pgsql`: treat as production-target SQL branch with provider-level FTS plus substring `ILIKE`/`LIKE` fallback.
- `mariadb`: treat as MySQL-family for FEED-010 branching and behavior expectations.

Rationale:
- `config/database.php` defines both `mysql` and `mariadb` connections.
- Existing provider logic and migration strategy in this track are already MySQL-family oriented for non-sqlite SQL, and FEED-010 explicitly adds a separate pgsql branch.

## 2) Typed Transport Invariants (Decision)

These invariants are locked for all supported drivers at the provider output boundary:

- `meta.intel_summary` must always be a JSON array at transport time; it must never be `null`.
- Default value for missing/no updates is `[]`.
- `meta.intel_last_updated` must be either:
  - `null` (or omitted), or
  - an ISO-8601 datetime string with timezone offset (for example `Z` or `+00:00`).

Rationale:
- Frontend contract in `resources/js/features/gta-alerts/domain/alerts/fire/schema.ts` requires:
  - `intel_summary: z.array(...).optional().default([])`
  - `intel_last_updated: z.string().datetime({ offset: true }).nullable().optional()`

## 3) Implementation Guardrails Entering Phase 1+

- Provider pgsql branches must not fall through to MySQL-only SQL helpers.
- Scene Intel JSON building must preserve `intel_summary` as array and ISO-offset timestamp formatting for `intel_last_updated`.
- Cross-driver verification must include explicit assertions for these two meta invariants.
