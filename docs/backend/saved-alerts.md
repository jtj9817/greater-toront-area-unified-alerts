# Saved Alerts System

This document describes the architecture, API contract, and guest/auth storage behaviour for the Saved Alerts feature.

## Overview

Saved Alerts is a bookmark system that lets users pin GTA Alerts items (Fire, Police, TTC, GO Transit) for later reference. It is **not** an immutable snapshot or historical archive — a saved alert is a pointer to the current unified alert record. If the underlying alert is removed from the feed, the pointer becomes unresolved and the UI surfaces it as an unavailable row with a remove action.

Key design decisions:
- **Naming convention:** `saved_alerts` table, `SavedAlert` model, `SavedAlertController`, `/api/saved-alerts` routes (mirrors `saved_places` pattern).
- **Hydration contract:** `GET /api/saved-alerts` returns fully hydrated `UnifiedAlertResource` payloads, not bare IDs.
- **Unresolved IDs:** omitted from `data`; listed in `meta.missing_alert_ids`.
- **Auth cap:** intentionally uncapped in this version — there is no server-side limit on how many alerts an authenticated user can save.
- **Guest cap:** 10 IDs, stored in `localStorage`; capped locally with a one-click "remove oldest three" eviction action.

---

## Persistence Layer

### Database Schema

```
saved_alerts
├── id          bigint, PK (auto-increment)
├── user_id     bigint FK → users.id (cascade delete)
├── alert_id    varchar(120) — canonical "{source}:{externalId}" format
├── created_at  timestamp
└── updated_at  timestamp

Indexes:
  UNIQUE (user_id, alert_id)   — prevents duplicate saves
  INDEX  (user_id, id)         — supports newest-first retrieval
```

### Eloquent Model

`App\Models\SavedAlert` — minimal; `fillable: [user_id, alert_id]`. Belongs to `User` via `user()`. `User` exposes a `savedAlerts()` has-many relation.

### Canonical Alert IDs

`alert_id` stores the existing `{source}:{externalId}` composite key (e.g. `fire:F26018618`). Validation in `App\Http\Requests\Notifications\SavedAlertStoreRequest` delegates to the `App\Services\Alerts\DTOs\AlertId` value-object contract.

---

## API Contract

All three endpoints require authentication (`auth` middleware, registered in `routes/settings.php`).

### GET /api/saved-alerts

Returns hydrated saved alerts for the authenticated user, newest-saved first.

**Response shape:**

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

- `data` — array of `UnifiedAlertResource` payloads, in the same order the user saved them (newest first). IDs that cannot be resolved are **omitted** from this array.
- `meta.saved_ids` — all saved `alert_id` values for the user, including unresolved ones; used by the frontend to derive saved-state badges without an additional roundtrip.
- `meta.missing_alert_ids` — IDs that are in the saved set but could not be resolved via `UnifiedAlertsQuery::fetchByIds()` (the underlying alert no longer exists or has been purged).

**Hydration path:** `SavedAlertController::index()` fetches the user's saved rows, then calls `UnifiedAlertsQuery::fetchByIds()` (which chunks up to 500 IDs to avoid bind-parameter limits). The result is keyed by ID and iterated in saved-order to produce the `data` array.

### POST /api/saved-alerts

Saves an alert for the authenticated user.

```
POST /api/saved-alerts
Content-Type: application/json

{ "alert_id": "fire:F26018618" }
```

| Status | Meaning |
|--------|---------|
| `201 Created` | Alert saved successfully. Response body: `{ "data": { "id": ..., "alert_id": ..., "saved_at": "..." } }` |
| `409 Conflict` | Alert was already saved by this user. |
| `422 Unprocessable Entity` | `alert_id` failed validation (invalid format). |
| `401 Unauthorized` | Not authenticated. |

Duplicate saves are detected with an application-layer pre-check first; the database `UNIQUE` constraint serves as the race-condition backstop and is caught as a `UniqueConstraintViolationException`.

### DELETE /api/saved-alerts/{alertId}

Removes a saved alert (scoped to the authenticated user).

```
DELETE /api/saved-alerts/fire%3AF26018618
```

`alertId` must be URL-encoded (`:` → `%3A`).

| Status | Meaning |
|--------|---------|
| `200 OK` | Alert removed. Response body: `{ "meta": { "deleted": true } }` |
| `404 Not Found` | No saved alert with that ID for the current user. |
| `401 Unauthorized` | Not authenticated. |

---

## Inertia Bootstrap State

Authenticated users receive their saved IDs as part of the initial Inertia payload from `GtaAlertsController`, preventing a flash from unsaved to saved on first render:

