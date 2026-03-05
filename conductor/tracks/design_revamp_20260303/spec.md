# Specification: UI Design Revamp (Prototype Two)

## Overview
This track focuses on revamping the current GTA Alerts user interface based on the static mockups provided in the `prototype-two-design` folder. The redesign encompasses the global layout, the alert feed/table views, and foundational theme/CSS variables.

## Functional Requirements
- **Scope:** The revamp applies to the **GTA Alerts page** and its feature components under `resources/js/features/gta-alerts/`. Non-GTA pages should not be visually restyled as a side effect.
- **Global Layout (GTA Alerts):** Implement the prototype sidebar + header + footer + overall layout structure using the prototype's brutalist/high-contrast aesthetic.
- **Alert Feed & Table:** Redesign the alert presentation to support both a feed view and a dense table view, accurately reflecting the prototype's design:
  - Feed cards with strong status/severity affordances.
  - Table with expandable rows for incident summaries.
- **Theme & CSS Variables:** Integrate the prototype color palette (e.g., `primary`, `critical`, `warning`, `background-dark`, `panel-light`), typography (Public Sans), and utilities (e.g., `.brutalist-border`, `.panel-shadow`, `incident-table` helpers) from the prototype.

## Non-Functional Requirements
- **Integration Strategy:** Components will be replaced and updated on a component-by-component basis to ensure application stability and maintainability during the transition.
- **Styling Architecture:** The repo already uses Tailwind v4 `@theme` in `resources/css/app.css`. The revamp should:
  - Add any missing prototype tokens to `@theme` so Tailwind utilities exist (e.g., `bg-warning`, `bg-panel-light`).
  - Prefer scoping overrides under `.gta-alerts-theme { ... }` to avoid unintended cross-app styling changes.
- **Responsiveness:** Ensure the new layout remains responsive and usable across different screen sizes.
- **Automated Unit/Component Testability:** UI changes must keep `vitest` expectations coherent; update tests when labels/structure change.
- **Browser E2E Verification Requirement (Phase 4):**
  - Verification must run against the local dev site at **`http://localhost:8080/`**.
  - Preferred browser automation path is **Playwright MCP**; fallback is standalone **Playwright CLI** if MCP is unavailable.
  - Verification must include desktop and mobile viewport coverage for layout and interaction parity.
  - Evidence artifacts (screenshots and optional traces/videos) must be captured and referenced in phase notes.
- **Final Documentation Requirement (Phase 5):**
  - After testing passes, final documentation must be completed as a dedicated phase.
  - The phase must cover track artifacts (`plan.md`, `spec.md`, audit notes) and project-level docs (`README.md`, `CLAUDE.md`, relevant `docs/` pages).
  - The final closeout must include command evidence, artifact references, and registry/archival updates.

## Phase-Specific Delivery Requirements
### Phase 4: Testing & Verification
- Validate the revamp end-to-end at `http://localhost:8080/` using Playwright MCP or Playwright CLI.
- Verify shell parity across breakpoints:
  - Sidebar/header/footer render and interaction affordances on desktop.
  - Mobile drawer and bottom navigation behavior on mobile.
- Verify feed/table behavior:
  - `Feed` ↔ `Table` toggle remains client-side and does not trigger navigation.
  - Feed mode shows prototype-style alert cards with clear active vs cleared visual distinction.
  - Table mode supports expandable summary rows; expand/collapse control must not unintentionally trigger details navigation.
  - Incident selection pathways still open alert details where expected.
- Execute quality gates and record outcomes:
  - `pnpm run format:check`
  - `pnpm run lint:check`
  - `pnpm run types`
  - `pnpm run test` (or `pnpm run quality:check`)
  - `./vendor/bin/sail artisan test` when integration scope requires backend regression confidence.

### Phase 5: Final Comprehensive Documentation
- Update all track artifacts to reflect final implementation and verification outcomes.
- Document user-facing changes, testing workflow, and troubleshooting notes.
- Produce a final closeout summary including:
  - Commands executed and pass/fail status.
  - Verification artifact/log paths.
  - Remaining technical debt and follow-up ticket references.
- Complete track registry/archive maintenance per conductor workflow.

## Acceptance Criteria
- **Layout parity:** The GTA Alerts layout matches the structural and visual intent of `prototype-two-design/App.tsx` (sidebar, header search affordance, footer, and the prototype’s high-contrast styling), while preserving existing navigation behavior (Feed/Inbox/Saved/Zones/Settings).
- **View toggle parity:** The user can toggle between **"Feed"** and **"Table"** views for alerts, matching the prototype’s toggle styling/placement. The toggle is client-side and does not trigger navigation.
- **Table behavior parity:** The Table view supports expandable rows for summaries as shown in the prototype (an expanded detail row beneath the selected incident row).
- **Theme integration:** Public Sans is available, and required prototype tokens/utilities are integrated so Tailwind classes render correctly (no missing `bg-*`/`text-*` utilities used by the new UI).
- **No functional regressions:** Existing GTA Alerts behavior remains intact: debounced search, filter links (Inertia), infinite scroll, details view (unless explicitly replaced by in-table expansion), and routing.
- **Automated quality:** `pnpm run quality:check` passes for the updated UI. (If shared layout assets are modified, PHP/Laravel tests should remain green as well.)
- **Phase 4 verification complete:** Playwright MCP or Playwright CLI validation against `http://localhost:8080/` passes for required desktop/mobile and interaction scenarios, with artifact references recorded.
- **Phase 5 documentation complete:** Final track and project documentation updates are complete, with closeout evidence and registry/archive updates recorded.

## Out of Scope
- Adding new data sources or backend features.
- Major refactoring of state management or data fetching logic, beyond what is required for the UI components.
