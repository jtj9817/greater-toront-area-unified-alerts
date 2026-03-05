---
ticket_id: FEED-017
title: "[Phase 4] Design revamp quality gates failing after Playwright verification rerun"
status: Closed
priority: High
assignee: Unassigned
created_at: 2026-03-05
updated_at: 2026-03-05
closed_at: 2026-03-05
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

Phase 4 browser interaction checks remain stable, and the frontend quality gates that were blocking signoff have been remediated. Formatting, TypeScript, frontend quality checks, and the full Laravel suite all pass in the current local environment.

## Latest Verification Snapshot (2026-03-05)

### Passing gates

- `pnpm run format:check` -> PASS
- `pnpm run lint:check` -> PASS
- `pnpm run types` -> PASS
- `pnpm run test:ci` -> PASS
- `pnpm run quality:check` -> PASS
- `composer test` -> PASS

## Scope Clarification

- Previous note about Sail backend gate being blocked by Docker is no longer current for this environment.
- This ticket remained narrowly scoped to frontend quality gate remediation (format + types) and rerun confirmation.

## Acceptance Criteria

- [x] `pnpm run format:check` passes with no formatting drift.
- [x] `pnpm run types` passes with no unresolved module errors.
- [x] `pnpm run quality:check` completes successfully end-to-end.
- [x] Phase 4 plan item can be marked fully complete with updated evidence references.

## Resolution Notes

- `resources/js/pages/settings/password.tsx` now uses the generated `@/routes/user-password` `update.form()` helper instead of the unresolved generated action import, which clears the `TS2307` type failure without changing the form payload shape.
- `resources/js/features/gta-alerts/App.test.tsx` and `resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx` now mock `SceneIntelTimeline`, removing unrelated async state updates and eliminating the noisy `act(...)` warnings from the parent tests.
- Prettier drift under `resources/` was normalized with `pnpm run format`.
- Verification on 2026-03-05:
  - Narrow checks: `pnpm run types`, `LARAVEL_BYPASS_ENV_CHECK=1 pnpm exec vitest run resources/js/features/gta-alerts/App.test.tsx resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx`, `pnpm exec prettier --check ...`
  - Full gates: `composer test`, `./vendor/bin/pint`, `composer lint`, `pnpm run lint`, `pnpm run format`, `pnpm run types`, `pnpm run quality:check`
