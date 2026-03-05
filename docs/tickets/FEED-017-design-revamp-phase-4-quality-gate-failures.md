---
ticket_id: FEED-017
title: "[Phase 4] Design revamp quality gates failing after Playwright verification rerun"
status: Open
priority: High
assignee: Unassigned
created_at: 2026-03-05
tags: [frontend, qa, quality-gates, design-revamp]
related_files:
  - conductor/tracks/design_revamp_20260303/plan.md
  - tests/e2e/design-revamp-phase-4.spec.ts
artifacts:
  - artifacts/playwright/design-revamp-phase4-20260305-gates-detailed.log
  - artifacts/playwright/design-revamp-phase4-20260305-quality-check.log
  - artifacts/playwright/design-revamp-phase4-20260305-sail-artisan-test.log
  - artifacts/playwright/design-revamp-phase4-20260305-desktop-feed-1440.png
  - artifacts/playwright/design-revamp-phase4-20260305-desktop-table.png
  - artifacts/playwright/design-revamp-phase4-20260305-mobile-drawer-closed.png
---

## Summary

Phase 4 Playwright verification rerun on `http://localhost:8080/` passed core UI interaction checks, but quality gates remain blocked by formatting drift and an unavailable Docker runtime for the Sail backend gate.

## Findings

### 1) `pnpm run quality:check` fails at formatting gate

- Command: `pnpm run quality:check`
- Failure point: `pnpm run format:check`
- Result: Prettier reports style drift in 22 files under `resources/` and exits non-zero.
- Evidence: `artifacts/playwright/design-revamp-phase4-20260305-quality-check.log`

### 2) Backend regression gate could not execute in Sail runtime

- Command: `./vendor/bin/sail artisan test`
- Result: command exits immediately with `Docker is not running.`
- Impact: Phase 4 backend regression confidence is incomplete in the documented Sail path.
- Evidence: `artifacts/playwright/design-revamp-phase4-20260305-sail-artisan-test.log`

## Verification Notes

- Frontend gates executed individually:
  - `pnpm run lint:check` ✅
  - `pnpm run types` ✅
  - `pnpm run test` ✅
- Core Playwright UI contract checks passed (desktop/mobile shell, client-side Feed/Table toggle, table expand/collapse contract, details CTA path, cleared-card muted treatment).
- Detailed evidence: `artifacts/playwright/design-revamp-phase4-20260305-gates-detailed.log` and screenshot set `artifacts/playwright/design-revamp-phase4-20260305-*.png`.

## Acceptance Criteria

- [ ] `pnpm run format:check` passes with no formatting drift.
- [ ] `pnpm run quality:check` completes successfully end-to-end.
- [ ] `./vendor/bin/sail artisan test` executes in a running Docker/Sail environment with pass/fail evidence recorded.

