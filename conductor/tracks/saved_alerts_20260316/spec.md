# Track Specification: Implement Saved Alerts Feature

## 1. Overview

The Saved Alerts feature adds bookmark-style persistence for GTA Alerts items across Fire, Police, TTC, and GO Transit sources. It must fit the current Laravel + Inertia + React architecture already in the repository:

- the main shell is the existing `gta-alerts` page and `resources/js/features/gta-alerts/App.tsx`
- the app already has a `saved` navigation destination and a placeholder `SavedView.tsx`
- authenticated JSON endpoints currently live in `routes/settings.php`
- alert identity is already standardized as `{source}:{externalId}` via `App\Services\Alerts\DTOs\AlertId`

This feature is a bookmark system for existing unified alerts. It is not a historical snapshot/archive system unless that is explicitly added later.

## 2. Architecture Constraints To Honor

### 2.1 Backend Routing & Naming
- Do not spec this feature around `routes/api.php`; that file does not exist in the current application.
- Auth-only saved-alert endpoints should follow the current notifications pattern in `routes/settings.php` and `App\Http\Controllers\Notifications\*`.
- JSON responses should follow existing repository conventions with `data` and `meta` envelopes.

### 2.2 Frontend Shell
- Saved alerts must integrate into the existing GTA Alerts shell instead of adding a separate page.
- `SavedView.tsx` should be treated as an existing placeholder to replace, not a new concept to introduce.
- `AlertCard.tsx`, `AlertTableView.tsx`, `AlertDetailsView.tsx`, and `FeedView.tsx` are the correct integration points for saved-state UI.

### 2.3 Alert Identity
- Persist only canonical alert IDs using the existing `{source}:{externalId}` contract.
- Validation should reuse the existing `AlertId` value object semantics rather than relying on a loose string rule.
- No alternate ID shape should be introduced for saved alerts.

### 2.4 User Feedback
- The repository does not currently contain a generic action-toast system for save/unsave UI events.
- If transient toasts are required, they must be explicitly added as part of this feature rather than assumed to already exist.
- The realtime `NotificationToastLayer` is not the saved-alert feedback mechanism and should not be repurposed for local action state.

## 3. Functional Requirements

### 3.1 Persistence Model
- Locked naming for this track:
    - table: `saved_alerts`
    - model: `SavedAlert`
    - controller: `SavedAlertController`
    - routes: `/api/saved-alerts`
- The persistence table must include:
    - `id`
    - `user_id`
    - `alert_id`
    - `created_at`
    - `updated_at`
- The table must enforce a unique constraint on `user_id + alert_id`.
- The authenticated system has no hard cap in this version.

### 3.2 Guest Storage
- Guest users store saved alert IDs in `localStorage`.
- Guest storage is capped at 10 saved alert IDs.
- Guest storage must be isolated from authenticated storage; logging in does not automatically migrate guest bookmarks in this version.
- Guest storage must preserve insertion order so “oldest three” eviction is deterministic.
- Locked UX when the guest cap is reached:
    - show a clear explanatory message
    - offer an explicit one-click action to remove the oldest three saved IDs

### 3.3 Authenticated API Contract
- Locked write contract:
    - `POST /api/saved-alerts`
    - request body: `{ "alert_id": "fire:F26018618" }`
    - validates `alert_id`
    - inserts if new
    - returns `201 Created`
    - duplicate saves return a deterministic non-success response (`409 Conflict` is acceptable and matches the earlier draft)
- Locked delete contract:
    - `DELETE /api/saved-alerts/{alertId}`
    - scopes deletion to the authenticated user only
    - returns `200 OK` with `meta.deleted = true`
- Locked read contract:
    - `GET /api/saved-alerts`
    - returns hydrated saved alerts for `SavedView`
    - also returns enough metadata to derive saved-state badges without an extra roundtrip
    - response shape:

```json
{
  "data": [
    {
      "id": "fire:F26018618",
      "source": "fire",
      "external_id": "F26018618",
      "is_active": true,
      "timestamp": "2026-03-16T12:00:00Z",
      "title": "STRUCTURE FIRE",
      "location": { "name": "Main St", "lat": null, "lng": null },
      "meta": { "event_num": "F26018618" }
    }
  ],
  "meta": {
    "saved_ids": ["fire:F26018618"],
    "missing_alert_ids": []
  }
}
```

