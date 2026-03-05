# Implementation Plan: UI Design Revamp (Prototype Two)

## Phase 1: Foundation & Theme Setup
- [x] Task: Update Theme Tokens, Fonts, and Utilities (GTA Alerts Scoped) [2615efb]
    - [x] Sub-task: Load **Public Sans** in `resources/views/app.blade.php` (Material Symbols is already loaded there).
    - [x] Sub-task: Extend `resources/css/app.css` `@theme` with the **prototype tokens** needed by the new UI (e.g., `--color-warning`, `--color-critical`, `--color-panel-light`, `--color-brand-dark`).
    - [x] Sub-task: Override theme tokens under `.gta-alerts-theme { ... }` so the revamp does **not** unintentionally restyle non-GTA pages (e.g., set `--primary`, `--primary-foreground`, and any `--color-*` overrides used by `bg-background-dark`, etc.).
    - [x] Sub-task: Migrate prototype utilities into `resources/css/app.css` (from `prototype-two-design/index.css`):
        - [x] `.brutalist-border`, `.panel-shadow`, `.custom-scrollbar`/`.scrollbar-hide`
        - [x] Table helpers: `.incident-table th/td`, `.expandable-row`, `.active-row`
- [x] Task: Conductor - User Manual Verification 'Phase 1: Foundation & Theme Setup' [04ecd01] (Protocol in workflow.md; verified 2026-03-04, script: `tests/manual/verify_design_revamp_phase_1_foundation_theme_setup.php`, log: `storage/logs/manual_tests/design_revamp_phase_1_foundation_theme_setup_2026_03_04_230453.log`)

## Phase 2: Global Layout Implementation
- [x] Task: Implement Prototype Sidebar (GTA Alerts) [e55cd96]
    - [x] Sub-task: Update `resources/js/features/gta-alerts/components/Sidebar.tsx` to match `prototype-two-design/App.tsx` (black background, hard borders, primary active state, Material Symbols icons).
    - [x] Sub-task: Decide how to reconcile existing behaviors not shown in the prototype (mobile drawer + desktop collapse). Keep functionality unless explicitly dropped.
- [x] Task: Implement Prototype Header (GTA Alerts) [e55cd96]
    - [x] Sub-task: Update the header in `resources/js/features/gta-alerts/App.tsx` (or extract to `resources/js/features/gta-alerts/components/Header.tsx`) to match the prototype (search bar styling + notification/person icon affordances).
    - [x] Sub-task: Preserve existing behavior: debounced search + navigation to Inbox/Settings.
- [x] Task: Implement Prototype Footer (GTA Alerts) [e55cd96]
    - [x] Sub-task: Add a footer component (new `resources/js/features/gta-alerts/components/Footer.tsx`) matching the prototype’s environment stats + links.
    - [x] Sub-task: Ensure this coexists with the existing mobile-only `BottomNav` (`resources/js/features/gta-alerts/components/BottomNav.tsx`).
- [x] Task: Update GTA Alerts Layout Wrapper [e55cd96]
    - [x] Sub-task: Update the root wrapper in `resources/js/features/gta-alerts/App.tsx` to match the prototype layout classes (`relative flex h-screen w-full overflow-hidden bg-background-dark text-white font-sans`), while keeping `.gta-alerts-theme` as the scoping root.
- [x] Task: Implement Prototype Floating Action Button (Refresh) [e55cd96]
    - [x] Sub-task: Add a floating refresh button (prototype bottom-right) that triggers a safe feed refresh without breaking scroll/state (likely via Inertia reload for the feed view).
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Global Layout Implementation' (Protocol in workflow.md)

## Phase 3: Alert Feed & Table Views
- [x] Task: Align View Toggle With Prototype (Feed/Table) [7c578d7]
    - [x] Sub-task: Update `resources/js/features/gta-alerts/components/FeedView.tsx` view toggle to match prototype labeling/styling ("Feed" vs "Table") and keep it client-side.
    - [x] Sub-task: Update affected tests (`resources/js/features/gta-alerts/components/FeedView.test.tsx`) if UI labels change.
- [x] Task: Implement Prototype-Style Alert Table (Expandable Rows) [7c578d7]
    - [x] Sub-task: Update `resources/js/features/gta-alerts/components/AlertTableView.tsx` to use the prototype table styling (`incident-table`) and implement expandable summary rows (state: expanded row id).
    - [x] Sub-task: Preserve a clear interaction contract:
        - [x] Clicking the expand affordance expands/collapses.
        - [x] Selecting an incident still routes to/opens `AlertDetailsView` (if retained) or is replaced by the in-table summary behavior.
- [x] Task: Implement Prototype-Style Feed Cards [7c578d7]
    - [x] Sub-task: Update `resources/js/features/gta-alerts/components/AlertCard.tsx` (or introduce a new card component and swap usage in `FeedView.tsx`) to match prototype brutalist styling.
    - [x] Sub-task: Ensure visual distinction for different alert statuses (Active vs Cleared/Grayscale) without losing readability.
- [x] Task: Conductor - User Manual Verification 'Phase 3: Alert Feed & Table Views' [a1c835a] (Protocol in workflow.md; verified 2026-03-05, script: `tests/manual/verify_design_revamp_phase_3_alert_feed_table_views.php`, log: `storage/logs/manual_tests/design_revamp_phase_3_alert_feed_table_views_2026_03_05_005030.log`)

## Phase 4: Testing & Verification (Playwright + Quality Gates)
- [x] Task: Prepare Local E2E Verification Environment (`http://localhost:8080/`) [2b4e9d4]
    - [x] Sub-task: Start and verify the local app endpoint at `http://localhost:8080/` (Sail-backed or equivalent local dev runtime), and confirm the GTA Alerts page is reachable before browser automation begins.
    - [x] Sub-task: Ensure test data is present to exercise both Feed and Table states (at minimum one active alert and one cleared alert visible in the UI).
    - [x] Sub-task: Record runtime assumptions for verification (browser engine, viewport presets, auth state, and whether verification runs through Playwright MCP or standalone Playwright).
