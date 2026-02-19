# In-App Notification System

This document describes the current architecture, schema, matching logic, and API surface for the in-app notification system.

## Overview

The notification system delivers real-time, user-targeted alerts based on notification preferences and saved places.

Users can configure:
- Alert type filters (`all`, `emergency`, `transit`, `accessibility`)
- Severity thresholds (`all`, `minor`, `major`, `critical`)
- Granular subscriptions (`route:*`, `line:*`, `station:*`, `agency:*`)
- Digest mode and push toggle
- Saved places (separate resource used for geofence matching)

The pipeline is event-driven and supports real-time delivery plus inbox management.

## Architecture

```
Alert source command/service
    ↓
AlertCreated event (NotificationAlert DTO)
    ↓
DispatchAlertNotifications listener
    ↓
FanOutAlertNotificationsJob
    ↓
NotificationMatcher (type + severity + subscriptions + saved-place geofence)
    ↓
DispatchAlertNotificationChunkJob (chunks of 250 matching user IDs)
    ↓
DeliverAlertNotificationJob (per user)
    ↓
NotificationLog persisted + AlertNotificationSent broadcast
    ↓
Frontend toast + inbox refresh
```

### Fan-Out Pipeline

The delivery pipeline uses a three-stage fan-out to scale across large user bases:

1. `DispatchAlertNotifications` listener serializes the alert into a payload and dispatches `FanOutAlertNotificationsJob`.
2. `FanOutAlertNotificationsJob` runs `NotificationMatcher::matchingPreferences()` to identify all matching users, then chunks them into groups of 250 and dispatches `DispatchAlertNotificationChunkJob` per chunk.
3. `DispatchAlertNotificationChunkJob` dispatches one `DeliverAlertNotificationJob` per user ID in its chunk.

This prevents a single large listener from blocking the queue and bounds per-job user counts.

## Database Schema

### `notification_preferences`

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment primary key |
| `user_id` | bigint (FK, unique) | One preference row per user |
| `alert_type` | string | `all`, `emergency`, `transit`, `accessibility` |
| `severity_threshold` | string | `all`, `minor`, `major`, `critical` |
| `subscriptions` | json | Array of normalized subscription URNs |
| `digest_mode` | boolean | Daily digest toggle |
| `push_enabled` | boolean | Master enable/disable toggle |
| `created_at` | timestamp | Creation time |
| `updated_at` | timestamp | Last update time |

### `saved_places`

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment primary key |
| `user_id` | bigint (FK) | Owning user |
| `name` | string | User-friendly location name |
| `lat` | decimal | Latitude |
| `long` | decimal | Longitude |
| `radius` | integer | Match radius in meters |
| `type` | string | Place type (`address`, `poi`, `custom`, `legacy_geofence`) |
| `created_at` | timestamp | Creation time |
| `updated_at` | timestamp | Last update time |

### `notification_logs`

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment primary key |
| `user_id` | bigint (FK) | Owning user |
| `alert_id` | string (nullable) | Alert ID or digest ID |
| `delivery_method` | string | `in_app` or `in_app_digest` |
| `status` | string | `sent`, `processing`, `delivered`, `read`, `dismissed` |
| `sent_at` | timestamp | Delivery timestamp |
| `read_at` | timestamp (nullable) | Read timestamp |
| `dismissed_at` | timestamp (nullable) | Dismiss timestamp |
| `metadata` | json | Alert/digest payload |
| `created_at` | timestamp | Creation time |
| `updated_at` | timestamp | Last update time |

## Matching Engine

`App\Services\Notifications\NotificationMatcher` requires all checks below to pass:

1. Alert type check (`all`/`emergency`/`transit`/`accessibility`)
2. Severity threshold check
3. Subscription intersection check for transit-family sources
4. Saved place geofence distance check (Haversine)

### Geofence Behavior

- If a user has no saved places, geofence check passes.
- If a user has saved places and alert coordinates are missing, geofence check fails.
- Matching uses `distance_km <= radius_meters / 1000`.
- Matching succeeds when any saved place is in range.

### Subscription Behavior

- Empty subscriptions match all alerts.
- Non-transit alerts bypass subscription filtering.
- Transit-family alerts (`transit`, `go_transit`, `ttc_accessibility`) require an intersection between user subscriptions and extractor-derived URNs.
- Note: `ttc_accessibility` is notification-only and not a feed `source` filter.

## Delivery Pipeline

`App\Jobs\DeliverAlertNotificationJob`:

1. Validates user preference + `push_enabled`
2. Creates or reuses `NotificationLog` idempotently
3. Uses optimistic state transition (`sent` → `processing`)
4. Broadcasts `AlertNotificationSent`
5. Marks log `delivered`

## Daily Digest

`App\Jobs\GenerateDailyDigestJob` aggregates prior-day delivered notifications for users with `digest_mode = true` and writes an `in_app_digest` log entry.

## API Endpoints

All endpoints below require authentication.

