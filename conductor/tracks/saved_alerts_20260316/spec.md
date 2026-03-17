# Track Specification: Implement "Saved Alerts" Feature

## 1. Overview
The "Saved Alerts" feature allows users to bookmark specific emergency and transit alerts (Fire, Police, Transit) for future reference. The system supports persistence for both guest and authenticated users with clear boundaries between client-side and server-side storage.

## 2. Functional Requirements

### 2.1 Storage Strategies
- **Guest Users:**
    - Use the Browser Web Storage API (`localStorage`).
    - Capped at **10 alerts**.
    - If the cap is reached, users are prompted to manually evict the oldest 3 alerts.
- **Authenticated Users:**
    - Use a PostgreSQL/MySQL database via a junction table `user_alerts`.
    - No hard cap on the number of saved alerts in the database (beyond reasonable system limits).
    - **Isolation:** Local storage and database storage are isolated. Logging in does not automatically synchronize guest alerts to the database.

### 2.2 User Interface (UI) Components
- **Save Action Entrypoints:**
    - **Feed Cards:** A "Save" toggle/button on each card in the Feed view.
    - **Table Rows:** A "Save" toggle/button on each row in the Table view.
    - **Alert Details:** A "Save" button in the detailed view.
- **Visual Feedback:**
    - **Toasts:** Use the existing Radix UI Toast system for Success (Saved), Conflict (Already Saved), and Limit Reached (Guest cap) notifications.
    - **Saved Badges:** Display a "Saved" indicator/badge on cards and table rows that are already bookmarked.
- **Management:**
    - **Saved Tab:** A new "Saved" tab/filter in the main dashboard that narrows the unified feed to only display the user's saved alerts.

### 2.3 Backend API (Auth Only)
- **POST `/api/user/alerts`:**
    - Input: `alert_id` (String format: `source:id`, e.g., `police:77`).
    - Logic: Validate input, check for existing record, insert if new.
    - Response: `201 Created` or `409 Conflict`.
- **GET `/api/user/alerts`:**
    - Response: JSON list of saved `alert_id`s.

## 3. Acceptance Criteria
- [ ] Users can toggle the "Saved" state of any alert from Cards, Table Rows, and the Details View.
- [ ] Guest users cannot save more than 10 alerts; they receive a prompt to clear space.
- [ ] Authenticated users have their alerts persisted in the database across sessions and devices.
- [ ] A "Saved" indicator is visible on all alerts that have been bookmarked.
- [ ] The "Saved" tab correctly filters the unified feed to only show bookmarked alerts.
- [ ] All API endpoints are protected by authentication middleware.
- [ ] Success/Error toasts appear for all save/unsave interactions.

## 4. Out of Scope
- Automated synchronization of guest alerts to a user account upon login.
- Email or push notifications based on saved alert updates.
- Custom categorization/folders for saved alerts.
- Sharing saved alerts with other users.
