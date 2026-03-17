# Implementation Plan: Saved Alerts Feature

## Architecture Alignment

- The GTA Alerts UI already has a `saved` navigation slot via `resources/js/features/gta-alerts/App.tsx`, `Sidebar.tsx`, `BottomNav.tsx`, and a placeholder `SavedView.tsx`; this work should fill that existing shell rather than introduce a new page or route.
- Auth-protected JSON endpoints in this repository are currently registered in `routes/settings.php` under the `auth` middleware group, not in `routes/api.php`.
- Existing notification-adjacent APIs live under `App\Http\Controllers\Notifications\*` and use `data` / `meta` JSON envelopes. Saved alerts should follow that pattern.
- Canonical alert identifiers already exist as `{source}:{externalId}` strings and are validated in `App\Services\Alerts\DTOs\AlertId`; reuse that contract instead of inventing a new ID format.
- The frontend currently has no generic action-toast primitive. If save/unsave feedback must be transient, that UX needs to be explicitly designed and implemented as part of this track.

## Locked Decisions

- [x] Persistence naming convention: use `saved_alerts`, `SavedAlert`, `SavedAlertController`, and `/api/saved-alerts` for parity with `saved_places`.
- [x] `SavedView` hydration contract: `GET /api/saved-alerts` returns hydrated alert resources, not IDs only.
- [x] Guest cap UX: present a one-click action to clear the oldest three saved IDs when the 10-item guest cap is reached.
- [x] Unresolved saved IDs: keep the saved record and surface unavailable rows with a remove action.

## Phase 1: Persistence Contract & Backend API (GTA-101)

- [x] Task: Define the persistence shape and naming. <!-- 86163e6 -->
    - [x] Implement the locked `saved_alerts` naming across migration, model, controller, routes, and tests.
    - [x] Implement the locked hydrated read contract for `GET /api/saved-alerts`.
- [x] Task: Create the persistence layer [TDD]. <!-- 86163e6 -->
    - [x] Add migration for the saved-alert table with `id`, `user_id`, `alert_id`, timestamps, a unique index on `user_id + alert_id`, and an index supporting newest-first retrieval.
    - [x] Create the corresponding Eloquent model with minimal fillable state.
    - [x] Add `savedAlerts()` to `app/Models/User.php` and extend `tests/Unit/Models/UserTest.php`.
- [x] Task: Add request validation and ID normalization [TDD]. <!-- 86163e6 -->
    - [x] Create a form request for create operations under `app/Http/Requests/Notifications/`.
    - [x] Validate `alert_id` against the existing `AlertId` contract rather than only checking `string`.
    - [x] Strip/normalize user input before validation to match the repository’s sanitization pattern.
- [x] Task: Implement auth-only saved-alert endpoints [TDD]. <!-- 86163e6 -->
    - [x] Add routes in `routes/settings.php` under the existing `auth` group.
    - [x] Implement index/store/destroy actions in `App\Http\Controllers\Notifications\SavedAlertController`.
    - [x] Match existing JSON response conventions: `data` for resources and `meta.deleted` for deletion acknowledgements.
    - [x] Handle duplicate saves with a deterministic application response and preserve the database unique constraint as the race-condition backstop.
- [x] Task: Backend feature coverage. <!-- 86163e6 -->
    - [x] Add `tests/Feature/Notifications/SavedAlertControllerTest.php` covering auth requirements, create, duplicate create, list, delete, owner scoping, and invalid `alert_id` input.
    - [x] Add a limit/behavior test file if authenticated-user caps are introduced later; otherwise document that auth saves are uncapped in this iteration. <!-- Auth saves are intentionally uncapped in this iteration; documented via the "authenticated saves are uncapped" test -->
- [x] Task: Conductor - User Manual Verification ‘Phase 1: Persistence Contract & Backend API’ (Protocol in `conductor/workflow.md`). <!-- dc6e9f3 -->

## Phase 2: Saved Alert Read Model & Feed Hydration (GTA-102) [checkpoint: 00e4bd7]

- [x] Task: Define how saved IDs become renderable alerts. <!-- ae500bb -->
    - [x] Extend backend read logic so saved alerts can be resolved into `UnifiedAlertResource` payloads.
    - [x] Include unavailable saved-alert records in response metadata so the UI can render unresolved rows and removal actions.
- [x] Task: Implement the saved-alert read model [TDD]. <!-- ae500bb -->
    - [x] Add the backend query path needed to fetch saved alerts in a deterministic order (typically newest saved first).
    - [x] Decide how unresolved IDs are represented (`missing_alert_ids`, omitted records, or unavailable stubs). <!-- Chose: omit from `data`, list in `meta.missing_alert_ids` -->
    - [x] Ensure the response shape is compatible with the existing frontend domain mappers in `resources/js/features/gta-alerts/domain/alerts/`.
- [x] Task: Bootstrap saved IDs into the app shell [TDD]. <!-- ae500bb -->
    - [x] Update `app/Http/Controllers/GtaAlertsController.php` so authenticated users can arrive with initial saved-alert state instead of requiring a second request before badges render.
    - [x] Update `resources/js/pages/gta-alerts.tsx` and `resources/js/features/gta-alerts/App.tsx` props to carry the initial saved IDs.