### Notification preferences

| Method | URI | Description |
|---|---|---|
| `GET` | `/settings/notifications` | Return current user notification preferences |
| `PATCH` | `/settings/notifications` | Update preferences |

`PATCH` supports current fields:
- `alert_type`
- `severity_threshold`
- `subscriptions`
- `digest_mode`
- `push_enabled`

Backwards-compatibility is preserved for legacy clients:
- `geofences` payload is accepted and synchronized into `saved_places` with type `legacy_geofence`
- `subscribed_routes` payload is accepted and normalized into `subscriptions`

### Inbox

| Method | URI | Description |
|---|---|---|
| `GET` | `/notifications/inbox` | List inbox items |
| `PATCH` | `/notifications/inbox/read-all` | Mark all unread items as read |
| `PATCH` | `/notifications/inbox/{id}/read` | Mark one item as read |
| `PATCH` | `/notifications/inbox/{id}/dismiss` | Dismiss one item |
| `DELETE` | `/notifications/inbox` | Clear all undismissed items |

### Saved Places

Saved places are managed via a separate CRUD API. Maximum 20 saved places per user.

| Method | URI | Description |
|---|---|---|
| `GET` | `/api/saved-places` | List the current user's saved places |
| `POST` | `/api/saved-places` | Create a new saved place |
| `PATCH` | `/api/saved-places/{id}` | Update a saved place |
| `DELETE` | `/api/saved-places/{id}` | Delete a saved place |

All saved-place endpoints are ownership-enforced (users can only access their own places).

### Subscription Options

| Method | URI | Description |
|---|---|---|
| `GET` | `/api/subscriptions/options` | Return available routes, lines, stations, and agency for subscription UI |

Response shape:
```json
{
  "data": {
    "agency": { "urn": "agency:ttc", "name": "TTC" },
    "routes": [{ "urn": "route:501", "id": "501", "name": "Queen" }],
    "stations": [{ "urn": "station:union", "slug": "union", "name": "Union Station" }],
    "lines": [{ "urn": "line:yonge-university", "id": "yonge-university", "name": "Yonge-University" }]
  }
}
```

Options are sourced from `config/transit_data.php`.

## Pruning Policy

Notification retention is documented in `docs/backend/maintenance.md`.

## File Reference

### Backend

- `app/Services/Notifications/NotificationMatcher.php`
- `app/Services/Notifications/NotificationAlert.php`
- `app/Services/Notifications/NotificationAlertFactory.php`
- `app/Services/Notifications/AlertContentExtractor.php`
- `app/Services/Notifications/NotificationSeverity.php`
- `app/Listeners/DispatchAlertNotifications.php`
- `app/Jobs/FanOutAlertNotificationsJob.php`
- `app/Jobs/DispatchAlertNotificationChunkJob.php`
- `app/Jobs/DeliverAlertNotificationJob.php`
- `app/Jobs/GenerateDailyDigestJob.php`
- `app/Http/Controllers/Settings/NotificationPreferenceController.php`
- `app/Http/Controllers/Notifications/NotificationInboxController.php`
- `app/Http/Controllers/Notifications/SavedPlaceController.php`
- `app/Http/Controllers/Notifications/SubscriptionOptionsController.php`
- `app/Http/Controllers/Geocoding/LocalGeocodingSearchController.php`
- `app/Services/Geocoding/LocalGeocodingService.php`
- `app/Models/NotificationPreference.php`
- `app/Models/SavedPlace.php`
- `app/Models/NotificationLog.php`
- `app/Models/TorontoAddress.php`
- `app/Models/TorontoPointOfInterest.php`
- `routes/settings.php`

### Frontend

- `resources/js/features/gta-alerts/components/NotificationSettings.tsx`
- `resources/js/features/gta-alerts/components/SubscriptionManager.tsx`
- `resources/js/features/gta-alerts/components/SavedPlacesManager.tsx`
- `resources/js/features/gta-alerts/components/NotificationInboxView.tsx`
- `resources/js/features/gta-alerts/services/NotificationInboxService.ts`

### Tests

- `tests/Feature/Notifications/AlertCreatedMatchingTest.php`
- `tests/Feature/Notifications/NotificationSystemIntegrationTest.php`
- `tests/Feature/Notifications/DeliverAlertNotificationJobTest.php`
- `tests/Feature/Notifications/FanOutAlertNotificationsJobTest.php`
- `tests/Feature/Notifications/GenerateDailyDigestJobTest.php`
- `tests/Feature/Notifications/NotificationInboxControllerTest.php`
- `tests/Feature/Notifications/SavedPlaceControllerTest.php`
- `tests/Feature/Notifications/SavedPlaceLimitTest.php`
- `tests/Feature/Notifications/SubscriptionOptionsControllerTest.php`
- `tests/Feature/Commands/PruneNotificationsCommandTest.php`
- `tests/Feature/Geocoding/LocalGeocodingSearchControllerTest.php`
