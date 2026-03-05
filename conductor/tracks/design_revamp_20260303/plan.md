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
- [ ] Task: Align View Toggle With Prototype (Feed/Table)
    - [ ] Sub-task: Update `resources/js/features/gta-alerts/components/FeedView.tsx` view toggle to match prototype labeling/styling ("Feed" vs "Table") and keep it client-side.
    - [ ] Sub-task: Update affected tests (`resources/js/features/gta-alerts/components/FeedView.test.tsx`) if UI labels change.
- [ ] Task: Implement Prototype-Style Alert Table (Expandable Rows)
    - [ ] Sub-task: Update `resources/js/features/gta-alerts/components/AlertTableView.tsx` to use the prototype table styling (`incident-table`) and implement expandable summary rows (state: expanded row id).
    - [ ] Sub-task: Preserve a clear interaction contract:
        - [ ] Clicking the expand affordance expands/collapses.
        - [ ] Selecting an incident still routes to/opens `AlertDetailsView` (if retained) or is replaced by the in-table summary behavior.
- [ ] Task: Implement Prototype-Style Feed Cards
    - [ ] Sub-task: Update `resources/js/features/gta-alerts/components/AlertCard.tsx` (or introduce a new card component and swap usage in `FeedView.tsx`) to match prototype brutalist styling.
    - [ ] Sub-task: Ensure visual distinction for different alert statuses (Active vs Cleared/Grayscale) without losing readability.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Alert Feed & Table Views' (Protocol in workflow.md)

## Phase 4: Testing & Verification (Playwright + Quality Gates)
- [ ] Task: Prepare Local E2E Verification Environment (`http://localhost:8080/`)
    - [ ] Sub-task: Start and verify the local app endpoint at `http://localhost:8080/` (Sail-backed or equivalent local dev runtime), and confirm the GTA Alerts page is reachable before browser automation begins.
    - [ ] Sub-task: Ensure test data is present to exercise both Feed and Table states (at minimum one active alert and one cleared alert visible in the UI).
    - [ ] Sub-task: Record runtime assumptions for verification (browser engine, viewport presets, auth state, and whether verification runs through Playwright MCP or standalone Playwright).
- [ ] Task: Implement Browser E2E Verification (Playwright MCP or Playwright CLI)
    - [ ] Sub-task: Add/extend E2E specs for the revamp flow (e.g., `tests/e2e/design-revamp-phase-4.spec.ts`) and target `http://localhost:8080/`.
    - [ ] Sub-task: Validate desktop shell parity (sidebar, header search affordance, footer links/stats, and refresh FAB visibility/positioning).
    - [ ] Sub-task: Validate Feed/Table toggle contract:
        - [ ] Toggle remains client-side (`Feed` ↔ `Table`) with no URL navigation side effect.
        - [ ] Toggle state updates visible content correctly (cards in Feed mode, table in Table mode).
    - [ ] Sub-task: Validate table interaction contract:
        - [ ] Expand affordance opens/closes summary row without triggering details navigation.
        - [ ] Row selection and/or summary CTA still opens alert details (`AlertDetailsView`) as expected.
    - [ ] Sub-task: Validate feed card state parity:
        - [ ] Active alerts retain high-visibility styling.
        - [ ] Cleared alerts render muted/grayscale treatment while preserving legibility.
    - [ ] Sub-task: Validate responsive behavior at minimum desktop and mobile breakpoints (mobile drawer, bottom nav coexistence, and FAB non-overlap with critical actions).
    - [ ] Sub-task: Capture verification artifacts (screenshots and, if enabled, Playwright trace/video) for key states and attach artifact paths in phase notes.
- [ ] Task: Run Code Quality and Regression Gates
    - [ ] Sub-task: Run `pnpm run format:check`, `pnpm run lint:check`, `pnpm run types`, and `pnpm run test` (or `pnpm run quality:check`).
    - [ ] Sub-task: Run backend suite when shared assets/layout integration might affect server-rendered behavior: `./vendor/bin/sail artisan test`.
    - [ ] Sub-task: If any gate fails, document failure mode, fix, and rerun evidence in the phase verification log.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Testing & Verification' (Protocol in workflow.md; include Playwright MCP/Playwright execution details and artifact references)

## Phase 5: Final Comprehensive Documentation & Track Closeout
- [ ] Task: Update Track Artifacts (Plan/Spec/Audit) With Final Implementation State
    - [ ] Sub-task: Update this `plan.md` with completed task checkboxes and commit SHAs for Phase 3 and Phase 4 deliverables.
    - [ ] Sub-task: Update `conductor/tracks/design_revamp_20260303/spec.md` if implemented behavior deviates from original wording (explicitly note accepted deltas or confirm no deltas).
    - [ ] Sub-task: Update or append the phase audit document (e.g., `20260304_design_revamp_20260303_audit.md`) with Phase 3 and Phase 4 commit/test evidence.
- [ ] Task: Update Project-Level Documentation
    - [ ] Sub-task: Document revamp behavior and user-facing UI changes in appropriate docs (`README.md`, `CLAUDE.md`, and relevant `docs/` pages).
    - [ ] Sub-task: Document verification workflow details: local URL (`http://localhost:8080/`), Playwright MCP/Playwright command strategy, and troubleshooting notes (including CI/Vite environment caveats if applicable).
    - [ ] Sub-task: Document any unresolved technical debt discovered during Phase 4 validation as explicit tickets linked from track notes.
- [ ] Task: Final Verification Report & Registry Maintenance
    - [ ] Sub-task: Produce a concise phase closeout report summarizing commands run, pass/fail status, and artifact/log paths.
    - [ ] Sub-task: Update `conductor/tracks.md` registry status and move the track to archive when all gates and documentation requirements are complete.
    - [ ] Sub-task: Ensure final checkpoint/notes references include the Phase 4 testing evidence and Phase 5 documentation evidence.
- [ ] Task: Conductor - User Manual Verification 'Phase 5: Final Comprehensive Documentation & Track Closeout' (Protocol in workflow.md)
