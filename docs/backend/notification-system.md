# In-App Notification System

This document describes the architecture, data flow, and API surface for the in-app notification system (Phase 1 MVP).

## Overview

The notification system delivers real-time, user-targeted alerts based on configurable preferences. Users set alert type filters, severity thresholds, geographic geofences, and route subscriptions. When a new alert is created, the system matches it against all active preferences and delivers in-app notifications to qualifying users. A daily digest mode aggregates prior-day notifications for users who prefer batched updates.

**Phase 1 MVP scope:**
- User preference storage (alert type, severity, geofence, routes, digest mode)
- In-app real-time delivery via Laravel Broadcasting
- Geofence matching (Haversine distance)
- Severity-based filtering with ranked thresholds
- Notification center (inbox) with read/dismiss/clear-all
- Daily digest aggregation

## Architecture

The system follows an event-driven pipeline:

```
Alert Source (Fire/Police/Transit/GO Transit fetch command)
    ↓
AlertCreated event (carries NotificationAlert DTO)
    ↓
DispatchAlertNotifications listener
    ↓  (matches preferences via NotificationMatcher)
DeliverAlertNotificationJob (per matching user)
    ↓  (creates NotificationLog, optimistic lock, broadcast)
NotificationLog persisted (status: delivered)
    +
AlertNotificationSent broadcast event
    ↓
Frontend toast + inbox update via Echo/WebSocket
```

**Daily digest flow (scheduled):**

```
GenerateDailyDigestJob (daily schedule)
    ↓  (queries digest_mode preferences)
Aggregate prior-day notification counts per user
    ↓
NotificationLog entry (delivery_method: in_app_digest)
    ↓
Visible in inbox as digest item
```

## Database Schema

### `notification_preferences`

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment primary key |
| `user_id` | bigint (FK, unique) | One preference record per user |
| `alert_type` | string | Filter: `all`, `emergency`, `transit`, `accessibility` |
| `severity_threshold` | string | Minimum severity: `all`, `minor`, `major`, `critical` |
| `geofences` | json | Array of `{name, lat, lng, radius_km}` objects |
| `subscribed_routes` | json | Array of route identifiers (e.g., `["501", "GO-LW"]`) |
| `digest_mode` | boolean | When true, receives daily digest instead of real-time |
| `push_enabled` | boolean | Master toggle for notifications |
| `created_at` | timestamp | Record creation time |
| `updated_at` | timestamp | Last update time |

**Indexes:** `unique(user_id)`

### `notification_logs`

| Column | Type | Description |
|---|---|---|
| `id` | bigint (PK) | Auto-increment primary key |
| `user_id` | bigint (FK) | Owning user |
| `alert_id` | string (nullable) | Alert composite ID (e.g., `police:123`) or digest ID (`digest:2026-02-10`) |
| `delivery_method` | string | `in_app` for real-time, `in_app_digest` for digest entries |
| `status` | string | Lifecycle status (see status transitions below) |
| `sent_at` | timestamp | When the notification was created/sent |
| `read_at` | timestamp (nullable) | When the user read the notification |
| `dismissed_at` | timestamp (nullable) | When the user dismissed the notification |
| `metadata` | json | Source-specific data (source, severity, summary, occurred_at, routes) |
| `created_at` | timestamp | Record creation time |
| `updated_at` | timestamp | Last update time |

**Indexes:** `status`, `sent_at`, `(user_id, status, sent_at)`

## Matching Engine

`App\Services\Notifications\NotificationMatcher` evaluates each active preference against an incoming `NotificationAlert`. All four criteria must pass:

### Alert Type Mapping

| Preference `alert_type` | Matches sources |
|---|---|
| `all` | Any source |
| `emergency` | `fire`, `police` |
| `transit` | `transit`, `go_transit` |
| `accessibility` | Transit sources containing accessibility keywords |

### Severity Ranking

Severities are ranked numerically: `all` (0) < `minor` (1) < `major` (2) < `critical` (3). A preference with threshold `major` matches alerts with severity `major` or `critical`.

### Geofence (Haversine)

Each geofence defines a center point (`lat`, `lng`) and `radius_km`. The matcher calculates great-circle distance using the Haversine formula. If the alert has coordinates and falls within any configured geofence, it matches. An empty geofence array matches all alerts. Alerts without coordinates do not match non-empty geofences.

### Route Subscription

If `subscribed_routes` is non-empty and the alert is a transit source, the alert's route list must intersect the subscribed routes. Non-transit alerts always pass this check. Empty subscribed routes match all alerts.

## Delivery Pipeline

`App\Jobs\DeliverAlertNotificationJob` handles per-user delivery:

1. Verify preference still exists and `push_enabled` is true
2. Validate alert payload (non-empty `alertId` and `source`)
3. `firstOrCreate` a `NotificationLog` (deduplicate by `user_id` + `alert_id` + `delivery_method`)
4. Skip if log already in terminal state (`delivered`, `read`, `dismissed`)
5. Optimistic lock: atomically `UPDATE ... SET status = 'processing' WHERE status = 'sent'`
6. Broadcast `AlertNotificationSent` event
7. Update status to `delivered`
8. On exception: rollback status from `processing` to `sent`, re-throw

### Status Transitions

```
sent → processing → delivered → read → dismissed
                                  ↘ dismissed (via dismiss action)
```

## Daily Digest

`App\Jobs\GenerateDailyDigestJob`:

- **Aggregation window:** Previous day 00:00 UTC to current day 00:00 UTC
- **Eligible users:** Preferences with `digest_mode = true` and `push_enabled = true`
- **Duplicate prevention:** Checks for existing `digest:{date}` log per user before creating
- **Metadata format:**
  ```json
  {
    "type": "daily_digest",
    "digest_date": "2026-02-10",
    "total_notifications": 4,
    "window_start": "2026-02-10T00:00:00+00:00",
    "window_end": "2026-02-11T00:00:00+00:00"
  }
  ```

## Broadcasting

### Channel Authorization

Private channel: `users.{userId}.notifications`

Authorized in `routes/channels.php`:
```php
Broadcast::channel('users.{userId}.notifications', function ($user, int $userId): bool {
    return $user->id === $userId;
});
```

### Event Payload

`AlertNotificationSent` implements `ShouldBroadcastNow`:

- **Channel:** `private-users.{userId}.notifications`
- **Event name:** `alert.notification.sent`
- **Payload:** `alert_id`, `source`, `severity`, `summary`, `sent_at`

## API Endpoints

All notification endpoints require authentication (`auth` middleware).

### Preferences

| Method | URI | Description |
|---|---|---|
| `GET` | `/settings/notifications` | Retrieve current user's notification preferences |
| `PATCH` | `/settings/notifications` | Update notification preferences |

**PATCH payload fields:** `alert_type`, `severity_threshold`, `geofences`, `subscribed_routes`, `digest_mode`, `push_enabled`

### Inbox

| Method | URI | Description |
|---|---|---|
| `GET` | `/notifications/inbox` | List inbox (paginated, excludes dismissed by default) |
| `PATCH` | `/notifications/inbox/{id}/read` | Mark notification as read |
| `PATCH` | `/notifications/inbox/{id}/dismiss` | Dismiss notification |
| `DELETE` | `/notifications/inbox` | Clear all undismissed notifications |

**Inbox query parameters:** `include_dismissed` (boolean), `per_page` (1-100, default 25), `page`

**Inbox response shape:**
```json
{
  "data": [
    {
      "id": 1,
      "alert_id": "police:123",
      "type": "alert",
      "delivery_method": "in_app",
      "status": "delivered",
      "sent_at": "2026-02-11T14:00:00+00:00",
      "read_at": null,
      "dismissed_at": null,
      "metadata": { "source": "police", "severity": "major", "summary": "..." }
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 1, "unread_count": 1 },
  "links": { "next": null, "prev": null }
}
```

## Frontend Integration

- **Toast layer:** `NotificationToast` component listens to Echo private channel and renders real-time alerts
- **Inbox view:** `NotificationInboxView` component with pagination, mark-as-read, dismiss, and clear-all actions
- **Settings UI:** `NotificationSettings` component under the settings view for managing preferences
- **Service:** `NotificationInboxService.ts` handles API calls with pagination URL normalization

## File Reference

### Backend

| Path | Purpose |
|---|---|
| `app/Events/AlertCreated.php` | Event fired when a new alert is ingested |
| `app/Events/AlertNotificationSent.php` | Broadcast event for real-time delivery |
| `app/Listeners/DispatchAlertNotifications.php` | Listener that matches preferences and dispatches jobs |
| `app/Jobs/DeliverAlertNotificationJob.php` | Per-user delivery with optimistic locking |
| `app/Jobs/GenerateDailyDigestJob.php` | Daily digest aggregation |
| `app/Models/NotificationPreference.php` | Preference model with validation rules |
| `app/Models/NotificationLog.php` | Notification log model with scopes |
| `app/Services/Notifications/NotificationAlert.php` | Alert DTO for the notification pipeline |
| `app/Services/Notifications/NotificationAlertFactory.php` | Creates NotificationAlert from source models |
| `app/Services/Notifications/NotificationMatcher.php` | Matching engine (type, severity, geofence, routes) |
| `app/Services/Notifications/NotificationSeverity.php` | Severity ranking and normalization |
| `app/Http/Controllers/Notifications/NotificationInboxController.php` | Inbox API (list, read, dismiss, clear-all) |
| `app/Http/Controllers/Settings/NotificationPreferenceController.php` | Preference API (show, update) |
| `database/migrations/2026_02_10_000001_create_notification_preferences_table.php` | Preferences schema |
| `database/migrations/2026_02_10_000002_create_notification_logs_table.php` | Logs schema |
| `database/factories/NotificationPreferenceFactory.php` | Test factory for preferences |
| `database/factories/NotificationLogFactory.php` | Test factory for logs |
| `routes/channels.php` | Broadcast channel authorization |
| `routes/settings.php` | Notification route definitions |

### Frontend

| Path | Purpose |
|---|---|
| `resources/js/features/gta-alerts/components/NotificationInboxView.tsx` | Inbox UI component |
| `resources/js/features/gta-alerts/components/NotificationSettings.tsx` | Settings UI component |
| `resources/js/features/gta-alerts/components/NotificationToast.tsx` | Real-time toast component |
| `resources/js/features/gta-alerts/services/NotificationInboxService.ts` | Inbox API service |

### Tests

| Path | Purpose |
|---|---|
| `tests/Feature/Notifications/NotificationSystemIntegrationTest.php` | End-to-end system integration (3 tests) |
| `tests/Feature/Notifications/AlertCreatedMatchingTest.php` | Matching engine integration |
| `tests/Feature/Notifications/DeliverAlertNotificationJobTest.php` | Delivery job behavior |
| `tests/Feature/Notifications/GenerateDailyDigestJobTest.php` | Digest aggregation |
| `tests/Feature/Notifications/NotificationInboxControllerTest.php` | Inbox API |