- [x] Task: Backend/query coverage. <!-- ae500bb -->
    - [x] Add tests for the saved-alert hydration path, including mixed-source IDs and unresolved IDs.
    - [x] Add regression tests for the initial Inertia payload when the request is authenticated versus guest.
- [x] Task: Conductor - User Manual Verification 'Phase 2: Saved Alert Read Model & Feed Hydration' (Protocol in `conductor/workflow.md`).

## Phase 3: Frontend Saved Alert State (GTA-103)

- [ ] Task: Implement a dedicated saved-alert state layer [TDD].
    - [ ] Add `resources/js/features/gta-alerts/hooks/useSavedAlerts.ts`.
    - [ ] Make the hook SSR-safe and branch on `auth.user` / initial props instead of assuming browser-only state.
    - [ ] Store guest data in a versioned `localStorage` key and keep a deterministic oldest-first order for eviction.
    - [ ] Support `saveAlert`, `removeAlert`, `toggleAlert`, `isSaved`, and the guest eviction helper selected in the final UX decision.
- [ ] Task: Add a small API client/service for auth flows [TDD].
    - [ ] Create `resources/js/features/gta-alerts/services/SavedAlertService.ts` following the same fetch/error conventions as `SavedPlaceService.ts`.
    - [ ] Normalize backend payloads and expose typed error states for duplicate save, auth failure, validation failure, and unresolved-item handling.
- [ ] Task: Decide and implement user feedback behavior.
    - [ ] Either add a reusable action-feedback layer for save/unsave/limit events or intentionally use an inline/status-message pattern.
    - [ ] Keep the implementation separate from the realtime `NotificationToastLayer`, which serves a different backend event stream.
- [ ] Task: Frontend unit coverage.
    - [ ] Add `resources/js/features/gta-alerts/hooks/useSavedAlerts.test.ts` for guest storage, auth API branching, bootstrap state, eviction, and duplicate handling.
    - [ ] Add service tests if the saved-alert API client contains non-trivial normalization/error logic.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Frontend Saved Alert State' (Protocol in `conductor/workflow.md`).

## Phase 4: UI Integration Across Existing Views (GTA-104)

- [ ] Task: Wire saved state into the GTA Alerts shell.
    - [ ] Lift saved-alert state high enough in `resources/js/features/gta-alerts/App.tsx` so `FeedView`, `SavedView`, and `AlertDetailsView` all use the same source of truth.
    - [ ] Revisit the current ID-only detail navigation contract if the saved view needs to open alerts that were hydrated outside the initial feed payload.
- [ ] Task: Integrate save toggles into feed cards and table rows.
    - [ ] Update `AlertCard.tsx` to add a dedicated save control without breaking the existing card click-to-open behavior.
    - [ ] Update `AlertTableView.tsx` to add a save action in both collapsed and expanded states as needed.
    - [ ] Reuse the existing `Icon` component and current design language instead of introducing a separate Lucide-only icon system.
- [ ] Task: Integrate save state into alert details.
    - [ ] Update `AlertDetailsView.tsx` to render real saved state, pending state, and save/remove actions.
    - [ ] Prevent duplicate submissions while the save action is in flight.
- [ ] Task: Replace the placeholder saved view.
    - [ ] Update `SavedView.tsx` to render real saved alerts instead of the current empty placeholder list.
    - [ ] Remove or replace the out-of-scope “Create Watchlist” affordance unless watchlists are intentionally being added to the scope.
    - [ ] Add empty, loading, and unresolved-alert states, with a remove action for unresolved rows.
- [ ] Task: Frontend component coverage.
    - [ ] Update/add tests for `AlertCard`, `AlertTableView`, `AlertDetailsView`, `SavedView`, and `App.tsx` to cover saved-state rendering and interactions.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: UI Integration Across Existing Views' (Protocol in `conductor/workflow.md`).

## Phase 5: Quality Gates, Documentation & Closeout (GTA-105)

- [ ] Task: Execute automated quality gates.
    - [ ] Run `composer test`.
    - [ ] Run `pnpm run quality:check`.
    - [ ] Attempt `php artisan test --coverage --min=90` or `./vendor/bin/sail artisan test --coverage --min=90` depending on the available coverage driver/runtime, and document any environment blocker if strict coverage cannot run.
- [ ] Task: Add manual verification artifacts.
    - [ ] Add/update manual verification scripts for guest save flows, authenticated save flows, saved-view rendering, and unresolved saved IDs.
    - [ ] Record which architectural option was chosen for hydration and unresolved records.
- [ ] Task: Update technical documentation.
    - [ ] Update `README.md`, `CLAUDE.md`, and any relevant `docs/` references with the saved-alert API contract, guest/local behavior, and known limitations.
    - [ ] Document the chosen table/model naming if it differs from the earlier draft.
- [ ] Task: Registry maintenance.
    - [ ] Update conductor registry status and archive bookkeeping once implementation and verification are complete.
- [ ] Task: Conductor - User Manual Verification 'Phase 5: Quality Gates, Documentation & Closeout' (Protocol in `conductor/workflow.md`).