### 3.4 Saved View Data Semantics
- `SavedView` must be able to render saved alerts even when they are not part of the current feed page payload.
- A saved alert is a pointer to the current unified alert record, not an immutable snapshot.
- If a saved alert ID can no longer be resolved:
    - backend must include it in `meta.missing_alert_ids`
    - frontend must show an unavailable state with a remove action

### 3.5 Inertia Bootstrap State
- Authenticated users should arrive on the GTA Alerts page with initial saved-alert state available in the Inertia payload so card/table/detail badges do not flash from unsaved to saved after mount.
- Required prop addition from `GtaAlertsController` to the `gta-alerts` page:
    - `saved_alert_ids: string[]`
- Guests bootstrap saved state from `localStorage` during client initialization.

### 3.6 UI Entry Points
- Save/remove actions must be supported from:
    - feed cards
    - table rows
    - alert details view
    - the saved alerts view itself
- Card-level save buttons must not break the existing card click behavior that opens details.
- Table-level save actions must not break the current row expand/collapse interaction.
- The existing `Icon` component and current GTA Alerts visual language should be reused instead of introducing a separate icon system for this feature.

### 3.7 Feedback & States
- The UI must represent at least these states:
    - unsaved
    - saved
    - pending save/remove action
    - guest limit reached
    - saved view loading
    - saved view empty
    - unresolved/missing saved alert
- Success, duplicate, limit, validation, and remove outcomes must have explicit user-visible feedback.
- Whether that feedback is implemented as transient toast UI or inline/status messaging is an open design decision, but the spec must not assume an already-existing toast system.

## 4. Testing & Quality Requirements

- Backend coverage must include:
    - auth enforcement
    - valid save
    - duplicate save handling
    - delete scoping to owner
    - invalid `alert_id`
    - hydrated read contract and unresolved IDs if that path is selected
- Frontend coverage must include:
    - guest `localStorage` behavior
    - authenticated API branching
    - save/remove/toggle interactions in card, table, detail, and saved views
    - guest-cap handling
    - bootstrap of initial saved IDs from Inertia props
- Quality gates should include:
    - `composer test`
    - `pnpm run quality:check`
    - coverage command if a coverage driver/runtime is available

## 5. Documentation & Closeout Requirements

- `README.md`, `CLAUDE.md`, and relevant `docs/` files must be updated to reflect the saved-alert API contract, guest/local storage behavior, and any known limitations.
- The chosen persistence naming (`saved_alerts`, `SavedAlert`, `SavedAlertController`) and hydration/unresolved-ID approach must be documented if not already captured in `CLAUDE.md`.
- The conductor registry status must be updated and track bookkeeping archived once all phases are verified complete.

## 6. Acceptance Criteria

- [ ] Saved alerts use the existing canonical alert ID format and validate against the current alert identity contract.
- [ ] Auth-only saved-alert routes follow the repository’s current routing/controller conventions instead of assuming `routes/api.php`.
- [ ] Guest users can save up to 10 alerts locally and receive explicit feedback when the cap is reached.
- [ ] Authenticated users can save and remove alerts and see that state persist across sessions.
- [ ] Feed cards, table rows, alert details, and the saved view all render consistent saved state from one source of truth.
- [ ] `SavedView` renders real saved alerts instead of the current placeholder empty list.
- [ ] The chosen unresolved-ID behavior is implemented and documented.
- [ ] Tests and quality gates are updated to cover the new backend and frontend contracts.

## 7. Out of Scope

- Automatic migration of guest-saved alerts into an authenticated account on login.
- Immutable snapshotting/archival of alert payloads at the time of save.
- Watchlists, folders, or custom saved-alert groupings.
- Push/email notifications triggered specifically by saving an alert.
- Sharing saved alerts with other users.

## 8. Locked Product Decisions

- [x] Persistence layer naming uses `saved_alerts`.
- [x] `GET /api/saved-alerts` returns hydrated alert resources.
- [x] Guest-cap UX includes a one-click “clear oldest three” action.
- [x] Unresolved saved IDs surface as unavailable rows with a remove action.
