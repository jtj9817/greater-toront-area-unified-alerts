---
ticket_id: FEED-017
title: "[Phase 4] Design revamp quality gates failing after Playwright verification rerun"
status: Open
priority: High
assignee: Unassigned
created_at: 2026-03-05
updated_at: 2026-03-05
tags: [frontend, qa, quality-gates, design-revamp]
related_files:
  - conductor/tracks/design_revamp_20260303/plan.md
  - tests/e2e/design-revamp-phase-4.spec.ts
  - docs/runbooks/design-revamp-phase-4-verification.md
artifacts:
  - artifacts/playwright/design-revamp-phase4-20260305-gates-detailed.log
  - artifacts/playwright/design-revamp-phase4-20260305-quality-check.log
  - artifacts/playwright/design-revamp-phase4-20260305-sail-artisan-test.log
  - artifacts/playwright/design-revamp-phase4-20260305-desktop-feed-1440.png
  - artifacts/playwright/design-revamp-phase4-20260305-desktop-table.png
  - artifacts/playwright/design-revamp-phase4-20260305-mobile-drawer-closed.png
---

## Summary

Phase 4 browser interaction checks are stable, and backend Sail regression tests are now passing in the current local environment. Remaining blockers are frontend format/type gates that prevent Phase 4 and track archival signoff.

## Latest Verification Snapshot (2026-03-05)

### Passing gates

- `pnpm run lint:check` -> PASS
- `pnpm run test` -> PASS
- `./vendor/bin/sail artisan test` -> PASS

### Failing gates

1) `pnpm run format:check` fails
- Result: Prettier reports style drift in 22 files under `resources/`.
- Impact: `pnpm run quality:check` fails immediately at the first step.

2) `pnpm run types` fails
- Result: `TS2307` in `resources/js/pages/settings/password.tsx`.
- Error: Cannot find module `@/actions/App/Http/Controllers/Settings/PasswordController` or corresponding type declarations.
- Impact: Type gate remains red even if formatting is fixed.

3) `pnpm run quality:check` fails
- Result: aggregate quality script exits non-zero because `format:check` fails.

## Scope Clarification

- Previous note about Sail backend gate being blocked by Docker is no longer current for this environment.
- This ticket is now narrowly scoped to frontend quality gate remediation (format + types) and rerun confirmation.

## Acceptance Criteria

- [ ] `pnpm run format:check` passes with no formatting drift.
- [ ] `pnpm run types` passes with no unresolved module errors.
- [ ] `pnpm run quality:check` completes successfully end-to-end.
- [ ] Phase 4 plan item can be marked fully complete with updated evidence references.
