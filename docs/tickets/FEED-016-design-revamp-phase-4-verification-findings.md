---
ticket_id: FEED-016
title: "[Phase 4] Design Revamp verification failures on localhost:8080"
status: Open
priority: High
assignee: Unassigned
created_at: 2026-03-05
tags: [frontend, qa, playwright, design-revamp]
related_files:
  - conductor/tracks/design_revamp_20260303/plan.md
  - resources/js/features/gta-alerts/components/FeedView.tsx
  - resources/js/features/gta-alerts/components/AlertTableView.tsx
  - resources/js/features/gta-alerts/App.tsx
artifacts:
  - artifacts/playwright/design-revamp-phase4-desktop-home.png
  - artifacts/playwright/design-revamp-phase4-desktop-table-view.png
  - artifacts/playwright/design-revamp-phase4-desktop-details-view.png
  - artifacts/playwright/design-revamp-phase4-desktop-cleared-filter-cards.png
  - artifacts/playwright/design-revamp-phase4-mobile-cleared-view.png
  - artifacts/playwright/design-revamp-phase4-quality-check.log
  - artifacts/playwright/design-revamp-phase4-sail-artisan-test.log
---

## Summary

Phase 4 Playwright verification against `http://localhost:8080/` surfaced multiple blocking issues across UI parity, responsive behavior, and quality gates.

## Findings

### 1) Runtime UI does not match Phase 3 contract

- View toggle renders **Cards/Table** in runtime instead of expected **Feed/Table** labels.
- Table mode does not provide expandable summary-row behavior; clicking a row routes directly to details view.
- Expected `FeedView`/`AlertTableView` interaction contract from the track plan is not observed in the running app.

### 2) Mobile drawer behavior is broken

- At mobile viewport (`390x844`), sidebar/drawer appears open by default and overlays the content.
- `Open menu`/`Close menu` actions do not close the drawer in observed runs.
- Overlay state obscures primary content and contributes to FAB/interaction crowding.

### 3) CSP blocks external font/icon stylesheets

- Browser console reports CSP violations for:
  - `https://fonts.bunny.net/...`
  - `https://fonts.googleapis.com/...` (Public Sans, Lexend, Material Symbols)
- This degrades typography/icon rendering and affects design parity checks.

### 4) Phase 4 quality gates are not fully green

- `pnpm run quality:check` fails at `vitest run` under `CI=true` due Laravel Vite env guard requiring `LARAVEL_BYPASS_ENV_CHECK=1`.
- Backend gate command `./vendor/bin/sail artisan test` did not run because Docker was not running in the verification environment.

### 5) Visual parity and content width are still not aligned

- Frontend color aesthetics in runtime still do not match the expected revamp visual treatment.
- The element with class `relative flex-1 overflow-y-auto p-4 md:p-6 opacity-100 transition-opacity duration-200` is not expanding to the full available width of its parent layout.
- Expected behavior: this content region should span full parent width (with responsive padding only), without additional width constraint artifacts.

## Reproduction Notes

- Verified using Playwright MCP on `http://localhost:8080/`.
- Desktop verification at default viewport and mobile verification at `390x844`.
- Status filter confirmed data presence for both active and cleared states (`/?status=active`, `/?status=cleared`).

## Acceptance Criteria

- [x] Runtime UI at `http://localhost:8080/` matches Phase 3 contract and visual design parity (labels/interaction plus approved color aesthetics).
- [x] Mobile drawer defaults to closed and reliably toggles open/closed.
- [x] CSP/font strategy supports required typography/icon assets (or design/spec updated to match local-only assets).
- [x] `pnpm run quality:check` is CI-safe and passes in the expected Phase 4 environment.
- [x] Backend verification gate is runnable in documented local setup (`sail`/Docker available) with pass/fail evidence recorded.
- [x] Main content container (`relative flex-1 overflow-y-auto p-4 md:p-6 opacity-100 transition-opacity duration-200`) expands to full parent width.

## Notes

- Fixes applied in `resources/js/features/gta-alerts/App.tsx` and `resources/js/features/gta-alerts/components/FeedView.tsx` to remove width constraints and align feed controls with the high-contrast revamp treatment.
- Verification commands passed on 2026-03-05: targeted Vitest scopes (`FeedView.test.tsx`, `App.test.tsx`, `App.url-state.test.tsx`), `./vendor/bin/pint`, `composer lint && pnpm run lint && pnpm run format && pnpm run types`, `pnpm run quality:check`, and `composer test`.
