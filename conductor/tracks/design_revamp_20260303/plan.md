# Implementation Plan: UI Design Revamp (Prototype Two)

## Phase 1: Foundation & Theme Setup
- [ ] Task: Update Global CSS and Tailwind Configuration
    - [ ] Sub-task: Import 'Public Sans' font and 'Material Symbols Outlined' in the main CSS file.
    - [ ] Sub-task: Migrate custom CSS variables and utility classes (e.g., `.brutalist-border`, `.panel-shadow`) from `prototype-two-design/index.css`.
    - [ ] Sub-task: Update Tailwind v4 `@theme` configuration with the new color palette.
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Foundation & Theme Setup' (Protocol in workflow.md)

## Phase 2: Global Layout Implementation
- [ ] Task: Implement New Sidebar Component
    - [ ] Sub-task: Create or update the Sidebar component using the prototype design.
    - [ ] Sub-task: Integrate Material Symbols and new hover/active states.
- [ ] Task: Implement New Header and Footer Components
    - [ ] Sub-task: Create or update the Header component (Search bar, user/notification icons).
    - [ ] Sub-task: Create or update the Footer component (Environment stats, links).
- [ ] Task: Update Main App Layout Wrapper
    - [ ] Sub-task: Wrap the application with the new layout structure (`flex h-screen w-full overflow-hidden bg-background-dark text-white font-sans`).
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Global Layout Implementation' (Protocol in workflow.md)

## Phase 3: Alert Feed & Table Views
- [ ] Task: Implement View Toggle State
    - [ ] Sub-task: Add `viewMode` state ('feed' | 'table') to the main alerts page.
    - [ ] Sub-task: Implement the toggle button UI.
- [ ] Task: Implement Alert Table View
    - [ ] Sub-task: Create the `incident-table` component matching the prototype.
    - [ ] Sub-task: Implement expandable row UI for incident summaries.
- [ ] Task: Implement Alert Feed View
    - [ ] Sub-task: Create the detailed feed card component.
    - [ ] Sub-task: Ensure visual distinction for different alert statuses (e.g., Active vs Cleared/Grayscale).
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Alert Feed & Table Views' (Protocol in workflow.md)

## Phase 4: Quality & Documentation
- [ ] Task: Verify Formatting and Linting
    - [ ] Sub-task: Run ESLint and Prettier on all modified React files.
- [ ] Task: Documentation Updates
    - [ ] Sub-task: Document any new UI components or styling changes.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Quality & Documentation' (Protocol in workflow.md)