# Specification: UI Design Revamp (Prototype Two)

## Overview
This track focuses on revamping the current GTA Alerts user interface based on the static mockups provided in the `prototype-two-design` folder. The redesign encompasses the global layout, the alert feed/table views, and foundational theme/CSS variables.

## Functional Requirements
- **Global Layout:** Implement the new sidebar, header, and main layout structure using the prototype's brutalist/high-contrast aesthetic.
- **Alert Feed & Table:** Redesign the alert presentation to support both a feed view and a dense table view, accurately reflecting the prototype's design.
- **Theme & CSS Variables:** Integrate the new color palette (e.g., `primary`, `critical`, `warning`, `background-dark`), typography (Public Sans), and custom utilities (e.g., `.brutalist-border`, `.panel-shadow`) from the prototype.

## Non-Functional Requirements
- **Integration Strategy:** Components will be replaced and updated on a component-by-component basis to ensure application stability and maintainability during the transition.
- **Styling Architecture:** Adopt the Tailwind CSS v4 `@theme` structure as demonstrated in the prototype (`index.css`), upgrading/aligning the current setup as needed.
- **Responsiveness:** Ensure the new layout remains responsive and usable across different screen sizes.

## Acceptance Criteria
- The application's main layout matches the structural and visual design of `prototype-two-design/App.tsx`.
- The user can toggle between "Feed" and "Table" views for alerts, matching the prototype's visual implementation.
- All new CSS variables, fonts, and Tailwind v4 theme configurations are successfully integrated and active across the application.
- Existing functionality (e.g., routing, data fetching) remains intact after the visual revamp.

## Out of Scope
- Adding new data sources or backend features.
- Major refactoring of state management or data fetching logic, beyond what is required for the UI components.