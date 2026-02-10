# NOTIF-004: Phase 3 Frontend Test Expansion (Spec + Implementation Plan)

**Issue Type**: Story  
**Priority**: High  
**Status**: Backlog  
**Created**: 2026-02-10  
**Epic**: Notifications Phase 1 MVP  
**Components**: Frontend, Testing, Notifications  
**Labels**: `notifications`, `phase-3`, `frontend`, `vitest`, `react-testing-library`  
**Primary Reference**: `docs/plans/notification-system-feature-plan.md`

---

## 1. Summary
Expand frontend automated test coverage for Phase 3 notification settings and realtime toast behavior by adding deeper component and app-level scenarios that simulate pushed notification payloads into UI components.

---

## 2. Problem Statement
Phase 3 currently has baseline tests for settings and toasts, but important behavioral paths are under-tested. This creates regression risk for realtime delivery UX and preference-driven UI behavior.

Current risks:
1. Toast lifecycle logic is only partially verified (queue limits, timer edge-cases, and auth-user channel transitions are not fully asserted).
2. Settings behavior is not fully protected by tests for route option merge logic, control disabling, and key error-handling branches.
3. App-level integration behavior for receiving pushed events while navigating different views is minimally validated.

If these gaps regress, users may miss alerts, see stale toasts, or save incorrect settings.

---

## 3. Goals
1. Increase confidence in realtime in-app notification UX.
2. Ensure settings behavior remains stable as notification features evolve.
3. Verify push payload simulation flows from Echo listener to rendered UI.

## 4. Non-Goals
1. Backend matcher/delivery job changes.
2. Notification Center (Phase 4) inbox behavior.
3. End-to-end websocket infrastructure tests with external brokers.

---

## 5. Functional Specification

### 5.1 Toast Behavior Coverage
The test suite must verify:
1. Valid pushed payloads render visible toasts.
2. Invalid payloads are ignored with no render side-effects.
3. Toasts auto-dismiss after timeout.
4. Manual dismiss removes toast and clears timer state.
5. Toast queue enforces max display count.
6. Channel subscriptions cleanly transition when `authUserId` changes.

### 5.2 Settings Behavior Coverage
The test suite must verify:
1. Route options are merged from:
   - server-provided `availableRoutes`
   - existing `subscribed_routes`
   - `KNOWN_TRANSIT_ROUTES`
2. Route options are deduplicated and sorted.
3. Route filters disable correctly for non-transit alert types.
4. Geofence add/remove and duplicate-block behavior.
5. API load/save error states show correct UX copy.

### 5.3 App Integration Coverage
The test suite must verify:
1. `App` renders `NotificationToastLayer` for authenticated users.
2. Simulated pushed events render toasts regardless of current app view.
3. Unmount/navigation cleanup unsubscribes and leaves the correct channel.

---

## 6. Acceptance Criteria
1. New tests are added for all scenarios listed in Section 5.
2. All new and existing notification frontend tests pass via Vitest.
3. No flakiness under fake timers or repeated event emission.
4. Test names are behavior-first and map to user outcomes.
5. Coverage on Phase 3-related components increases measurably versus current baseline.

---

## 7. Technical Design Decisions

| Decision | Choice |
| :--- | :--- |
| Test Framework | Vitest + React Testing Library |
| Push Simulation | Mock `window.Echo.private().listen()` handlers and invoke callbacks with crafted payloads |
| Timer Strategy | Use fake timers for deterministic auto-dismiss assertions |
| Scope of Integration | Component-level plus lightweight `App` composition tests |
| Source Requirements | Align behaviors with `docs/plans/notification-system-feature-plan.md` Phase 1 MVP (Preference Management + Real-Time In-App Delivery) |

---

## 8. Implementation Plan

### Phase 1: Toast Component Deep Scenarios
**Target files**
- `resources/js/features/gta-alerts/components/NotificationToastLayer.test.tsx`

