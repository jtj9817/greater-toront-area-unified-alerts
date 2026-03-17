# Implementation Plan: Saved Alerts Feature

## Phase 1: Backend Infrastructure & API (GTA-101)

- [ ] Task: Database Schema & Models [TDD]
    - [ ] Create migration for `user_alerts` table (`id`, `user_id`, `alert_id`, `timestamps`).
    - [ ] Create `UserAlert` model with `user_id` and `alert_id` fillable.
    - [ ] Add `savedAlerts()` relationship to `User` model.
- [ ] Task: User Alerts REST API [TDD]
    - [ ] Implement `POST /api/user/alerts` in `UserAlertController@store`.
    - [ ] Implement `GET /api/user/alerts` in `UserAlertController@index`.
    - [ ] Implement `DELETE /api/user/alerts/{alertId}` in `UserAlertController@destroy`.
    - [ ] Add auth-protected routes in `routes/api.php`.
- [ ] Task: Backend Feature Tests
    - [ ] Write `tests/Feature/UserAlertTest.php` covering index, store (including 409 conflict), and destroy.
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Backend Infrastructure & API' (Protocol in workflow.md)

## Phase 2: Frontend State & Storage Hooks (GTA-102)

- [ ] Task: Implement `useSavedAlerts` Custom Hook [TDD]
    - [ ] Implement logic to detect `auth.user` and route save/delete to API or `localStorage`.
    - [ ] Implement `localStorage` handling with a 10-item cap.
    - [ ] Implement `clearOldestThree()` method for guest eviction.
- [ ] Task: Frontend Hook Unit Tests
    - [ ] Write `resources/js/features/gta-alerts/hooks/useSavedAlerts.test.ts` to verify local and API branching.
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Frontend State & Hooks' (Protocol in workflow.md)

## Phase 3: UI Integration (GTA-103)

- [ ] Task: Implement "Saved" Toggle in Feed Cards
    - [ ] Update `AlertCard.tsx` to include a save toggle with appropriate Radix UI/Lucide icons.
    - [ ] Apply "Saved" badge/styling when bookmarked.
- [ ] Task: Implement "Saved" Toggle in Table Rows
    - [ ] Update `AlertTableView.tsx` to include a save toggle/button in each row.
- [ ] Task: Wire Save Action in Alert Details
    - [ ] Update `AlertDetailsView.tsx` with the save button and handle limit-reached toasts.
- [ ] Task: Implement "Saved" Tab Filtering
    - [ ] Add a "Saved" filter to the `FeedView.tsx` view toggle or tab list.
    - [ ] Update `AlertService.ts` to filter the unified feed based on the saved state.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: UI Integration' (Protocol in workflow.md)

## Phase 4: QA Gating (GTA-104)

- [ ] Task: Execute Quality & Coverage Gates
    - [ ] Run `./vendor/bin/sail artisan test --coverage --min=90` to verify full backend coverage.
    - [ ] Run `pnpm run quality:check` (Linting, Types, Tests).
    - [ ] Perform a security audit: `./vendor/bin/sail composer audit` & `pnpm audit`.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: QA Gating' (Protocol in workflow.md)

## Phase 5: Documentation & Closeout (GTA-105)

- [ ] Task: Update Technical Documentation
    - [ ] Update `README.md` and `CLAUDE.md` with details on the Saved Alerts feature.
    - [ ] Create/Update documentation in `docs/` for the new API and frontend hook.
- [ ] Task: Registry Maintenance
    - [ ] Move the track to the archive in `conductor/tracks.md` and update its status.
- [ ] Task: Conductor - User Manual Verification 'Phase 5: Documentation & Closeout' (Protocol in workflow.md)