```php
// GtaAlertsController::show()
'saved_alert_ids' => $this->savedAlertIds($request),
```

The `saved_alert_ids` prop is a `string[]` of canonical IDs. Guests receive an empty array; the frontend bootstraps their saved state from `localStorage` during client initialisation instead.

---

## Guest Storage

Guest users cannot call the authenticated API. Saved IDs are stored client-side in `localStorage` under the versioned key `gta_saved_alerts_v1`.

- **Cap:** 10 IDs maximum.
- **Order:** Insertion order is preserved so that "oldest three" eviction is deterministic.
- **Cap UX:** When the cap is reached, the UI shows an explanatory message and a one-click action that removes the three oldest saved IDs (`evictOldestThree()`).
- **SSR safety:** `readGuestIds()` / `writeGuestIds()` guard against `window`/`localStorage` being unavailable during server-side rendering.
- **No automatic migration:** Logging in does not migrate guest bookmarks into the authenticated account. This is intentional and out of scope for this version.

---

## Frontend State Layer

`resources/js/features/gta-alerts/hooks/useSavedAlerts.ts` is the single source of truth for saved-alert state in the UI.

```ts
const {
  savedIds,        // string[] — current saved IDs in insertion order
  isSaved,         // (alertId: string) => boolean
  isPending,       // (alertId: string) => boolean — true while API call in-flight
  guestCapReached, // boolean
  saveAlert,       // (alertId: string) => Promise<void>
  removeAlert,     // (alertId: string) => Promise<void>
  toggleAlert,     // (alertId: string) => Promise<void>
  evictOldestThree,// () => void — guest cap relief action
  feedback,        // SavedAlertFeedback | null — inline status feedback
  clearFeedback,   // () => void
} = useSavedAlerts({ authUserId, initialSavedIds });
```

**Branching on `authUserId`:**
- `null` → guest mode; mutations write to `localStorage` only.
- non-null → auth mode; mutations call `SavedAlertService` (fetch wrappers) and apply optimistic local updates with rollback on failure.

**Feedback:** exposed as inline hook state (`SavedAlertFeedback | null`) rather than a global toast. Components render feedback contextually. This is deliberately separate from the realtime `NotificationToastLayer` which serves a different backend event stream.

**Supported feedback kinds:** `saved`, `removed`, `duplicate`, `limit`, `auth`, `validation`, `unknown`, `error`.

---

## API Service Layer

`resources/js/features/gta-alerts/services/SavedAlertService.ts` wraps the three API endpoints:

- `saveAlert(alertId)` — POST
- `removeAlert(alertId)` — DELETE (URL-encodes the ID)
- `fetchSavedAlerts()` — GET (validates the response shape before returning)

Errors are normalised to `SavedAlertServiceError` with a `kind` discriminant: `duplicate | auth | validation | unknown`.

---

## UI Integration Points

Saved state is lifted in `resources/js/features/gta-alerts/App.tsx` and passed down to all views:

| Component | Integration |
|-----------|-------------|
| `AlertCard.tsx` | Save toggle button (does not break card click-to-open) |
| `AlertTableView.tsx` | Save action in collapsed and expanded row states |
| `AlertDetailsView.tsx` | Full saved/pending/unsaved state with save/remove controls |
| `SavedView.tsx` | Full list of saved alerts with loading, empty, and unresolved states |

Unresolved saved IDs (present in `meta.missing_alert_ids`) are rendered as unavailable rows with a remove action in `SavedView`.

---

## Unresolved ID Handling

**Chosen approach:** omit from `data`, surface in `meta.missing_alert_ids`.

This keeps the `data` array clean (only renderable alerts), while the `missing_alert_ids` list gives the frontend enough information to render an unavailable row and a remove affordance for each unresolvable ID.

An ID becomes unresolvable when:
- The underlying alert record has been deleted from its source table.
- The feed has stopped reporting it and it was purged during a maintenance cycle.

The saved bookmark is **not** automatically deleted when the underlying alert becomes unresolvable; the user must explicitly remove it.

---

## Testing Coverage

| Layer | File |
|-------|------|
| Backend feature | `tests/Feature/Notifications/SavedAlertControllerTest.php` |
| Backend unit | `tests/Unit/Models/UserTest.php` (savedAlerts relation) |
| Frontend hook | `resources/js/features/gta-alerts/hooks/useSavedAlerts.test.ts` |
| Frontend service | `resources/js/features/gta-alerts/services/SavedAlertService.test.ts` |
| Manual verification | `tests/manual/verify_phase_5_saved_alerts_quality_gates.php` |