**Tasks**
1. Add fake-timer tests for auto-dismiss after timeout.
2. Add queue-cap test for >4 events.
3. Add invalid payload rejection tests.
4. Add auth-user switch resubscription test.
5. Add manual dismiss + timer cleanup verification.

**Definition of Done**
1. All new toast scenarios pass reliably in CI mode.
2. No brittle assertions tied to incidental markup.

### Phase 2: Settings Component Behavioral Expansion
**Target files**
- `resources/js/features/gta-alerts/components/SettingsView.test.tsx`

**Tasks**
1. Add route merge/dedupe/sort contract test.
2. Add alert-type dependent route disable test.
3. Add geofence duplicate prevention and remove-flow test.
4. Add API load error tests (`401`, generic).
5. Add API save error tests (`422`, generic).

**Definition of Done**
1. Tests validate both happy and failure paths.
2. User-visible messages are asserted exactly where critical.

### Phase 3: App Integration Push Simulation
**Target files**
- `resources/js/features/gta-alerts/App.test.tsx`

**Tasks**
1. Add authenticated render case that mounts toast layer.
2. Simulate pushed event while app is in different views and assert toast visibility.
3. Assert channel leave/cleanup on unmount.

**Definition of Done**
1. App integration tests verify cross-component contract, not backend internals.
2. Tests remain fast (<1s/file target where practical).

### Phase 4: Validation and Reporting
**Commands**
1. `CI=true LARAVEL_BYPASS_ENV_CHECK=1 pnpm exec vitest run resources/js/features/gta-alerts/components/NotificationToastLayer.test.tsx resources/js/features/gta-alerts/components/SettingsView.test.tsx resources/js/features/gta-alerts/App.test.tsx`
2. `CI=true LARAVEL_BYPASS_ENV_CHECK=1 pnpm run test`

**Definition of Done**
1. Target suite passes.
2. Full frontend test suite passes.
3. Ticket checklist is updated with result notes.

---

## 9. File Impact Summary

| File | Action | Purpose |
| :--- | :--- | :--- |
| `resources/js/features/gta-alerts/components/NotificationToastLayer.test.tsx` | Modify | Add realtime lifecycle and payload-edge tests |
| `resources/js/features/gta-alerts/components/SettingsView.test.tsx` | Modify | Add settings state and error-path coverage |
| `resources/js/features/gta-alerts/App.test.tsx` | Modify | Add app-level push-to-UI integration checks |
| `docs/tickets/NOTIF-004-phase-3-frontend-test-expansion-spec-plan.md` | Create | Persist implementation spec and execution plan |

---

## 10. Risks and Mitigations
1. **Risk**: Flaky timer-based tests.  
   **Mitigation**: Use fake timers and deterministic advancement.
2. **Risk**: Over-mocking hides integration defects.  
   **Mitigation**: Keep assertions at behavior level and include `App` composition tests.
3. **Risk**: Test brittleness from presentation changes.  
   **Mitigation**: Prefer semantic queries and stable labels over CSS-dependent selectors.

---

## 11. Rollback Plan
1. Revert new tests if they block unrelated release work.
2. Re-introduce scenarios incrementally behind smaller commits.
3. Keep this ticket doc as canonical plan for resumed execution.

---

## 12. Traceability to Feature Plan
This ticket directly supports the following areas from:
`docs/plans/notification-system-feature-plan.md`

1. **Preference Management**: stronger validation of settings UI behavior and API interaction paths.
2. **Real-Time In-App Delivery**: stronger simulation of pushed payload rendering and toast lifecycle behavior.
3. **Phase 1 MVP Reliability**: improved regression safety for core notification UX.

---

## 13. Ready Checklist
- [ ] Scope approved
- [ ] Test scenarios approved
- [ ] Implementation started
- [ ] Targeted tests passing
- [ ] Full frontend suite passing
- [ ] Ticket moved to Done