- [x] Task: Implement Browser E2E Verification (Playwright MCP or Playwright CLI) [983f815]
    - [x] Sub-task: Add/extend E2E specs for the revamp flow (e.g., `tests/e2e/design-revamp-phase-4.spec.ts`) and target `http://localhost:8080/`.
    - [x] Sub-task: Validate desktop shell parity (sidebar, header search affordance, footer links/stats, and refresh FAB visibility/positioning).
    - [x] Sub-task: Validate Feed/Table toggle contract:
        - [x] Toggle remains client-side (`Feed` ↔ `Table`) with no URL navigation side effect.
        - [x] Toggle state updates visible content correctly (cards in Feed mode, table in Table mode).
    - [x] Sub-task: Validate table interaction contract:
        - [x] Expand affordance opens/closes summary row without triggering details navigation.
        - [x] Row selection and/or summary CTA still opens alert details (`AlertDetailsView`) as expected.
    - [x] Sub-task: Validate feed card state parity:
        - [x] Active alerts retain high-visibility styling.
        - [x] Cleared alerts render muted/grayscale treatment while preserving legibility.
    - [x] Sub-task: Validate responsive behavior at minimum desktop and mobile breakpoints (mobile drawer, bottom nav coexistence, and FAB non-overlap with critical actions).
    - [x] Sub-task: Capture verification artifacts (screenshots and, if enabled, Playwright trace/video) for key states and attach artifact paths in phase notes.
- [x] Task: Run Code Quality and Regression Gates (verified 2026-03-05; see `docs/tickets/FEED-017-design-revamp-phase-4-quality-gate-failures.md`)
    - [x] Sub-task: Run `pnpm run format:check`, `pnpm run lint:check`, `pnpm run types`, and `pnpm run test` (or `pnpm run quality:check`).
    - [x] Sub-task: Run backend suite when shared assets/layout integration might affect server-rendered behavior: `./vendor/bin/sail artisan test`.
    - [x] Sub-task: If any gate fails, document failure mode, fix, and rerun evidence in the phase verification log. (Tracked in `docs/tickets/FEED-017-design-revamp-phase-4-quality-gate-failures.md`; failure was remediated and rerun evidence on 2026-03-05 includes `pnpm run format:check` pass, `pnpm run types` pass, `pnpm run quality:check` pass, and `composer test` pass.)
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Testing & Verification' (Protocol in workflow.md; include Playwright MCP/Playwright execution details and artifact references; attempted 2026-03-05 via Playwright MCP with artifacts `artifacts/playwright/design-revamp-phase4-20260305-*`; runtime assumptions recorded in `tests/e2e/design-revamp-phase-4.spec.ts`; latest findings tracked in `docs/tickets/FEED-017-design-revamp-phase-4-quality-gate-failures.md`; historical findings in `docs/tickets/FEED-016-design-revamp-phase-4-verification-findings.md`; backend rerun on 2026-03-05 confirms `./vendor/bin/sail artisan test` now passes.)

## Phase 5: Final Comprehensive Documentation & Track Closeout
- [x] Task: Update Track Artifacts (Plan/Spec/Audit) With Final Implementation State
    - [x] Sub-task: Update this `plan.md` with completed task checkboxes and commit SHAs for Phase 3 and Phase 4 deliverables.
    - [x] Sub-task: Update `conductor/tracks/design_revamp_20260303/spec.md` if implemented behavior deviates from original wording (explicitly note accepted deltas or confirm no deltas).
    - [x] Sub-task: Update or append the phase audit document (e.g., `20260304_design_revamp_20260303_audit.md`) with Phase 3 and Phase 4 commit/test evidence.
- [x] Task: Update Project-Level Documentation
    - [x] Sub-task: Document revamp behavior and user-facing UI changes in appropriate docs (`README.md`, `CLAUDE.md`, and relevant `docs/` pages).
    - [x] Sub-task: Document verification workflow details: local URL (`http://localhost:8080/`), Playwright MCP/Playwright command strategy, and troubleshooting notes (including CI/Vite environment caveats if applicable).
    - [x] Sub-task: Document any unresolved technical debt discovered during Phase 4 validation as explicit tickets linked from track notes. (Linked: `docs/tickets/FEED-015-footer-weather-stats-hardcoded-placeholder.md`, `docs/tickets/FEED-017-design-revamp-phase-4-quality-gate-failures.md`)
- [ ] Task: Final Verification Report & Registry Maintenance
    - [x] Sub-task: Produce a concise phase closeout report summarizing commands run, pass/fail status, and artifact/log paths. (See `conductor/tracks/design_revamp_20260303/phase_5_closeout_report_20260305.md`)
    - [ ] Sub-task: Update `conductor/tracks.md` registry status and move the track to archive when all gates and documentation requirements are complete. (Blocked by pending Phase 2/Phase 4/Phase 5 manual verification tasks.)
    - [x] Sub-task: Ensure final checkpoint/notes references include the Phase 4 testing evidence and Phase 5 documentation evidence. (See `20260304_design_revamp_20260303_audit.md`, `docs/tickets/FEED-016-design-revamp-phase-4-verification-findings.md`, `docs/tickets/FEED-017-design-revamp-phase-4-quality-gate-failures.md`, `conductor/tracks/design_revamp_20260303/phase_5_closeout_report_20260305.md`)
- [ ] Task: Conductor - User Manual Verification 'Phase 5: Final Comprehensive Documentation & Track Closeout' (Protocol in workflow.md)
