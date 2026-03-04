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
- **Testability:** UI changes must keep `vitest` expectations coherent; update tests when labels/structure change.

## Acceptance Criteria
- **Layout parity:** The GTA Alerts layout matches the structural and visual intent of `prototype-two-design/App.tsx` (sidebar, header search affordance, footer, and the prototype’s high-contrast styling), while preserving existing navigation behavior (Feed/Inbox/Saved/Zones/Settings).
- **View toggle parity:** The user can toggle between **"Feed"** and **"Table"** views for alerts, matching the prototype’s toggle styling/placement. The toggle is client-side and does not trigger navigation.
- **Table behavior parity:** The Table view supports expandable rows for summaries as shown in the prototype (an expanded detail row beneath the selected incident row).
- **Theme integration:** Public Sans is available, and required prototype tokens/utilities are integrated so Tailwind classes render correctly (no missing `bg-*`/`text-*` utilities used by the new UI).
- **No functional regressions:** Existing GTA Alerts behavior remains intact: debounced search, filter links (Inertia), infinite scroll, details view (unless explicitly replaced by in-table expansion), and routing.
- **Automated quality:** `pnpm run quality:check` passes for the updated UI. (If shared layout assets are modified, PHP/Laravel tests should remain green as well.)

## Out of Scope
- Adding new data sources or backend features.
- Major refactoring of state management or data fetching logic, beyond what is required for the UI components.
