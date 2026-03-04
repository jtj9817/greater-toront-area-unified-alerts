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
- [ ] Task: Implement Prototype Sidebar (GTA Alerts)
    - [ ] Sub-task: Update `resources/js/features/gta-alerts/components/Sidebar.tsx` to match `prototype-two-design/App.tsx` (black background, hard borders, primary active state, Material Symbols icons).
    - [ ] Sub-task: Decide how to reconcile existing behaviors not shown in the prototype (mobile drawer + desktop collapse). Keep functionality unless explicitly dropped.
- [ ] Task: Implement Prototype Header (GTA Alerts)
    - [ ] Sub-task: Update the header in `resources/js/features/gta-alerts/App.tsx` (or extract to `resources/js/features/gta-alerts/components/Header.tsx`) to match the prototype (search bar styling + notification/person icon affordances).
    - [ ] Sub-task: Preserve existing behavior: debounced search + navigation to Inbox/Settings.
- [ ] Task: Implement Prototype Footer (GTA Alerts)
    - [ ] Sub-task: Add a footer component (new `resources/js/features/gta-alerts/components/Footer.tsx`) matching the prototype’s environment stats + links.
    - [ ] Sub-task: Ensure this coexists with the existing mobile-only `BottomNav` (`resources/js/features/gta-alerts/components/BottomNav.tsx`).
- [ ] Task: Update GTA Alerts Layout Wrapper
    - [ ] Sub-task: Update the root wrapper in `resources/js/features/gta-alerts/App.tsx` to match the prototype layout classes (`relative flex h-screen w-full overflow-hidden bg-background-dark text-white font-sans`), while keeping `.gta-alerts-theme` as the scoping root.
- [ ] Task: Implement Prototype Floating Action Button (Refresh)
    - [ ] Sub-task: Add a floating refresh button (prototype bottom-right) that triggers a safe feed refresh without breaking scroll/state (likely via Inertia reload for the feed view).
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

## Phase 4: Quality & Documentation
- [ ] Task: Verify Formatting, Types, and Tests
    - [ ] Sub-task: Run `pnpm run format:check`, `pnpm run lint:check`, `pnpm run types`, and `pnpm run test` (or `pnpm run quality:check`).
    - [ ] Sub-task: Run backend test suite if any shared layout assets were touched: `./vendor/bin/sail artisan test`.
- [ ] Task: Documentation Updates
    - [ ] Sub-task: Document any new UI components or styling changes.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Quality & Documentation' (Protocol in workflow.md)
