# Database Schema Reference

This document is the canonical schema reference for the GTA Alerts backend. It covers every table, column, constraint, and index as defined by the migration history.

For query patterns that run across these tables, see [unified-alerts-system.md](unified-alerts-system.md). For notification system API and matching logic, see [notification-system.md](notification-system.md). For pruning schedules, see [maintenance.md](maintenance.md).

---

## Database Environments

| Environment | Driver | Notes |
|---|---|---|
| Production | PostgreSQL | Laravel Forge on Hetzner. GIN full-text indexes active. |
| Local dev | PostgreSQL or MySQL | MySQL FULLTEXT indexes conditionally applied. |
| Test suite | SQLite (in-memory) | Full-text search degrades to outer-query `LIKE` fallback. |

The codebase handles driver differences in two places:

1. **Full-text search** — providers branch on `DB::getDriverName()` to emit the correct FTS predicate per driver (see [Full-Text Search Architecture](#full-text-search-architecture)).
2. **String concatenation** — providers use `||` (SQLite/PostgreSQL) or `CONCAT()` (MySQL) when building the unified `id` column.

---

## Entity-Relationship Summary

```
users
 ├── hasOne  notification_preferences
 ├── hasMany notification_logs
 ├── hasMany saved_places
 └── hasMany saved_alerts

fire_incidents
 └── hasMany incident_updates  (via event_num FK)

incident_updates
 └── belongsTo users (created_by, nullable)

notification_preferences
 └── belongsTo users

notification_logs
 └── belongsTo users

saved_places
 └── belongsTo users

saved_alerts
 └── belongsTo users

toronto_addresses      (geocoding lookup table, no FK relations)
toronto_pois           (geocoding lookup table, no FK relations)
gta_postal_codes       (postal code reference table, no FK relations)
weather_caches         (durable weather cache, no FK relations)

-- Alert source tables (no FK relations) --
fire_incidents
police_calls
transit_alerts
go_transit_alerts
miway_alerts
yrt_alerts

-- Laravel infrastructure (no application-level relations) --
sessions
cache / cache_locks
jobs / job_batches / failed_jobs
password_reset_tokens
```

ASCII relationship diagram (abbreviated):

```
┌─────────────────────────────────────────────────────────────────┐
│                            users                                │
│  id · name · email · password · two_factor_* · remember_token  │
└──────┬───────────────┬────────────────────┬────────────────────┘
       │ 1:1           │ 1:N                │ 1:N
       ▼               ▼                    ▼
notification_   notification_logs      saved_places
preferences     user_id · alert_id     user_id · lat · long
user_id         delivery_method        radius · type
alert_type      status · sent_at
subscriptions   read_at · dismissed_at

┌──────────────────────────────┐
│       fire_incidents         │
│  id · event_num (unique)     │
│  event_type · prime_street   │
│  dispatch_time · is_active   │
└────────────┬─────────────────┘
             │ 1:N (event_num FK)
             ▼
       incident_updates
       event_num · update_type
       content · source · created_by

┌──────────────────┐  ┌───────────────────┐
│  police_calls    │  │  transit_alerts   │
│  object_id       │  │  external_id      │
│  call_type       │  │  source_feed      │
│  occurrence_time │  │  route_type       │
└──────────────────┘  └───────────────────┘

┌────────────────────────────────┐  ┌───────────────────────────────┐
│       go_transit_alerts        │  │        miway_alerts           │
│  external_id · alert_type      │  │  external_id · header_text    │
│  service_mode · posted_at      │  │  cause · effect · starts_at   │
└────────────────────────────────┘  └───────────────────────────────┘

┌───────────────────────────────┐
│        yrt_alerts             │
│  external_id · title          │
│  posted_at · route_text       │
│  body_text · is_active        │
└───────────────────────────────┘
```

---

## Table Reference

Tables are grouped by functional area. Within each group they appear in migration order.

---

### Core Infrastructure Tables

#### `users`

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | Primary key |
| `name` | varchar(255) | No | | Display name |
| `email` | varchar(255) | No | | Unique |
| `email_verified_at` | timestamp | Yes | NULL | |
| `password` | varchar(255) | No | | Bcrypt hash |
| `two_factor_secret` | text | Yes | NULL | Encrypted TOTP secret |
| `two_factor_recovery_codes` | text | Yes | NULL | Encrypted recovery codes |
| `two_factor_confirmed_at` | timestamp | Yes | NULL | Set when 2FA is confirmed |
| `remember_token` | varchar(100) | Yes | NULL | "Remember me" session token |
| `created_at` | timestamp | Yes | NULL | |
| `updated_at` | timestamp | Yes | NULL | |

**Indexes:** unique on `email`.

**Model:** `App\Models\User`

**Relations:** `hasOne NotificationPreference`, `hasMany NotificationLog`, `hasMany SavedPlace`

---

#### `password_reset_tokens`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `email` | varchar(255) | No | Primary key |
| `token` | varchar(255) | No | Hashed token |
| `created_at` | timestamp | Yes | |

No model — managed by Laravel Fortify.

---

#### `sessions`

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | varchar(255) | No | Primary key |
| `user_id` | bigint unsigned | Yes | Indexed (guest sessions have NULL) |
| `ip_address` | varchar(45) | Yes | IPv4 or IPv6 |
| `user_agent` | text | Yes | |
| `payload` | longtext | No | Serialized session data |
| `last_activity` | integer | No | Unix timestamp, indexed |

No model — managed by Laravel session driver.

---

#### `cache` and `cache_locks`

**`cache`**

| Column | Type | Notes |
|---|---|---|
| `key` | varchar(255) | Primary key |
| `value` | mediumtext | Serialized value |
| `expiration` | integer | Unix timestamp, indexed |

**`cache_locks`**

| Column | Type | Notes |
|---|---|---|
| `key` | varchar(255) | Primary key |
| `owner` | varchar(255) | Lock owner token |
| `expiration` | integer | Unix timestamp, indexed |

No models — managed by Laravel cache driver.

---

#### `jobs`, `job_batches`, and `failed_jobs`

**`jobs`**

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint unsigned | No | Primary key |
| `queue` | varchar(255) | No | Indexed |
| `payload` | longtext | No | Serialized job |
| `attempts` | tinyint unsigned | No | |
| `reserved_at` | integer unsigned | Yes | Unix timestamp |
| `available_at` | integer unsigned | No | Unix timestamp |
| `created_at` | integer unsigned | No | Unix timestamp |

**`job_batches`**

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | varchar(255) | No | Primary key (string UUID) |
| `name` | varchar(255) | No | |
| `total_jobs` | integer | No | |
| `pending_jobs` | integer | No | |
| `failed_jobs` | integer | No | |
| `failed_job_ids` | longtext | No | JSON array |
| `options` | mediumtext | Yes | |
| `cancelled_at` | integer | Yes | Unix timestamp |
| `created_at` | integer | No | Unix timestamp |
| `finished_at` | integer | Yes | Unix timestamp |

**`failed_jobs`**

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint unsigned | No | Primary key |
| `uuid` | varchar(255) | No | Unique |
| `connection` | text | No | Queue connection name |
| `queue` | text | No | Queue name |
| `payload` | longtext | No | Serialized job |
| `exception` | longtext | No | Stack trace |
| `failed_at` | timestamp | No | Defaults to current time |

Pruning policy: entries older than 7 days are deleted by `queue:prune-failed --hours=168`, scheduled daily. See [maintenance.md](maintenance.md).

---

### Alert Source Tables

All six alert tables share the same structural conventions:

- `id` — bigint auto-increment primary key
- A natural-key column with a `UNIQUE` index (the upsert key used by fetch commands)
- `is_active` — boolean flag; fetch commands set `false` for records absent from the latest feed response
- `feed_updated_at` — timestamp of the last feed sync that touched this row
- `created_at` / `updated_at` — standard Laravel timestamps
- A composite index on `(is_active, <primary_timestamp>)` for feed filtering

---

#### `fire_incidents`

Backing table for Toronto Fire CAD data. Upserted by `FetchFireIncidentsCommand` via `event_num`.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | Primary key |
| `event_num` | varchar(255) | No | | Natural key from Toronto Fire CAD; unique |
| `event_type` | varchar(255) | No | | Incident classification |
| `prime_street` | varchar(255) | Yes | NULL | Primary address street |
| `cross_streets` | varchar(255) | Yes | NULL | Intersection description |
| `dispatch_time` | datetime | No | | Time incident was dispatched (no timezone in column type; stored as UTC) |
| `alarm_level` | tinyint unsigned | No | 0 | Alarm level 0–5 |
| `beat` | varchar(255) | Yes | NULL | Dispatch zone identifier |
| `units_dispatched` | text | Yes | NULL | Unit identifiers (comma-separated string); widened from varchar(255) to text in migration `2026_03_06_120000` to accommodate long live-feed payloads |
| `is_active` | boolean | No | true | False when absent from feed |
| `feed_updated_at` | timestamp | Yes | NULL | Last feed sync timestamp |
| `created_at` | timestamp | Yes | NULL | |
| `updated_at` | timestamp | Yes | NULL | |

**Indexes:**

| Name | Columns | Type |
|---|---|---|
| (unique) | `event_num` | Unique |
| `fire_incidents_is_active_dispatch_time_index` | `(is_active, dispatch_time)` | B-tree |
| `fire_incidents_event_type_index` | `event_type` | B-tree |
| `fire_incidents_feed_updated_at_index` | `feed_updated_at` | B-tree |
| `fire_incidents_fulltext` | `(event_type, prime_street, cross_streets)` | GIN (PostgreSQL) / FULLTEXT (MySQL) |

**Model:** `App\Models\FireIncident`

**Scopes:** `active()` — filters `is_active = true`

**Relations:** `hasMany IncidentUpdate` (via `event_num`)

**Source docs:** [sources/toronto-fire.md](../sources/toronto-fire.md)

---

#### `police_calls`

Backing table for Toronto Police Service ArcGIS FeatureServer data. Upserted by `FetchPoliceCallsCommand` via `object_id`.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | Primary key |
| `object_id` | bigint unsigned | No | | ArcGIS FeatureServer object ID; unique |
| `call_type_code` | varchar(255) | No | | Short code (e.g., `52Z`); indexed |
| `call_type` | varchar(255) | No | | Human-readable description |
| `division` | varchar(255) | Yes | NULL | Police division (e.g., `D52`); indexed |
| `cross_streets` | varchar(255) | Yes | NULL | Intersection description |
| `latitude` | decimal(10,7) | Yes | NULL | |
| `longitude` | decimal(10,7) | Yes | NULL | |
| `occurrence_time` | datetime | No | | Time of occurrence (stored as UTC) |
| `is_active` | boolean | No | true | False when absent from feed |
| `feed_updated_at` | timestamp | Yes | NULL | Last feed sync timestamp |
| `created_at` | timestamp | Yes | NULL | |
| `updated_at` | timestamp | Yes | NULL | |

**Indexes:**

| Name | Columns | Type |
|---|---|---|
| (unique) | `object_id` | Unique |
| `police_calls_call_type_code_index` | `call_type_code` | B-tree |
| `police_calls_division_index` | `division` | B-tree |
| `police_calls_is_active_occurrence_time_index` | `(is_active, occurrence_time)` | B-tree |
| `police_calls_fulltext` | `(call_type, cross_streets)` | GIN (PostgreSQL) / FULLTEXT (MySQL) |

**Model:** `App\Models\PoliceCall`

**Scopes:** `active()` — filters `is_active = true`

**Source docs:** [sources/toronto-police.md](../sources/toronto-police.md)

---

#### `transit_alerts`

Backing table for TTC Transit composite feed (live API + SXA + static CMS). Upserted by `FetchTransitAlertsCommand` via `external_id`.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | Primary key |
| `external_id` | varchar(255) | No | | Prefixed by source_feed (e.g., `api:12345`, `sxa:67890`); unique |
| `source_feed` | varchar(255) | No | | `api`, `sxa`, or `static`; indexed |
| `alert_type` | varchar(255) | Yes | NULL | Alert classification |
| `route_type` | varchar(255) | Yes | NULL | `subway`, `bus`, or `streetcar`; indexed |
| `route` | varchar(255) | Yes | NULL | Route identifier or name |
| `title` | varchar(255) | No | | Alert headline |
| `description` | mediumtext | Yes | NULL | Full alert body |
| `severity` | varchar(255) | Yes | NULL | |
| `effect` | varchar(255) | Yes | NULL | Service effect description |
| `cause` | varchar(255) | Yes | NULL | Cause of disruption |
| `active_period_start` | datetime | Yes | NULL | Start of active window (stored as UTC) |
| `active_period_end` | datetime | Yes | NULL | End of active window (stored as UTC) |
| `direction` | varchar(255) | Yes | NULL | Affected direction |
| `stop_start` | varchar(255) | Yes | NULL | First affected stop |
| `stop_end` | varchar(255) | Yes | NULL | Last affected stop |
| `url` | varchar(255) | Yes | NULL | Source URL |
| `is_active` | boolean | No | true | False when absent from feed |
| `feed_updated_at` | timestamp | Yes | NULL | Last feed sync timestamp; indexed |
| `created_at` | timestamp | Yes | NULL | |
| `updated_at` | timestamp | Yes | NULL | |

**Indexes:**

| Name | Columns | Type |
|---|---|---|
| (unique) | `external_id` | Unique |
| `transit_alerts_source_feed_index` | `source_feed` | B-tree |
| `transit_alerts_route_type_index` | `route_type` | B-tree |
| `transit_alerts_is_active_active_period_start_index` | `(is_active, active_period_start)` | B-tree |
| `transit_alerts_feed_updated_at_index` | `feed_updated_at` | B-tree |
| `transit_alerts_fulltext` | `(title, description, stop_start, stop_end, route, route_type)` | GIN (PostgreSQL) / FULLTEXT (MySQL) |

**Model:** `App\Models\TransitAlert`

**Scopes:** `active()` — filters `is_active = true`

**Source docs:** [sources/ttc-transit.md](../sources/ttc-transit.md)

---

#### `go_transit_alerts`

Backing table for Metrolinx GO Transit service updates. Upserted by `FetchGoTransitAlertsCommand` via `external_id`.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | Primary key |
| `external_id` | varchar(255) | No | | Composite key from Metrolinx API; unique |
| `alert_type` | varchar(255) | No | | `Train`, `Bus`, or `Station`; indexed |
| `service_mode` | varchar(255) | No | | `Train`, `Bus`, or `Station`; indexed |
| `corridor_or_route` | varchar(255) | No | | Line or route name |
| `corridor_code` | varchar(255) | Yes | NULL | Short line code (e.g., `LW`) |
| `sub_category` | varchar(255) | Yes | NULL | Sub-classification of alert |
| `message_subject` | varchar(255) | No | | Alert headline |
| `message_body` | text | Yes | NULL | Alert detail |
| `direction` | varchar(255) | Yes | NULL | Travel direction |
| `trip_number` | varchar(255) | Yes | NULL | Specific train or bus trip |
| `delay_duration` | varchar(255) | Yes | NULL | Human-readable delay string |
| `status` | varchar(255) | Yes | NULL | Alert status from Metrolinx |
| `line_colour` | varchar(255) | Yes | NULL | Hex colour for UI theming |
| `posted_at` | datetime | No | | When alert was posted (stored as UTC) |
| `is_active` | boolean | No | true | False when absent from feed |
| `feed_updated_at` | timestamp | Yes | NULL | Last feed sync timestamp; indexed |
| `created_at` | timestamp | Yes | NULL | |
| `updated_at` | timestamp | Yes | NULL | |

**Indexes:**

| Name | Columns | Type |
|---|---|---|
| (unique) | `external_id` | Unique |
| `go_transit_alerts_alert_type_index` | `alert_type` | B-tree |
| `go_transit_alerts_service_mode_index` | `service_mode` | B-tree |
| `go_transit_alerts_is_active_posted_at_index` | `(is_active, posted_at)` | B-tree |
| `go_transit_alerts_feed_updated_at_index` | `feed_updated_at` | B-tree |
| `go_transit_alerts_fulltext` | `(message_subject, message_body, corridor_or_route, corridor_code, service_mode)` | GIN (PostgreSQL) / FULLTEXT (MySQL) |

**Model:** `App\Models\GoTransitAlert`

**Scopes:** `active()` — filters `is_active = true`

**Source docs:** [sources/go-transit.md](../sources/go-transit.md)

---

#### `miway_alerts`

Backing table for MiWay (Mississauga Transit) GTFS-RT service alerts. Upserted by `FetchMiwayAlertsCommand` via `external_id`.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | Primary key |
| `external_id` | varchar(255) | No | | GTFS-RT alert entity ID; unique |
| `header_text` | text | No | | Short alert headline |
| `description_text` | text | Yes | NULL | Full alert body |
| `cause` | varchar(255) | Yes | NULL | GTFS-RT cause string (e.g., `CONSTRUCTION`) |
| `effect` | varchar(255) | Yes | NULL | GTFS-RT effect string (e.g., `DETOUR`) |
| `starts_at` | timestamp | Yes | NULL | Alert active-period start; `NULL` means unknown/ongoing |
| `ends_at` | timestamp | Yes | NULL | Alert active-period end; `NULL` means open-ended |
| `url` | varchar(255) | Yes | NULL | Source URL |
| `detour_pdf_url` | varchar(255) | Yes | NULL | Link to detour PDF when available |
| `is_active` | boolean | No | true | False when absent from feed |
| `feed_updated_at` | timestamp | No | | Timestamp of the feed response that last touched this row |
| `created_at` | timestamp | Yes | NULL | |
| `updated_at` | timestamp | Yes | NULL | |

**Indexes:**

| Name | Columns | Type |
|---|---|---|
| (unique) | `external_id` | Unique |
| `miway_alerts_is_active_index` | `is_active` | B-tree |
| `miway_alerts_fulltext` | `(header_text, description_text)` | FULLTEXT (MySQL/MariaDB only) |

**Notes on full-text search:** The MySQL FULLTEXT index is applied by migration `2026_03_31_082123_add_fulltext_index_to_miway_alerts_table.php` (MySQL/MariaDB only; no-op on other drivers). Unlike the other four alert tables, `miway_alerts` does not yet have a dedicated PostgreSQL GIN index migration — the provider falls back to `ILIKE` on PostgreSQL for the `q` search parameter.

**Model:** `App\Models\MiwayAlert`

**Provider:** `App\Services\Alerts\Providers\MiwayAlertSelectProvider`

**Timestamp note:** The unified query uses `COALESCE(starts_at, created_at)` as the `timestamp` column, since `starts_at` may be NULL for alerts with no defined start time.

**Source docs:** [sources/miway.md](../sources/miway.md)

---

#### `yrt_alerts`

Backing table for YRT (York Region Transit) service advisories. Upserted by `FetchYrtAlertsCommand` via `external_id`.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | Primary key |
| `external_id` | varchar(255) | No | | URL slug from details_url; unique |
| `title` | text | No | | Advisory headline |
| `posted_at` | datetime | No | | When advisory was posted (stored as UTC) |
| `details_url` | varchar(255) | No | | Absolute URL to advisory detail page |
| `description_excerpt` | text | Yes | NULL | Normalized whitespace feed description |
| `route_text` | varchar(255) | Yes | NULL | Best-effort route derivation |
| `body_text` | text | Yes | NULL | Full advisory body from detail HTML |
| `list_hash` | varchar(40) | No | | SHA-1 of stable list fields |
| `details_fetched_at` | timestamp | Yes | NULL | Timestamp of last successful detail fetch |
| `is_active` | boolean | No | true | False when absent from feed |
| `feed_updated_at` | timestamp | Yes | NULL | Last feed sync timestamp; indexed |
| `created_at` | timestamp | Yes | NULL | |
| `updated_at` | timestamp | Yes | NULL | |

**Indexes:**

| Name | Columns | Type |
|---|---|---|
| (unique) | `external_id` | Unique |
| `yrt_alerts_posted_at_index` | `posted_at` | B-tree |
| `yrt_alerts_feed_updated_at_index` | `feed_updated_at` | B-tree |
| `yrt_alerts_is_active_posted_at_index` | `(is_active, posted_at)` | B-tree |

**Notes on full-text search:** `yrt_alerts` does not have a dedicated FULLTEXT or GIN index migration. The provider falls back to `ILIKE` on PostgreSQL and `LIKE` on MySQL for the `q` search parameter.

**Model:** `App\Models\YrtAlert`

**Provider:** `App\Services\Alerts\Providers\YrtAlertSelectProvider`

**Timestamp note:** The unified query uses `posted_at` as the `timestamp` column.

**Source docs:** [sources/yrt.md](../sources/yrt.md)

---

### Notification System Tables

Full notification system architecture, matching logic, and API documentation: [notification-system.md](notification-system.md).

---

#### `notification_preferences`

One row per user. Created lazily on first access.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | Primary key |
| `user_id` | bigint unsigned | No | | FK → `users.id`, cascade delete; unique |
| `alert_type` | varchar(255) | No | `all` | `all`, `emergency`, `transit`, `accessibility` |
| `severity_threshold` | varchar(255) | No | `all` | `all`, `minor`, `major`, `critical` |
| `subscriptions` | json | Yes | NULL | Array of normalized subscription URNs (`route:*`, `line:*`, `station:*`, `agency:*`) |
| `digest_mode` | boolean | No | false | Daily digest mode toggle |
| `push_enabled` | boolean | No | true | Master push notification toggle |
| `created_at` | timestamp | Yes | NULL | |
| `updated_at` | timestamp | Yes | NULL | |

**Indexes:** unique on `user_id`.

**Model:** `App\Models\NotificationPreference`

**Relations:** `belongsTo User`

**Migration history note:** The original schema had separate `geofences` (JSON) and `subscribed_routes` (JSON) columns. `geofences` was dropped in migration `2026_02_12_000001` with data migrated to `saved_places`. `subscribed_routes` was renamed to `subscriptions` in migration `2026_02_12_000005`. The API preserves backwards compatibility: legacy `geofences` and `subscribed_routes` payloads are accepted and normalized on write.

---

#### `notification_logs`

One row per delivered notification. Supports inbox read/dismiss state.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | Primary key |
| `user_id` | bigint unsigned | No | | FK → `users.id`, cascade delete |
| `alert_id` | varchar(255) | Yes | NULL | Composite alert ID (`source:externalId`) or digest identifier |
| `delivery_method` | varchar(255) | No | `in_app` | `in_app` or `in_app_digest` |
| `status` | varchar(255) | No | `sent` | `sent`, `processing`, `delivered`, `read`, `dismissed`; indexed |
| `sent_at` | timestamp | No | | Delivery timestamp; indexed |
| `read_at` | timestamp | Yes | NULL | Set when user reads the notification |
| `dismissed_at` | timestamp | Yes | NULL | Set when user dismisses the notification |
| `metadata` | json | No | | Alert or digest payload |
| `created_at` | timestamp | Yes | NULL | |
| `updated_at` | timestamp | Yes | NULL | |

**Indexes:**

| Name | Columns | Type |
|---|---|---|
| `notification_logs_status_index` | `status` | B-tree |
| `notification_logs_sent_at_index` | `sent_at` | B-tree |
| `notification_logs_user_id_status_sent_at_index` | `(user_id, status, sent_at)` | B-tree |

The standalone `user_id` index created by the FK was explicitly dropped in migration `2026_02_10_000003` because it is redundant with the composite index above.

**Model:** `App\Models\NotificationLog`

**Relations:** `belongsTo User`

**Scopes:** `unread()` — `read_at IS NULL`; `undismissed()` — `dismissed_at IS NULL`

**Pruning policy:** Records with `sent_at` older than 30 days are deleted by `notifications:prune`, scheduled daily. See [maintenance.md](maintenance.md).

---

#### `saved_alerts`

User-pinned alerts for the Saved Alerts feature. Each row records a composite alert ID (`source:externalId`) bookmarked by a user. Maximum uncapped for authenticated users.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | Primary key |
| `user_id` | bigint unsigned | No | | FK → `users.id`, cascade delete |
| `alert_id` | varchar(120) | No | | Composite alert ID in `source:externalId` format |
| `created_at` | timestamp | Yes | NULL | |
| `updated_at` | timestamp | Yes | NULL | |

**Indexes:**

| Name | Columns | Type |
|---|---|---|
| (unique) | `(user_id, alert_id)` | Unique |
| `saved_alerts_user_id_id_index` | `(user_id, id)` | B-tree |

**Model:** `App\Models\SavedAlert`

**Relations:** `belongsTo User`

**Full documentation:** [saved-alerts.md](saved-alerts.md)

---

### Geographic and Location Tables

---

#### `saved_places`

User-defined locations used for geofence matching in the notification system. Maximum 20 places per user.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | Primary key |
| `user_id` | bigint unsigned | No | | FK → `users.id`, cascade delete; indexed |
| `name` | varchar(120) | No | | User-defined label |
| `lat` | decimal(10,7) | No | | Latitude |
| `long` | decimal(10,7) | No | | Longitude |
| `radius` | integer unsigned | No | 500 | Match radius in meters |
| `type` | varchar(32) | No | `address` | `address`, `poi`, `manual`, `legacy_geofence` |
| `created_at` | timestamp | Yes | NULL | |
| `updated_at` | timestamp | Yes | NULL | |

**Indexes:**

| Name | Columns | Type |
|---|---|---|
| `saved_places_user_id_index` | `user_id` | B-tree |
| `saved_places_user_id_type_index` | `(user_id, type)` | B-tree |

**Model:** `App\Models\SavedPlace`

**Relations:** `belongsTo User`

**Note:** `legacy_geofence` type records are migrated from the former `notification_preferences.geofences` JSON column.

---

#### `toronto_addresses`

Geocoding lookup table for local address search. Populated from a static Toronto open-data address dataset.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint unsigned | No | Primary key |
| `street_num` | varchar(32) | Yes | Street number (nullable for range-only records) |
| `street_name` | varchar(160) | No | Full street name |
| `lat` | decimal(10,7) | No | |
| `long` | decimal(10,7) | No | |
| `zip` | varchar(16) | Yes | Postal code |
| `created_at` | timestamp | Yes | |
| `updated_at` | timestamp | Yes | |

**Indexes:**

| Name | Columns |
|---|---|
| `toronto_addresses_street_name_index` | `street_name` |
| `toronto_addresses_street_num_street_name_index` | `(street_num, street_name)` |
| `toronto_addresses_zip_index` | `zip` |

**Model:** `App\Models\TorontoAddress`

No application-level FK relations. Used by `App\Services\Geocoding\LocalGeocodingService`.

---

#### `toronto_pois`

Geocoding lookup table for points of interest search. Populated from a static dataset.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint unsigned | No | Primary key |
| `name` | varchar(180) | No | POI display name |
| `category` | varchar(120) | Yes | Category classification |
| `lat` | decimal(10,7) | No | |
| `long` | decimal(10,7) | No | |
| `created_at` | timestamp | Yes | |
| `updated_at` | timestamp | Yes | |

**Indexes:** `name`, `category` (each a separate B-tree index).

**Model:** `App\Models\TorontoPointOfInterest` (table: `toronto_pois`)

No application-level FK relations. Used by `App\Services\Geocoding\LocalGeocodingService`.

---

#### `gta_postal_codes`

Reference table containing GTA postal code FSAs with centroid coordinates. Used by the weather feature for FSA lookup and nearest-FSA resolution.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `fsa` | varchar(3) | No | Primary key; 3-character Forward Sortation Area (e.g., "M5V") |
| `municipality` | varchar(255) | No | City or municipality name |
| `neighbourhood` | varchar(255) | Yes | Neighbourhood or area name |
| `lat` | decimal(10,7) | No | Centroid latitude |
| `lng` | decimal(10,7) | No | Centroid longitude |
| `created_at` | timestamp | Yes | |
| `updated_at` | timestamp | Yes | |

No application-level FK relations.

**Model:** `App\Models\GtaPostalCode`

**Key methods:** `normalize(string $input): string` — normalizes any postal code format to FSA; `search(string $query): Builder` — searches by FSA, municipality, or neighbourhood; `nearestFsa(float $lat, float $lng): ?static` — finds closest FSA by squared Euclidean distance.

---

#### `weather_caches`

Durable database cache for weather payloads. Acts as the second layer of the three-tier weather caching strategy.

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigint unsigned | No | Primary key |
| `fsa` | varchar(255) | No | FSA identifier |
| `provider` | varchar(255) | No | Provider name (e.g., `environment_canada`) |
| `payload` | json | No | Serialized `WeatherData` fields |
| `fetched_at` | datetime | No | Cache timestamp |
| `created_at` | timestamp | Yes | |
| `updated_at` | timestamp | Yes | |

**TTL:** 30 minutes (`fetched_at > now() - 30min`).

No application-level FK relations.

**Model:** `App\Models\WeatherCache`

**Full documentation:** [weather.md](weather.md)

---

### Scene Intel Tables

Scene Intel provides a structured update timeline for fire incidents. Full documentation: [scene-intel.md](scene-intel.md).

---

#### `incident_updates`

Update events attached to a fire incident, indexed by `event_num`.

| Column | Type | Nullable | Default | Notes |
|---|---|---|---|---|
| `id` | bigint unsigned | No | auto | Primary key |
| `event_num` | varchar(255) | No | | FK → `fire_incidents.event_num`, cascade delete |
| `update_type` | varchar(50) | No | | `milestone`, `resource_status`, `alarm_change`, `phase_change`, `manual_note`; cast to `IncidentUpdateType` enum |
| `content` | text | No | | Human-readable update text |
| `metadata` | json | Yes | NULL | Structured supplementary data |
| `source` | varchar(50) | No | `synthetic` | Origin: `synthetic` (system-generated) or a user/admin identifier |
| `created_by` | bigint unsigned | Yes | NULL | FK → `users.id`, null on delete; NULL for system-generated updates |
| `created_at` | timestamp | Yes | NULL | |
| `updated_at` | timestamp | Yes | NULL | |

**Indexes:**

| Name | Columns | Type |
|---|---|---|
| `incident_updates_event_num_created_at_index` | `(event_num, created_at)` | B-tree |
| `incident_updates_update_type_index` | `update_type` | B-tree |
| `incident_updates_created_at_index` | `created_at` | B-tree |

**Model:** `App\Models\IncidentUpdate`

**Relations:** `belongsTo FireIncident` (via `event_num`), `belongsTo User` (via `created_by`, nullable)

**Enum cast:** `update_type` is cast to `App\Enums\IncidentUpdateType`. See [enums.md](enums.md).

**Pruning policy:** Records with `created_at` older than 90 days are deleted by `model:prune` for `IncidentUpdate`, scheduled daily. See [maintenance.md](maintenance.md).

---

## Full-Text Search Architecture

The `q` search parameter in the unified feed is handled at the provider layer with driver-specific implementations. For the full query flow, see [unified-alerts-system.md](unified-alerts-system.md#search-performance-cross-driver-full-text-search).

### PostgreSQL (production)

GIN indexes on computed `tsvector` expressions. Applied by migration `2026_02_26_000001_add_pgsql_fulltext_indexes_to_alert_tables.php`. Created with `CREATE INDEX CONCURRENTLY` so `$withinTransaction = false` is set on that migration.

Each index covers a concatenated tsvector across the columns listed below:

| Table | Index name | Indexed columns |
|---|---|---|
| `fire_incidents` | `fire_incidents_fulltext` | `event_type`, `prime_street`, `cross_streets` |
| `police_calls` | `police_calls_fulltext` | `call_type`, `cross_streets` |
| `transit_alerts` | `transit_alerts_fulltext` | `title`, `description`, `stop_start`, `stop_end`, `route`, `route_type` |
| `go_transit_alerts` | `go_transit_alerts_fulltext` | `message_subject`, `message_body`, `corridor_or_route`, `corridor_code`, `service_mode` |

**Note:** `miway_alerts` and `yrt_alerts` do not have GIN index migrations for PostgreSQL. Their providers fall back to `ILIKE` when the driver is PostgreSQL.

Query expression used by providers:

```sql
to_tsvector('simple', coalesce(col1, '') || ' ' || coalesce(col2, '') || ...)
    @@ plainto_tsquery('simple', ?)
```

An `ILIKE` substring predicate is applied **in addition** to the FTS condition to preserve partial-match UX.

### MySQL (local/dev)

FULLTEXT indexes on the same column sets. Applied by migration `2026_02_19_120000_add_fulltext_indexes_to_alert_tables.php` (MySQL/MariaDB only — migration no-ops on other drivers).

Providers use `MATCH (...) AGAINST (? IN NATURAL LANGUAGE MODE)` plus `LIKE` fallback predicates.

### SQLite (test suite)

No FTS indexes. The outer query in `UnifiedAlertsQuery` applies a case-insensitive `LIKE` over the unified `title` and `location_name` columns only.

---

## Data Lifecycle and Pruning Policies

| Table | Pruning condition | Retention | Command | Schedule |
|---|---|---|---|---|
| `notification_logs` | `sent_at < now() - 30 days` | 30 days | `notifications:prune` | Daily 00:00 |
| `incident_updates` | `created_at < now() - 90 days` | 90 days | `model:prune --model=App\Models\IncidentUpdate` | Daily 00:00 |
| `failed_jobs` | `failed_at < now() - 7 days` | 7 days | `queue:prune-failed --hours=168` | Daily 00:00 |

All other tables have no automated pruning. Alert source tables (`fire_incidents`, `police_calls`, `transit_alerts`, `go_transit_alerts`, `miway_alerts`, `yrt_alerts`) are append-only; rows are deactivated (soft-flagged) rather than deleted when they disappear from the feed.

Full policy detail and scheduler verification: [maintenance.md](maintenance.md).

---

## Migration History

All migrations in chronological run order:

| Migration file | Description |
|---|---|
| `0001_01_01_000000_create_users_table.php` | Creates `users`, `password_reset_tokens`, `sessions` |
| `0001_01_01_000001_create_cache_table.php` | Creates `cache`, `cache_locks` |
| `0001_01_01_000002_create_jobs_table.php` | Creates `jobs`, `job_batches`, `failed_jobs` |
| `2025_08_14_170933_add_two_factor_columns_to_users_table.php` | Adds `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` to `users` |
| `2026_01_31_185634_create_fire_incidents_table.php` | Creates `fire_incidents` |
| `2026_01_31_204856_create_police_calls_table.php` | Creates `police_calls` |
| `2026_02_02_044111_add_feed_updated_at_index_to_fire_incidents_table.php` | Adds index on `fire_incidents.feed_updated_at` |
| `2026_02_05_203656_create_transit_alerts_table.php` | Creates `transit_alerts` |
| `2026_02_05_233653_create_go_transit_alerts_table.php` | Creates `go_transit_alerts` |
| `2026_02_10_000001_create_notification_preferences_table.php` | Creates `notification_preferences` (original schema with `geofences` + `subscribed_routes`) |
| `2026_02_10_000002_create_notification_logs_table.php` | Creates `notification_logs` |
| `2026_02_10_000003_drop_redundant_user_id_index_from_notification_logs_table.php` | Drops standalone `user_id` index made redundant by composite index |
| `2026_02_12_000001_drop_geofences_from_notification_preferences_table.php` | Creates `saved_places`, migrates geofence data from `notification_preferences.geofences`, drops `geofences` column |
| `2026_02_12_000002_create_saved_places_table.php` | Creates `saved_places` if it does not exist (idempotent guard) |
| `2026_02_12_000003_create_toronto_addresses_table.php` | Creates `toronto_addresses` |
| `2026_02_12_000004_create_toronto_pois_table.php` | Creates `toronto_pois` |
| `2026_02_12_000005_rename_subscribed_routes_to_subscriptions_in_notification_preferences_table.php` | Renames `subscribed_routes` → `subscriptions`, migrates data |
| `2026_02_13_000001_create_incident_updates_table.php` | Creates `incident_updates` |
| `2026_02_19_120000_add_fulltext_indexes_to_alert_tables.php` | Adds MySQL FULLTEXT indexes to all four alert tables (no-op on other drivers) |
| `2026_02_26_000001_add_pgsql_fulltext_indexes_to_alert_tables.php` | Adds PostgreSQL GIN indexes to all four alert tables (no-op on other drivers; runs outside transaction) |
| `2026_03_06_120000_change_fire_incidents_units_dispatched_to_text.php` | Widens `fire_incidents.units_dispatched` from `varchar(255)` to `text` to accommodate long live-feed unit lists |
| `2026_03_16_000001_create_saved_alerts_table.php` | Creates `saved_alerts` |
| `2026_03_25_000001_create_gta_postal_codes_table.php` | Creates `gta_postal_codes` |
| `2026_03_25_000002_create_weather_caches_table.php` | Creates `weather_caches` |
| `2026_03_31_040514_create_miway_alerts_table.php` | Creates `miway_alerts` |
| `2026_03_31_082123_add_fulltext_index_to_miway_alerts_table.php` | Adds MySQL/MariaDB FULLTEXT index on `(header_text, description_text)` in `miway_alerts` (no-op on other drivers) |
| `2026_04_01_221138_create_yrt_alerts_table.php` | Creates `yrt_alerts` |

---

## Related Documentation

- [unified-alerts-system.md](unified-alerts-system.md) — UNION ALL query, providers, cursor pagination, FTS query patterns
- [notification-system.md](notification-system.md) — Notification pipeline, matching engine, inbox API, saved places API
- [enums.md](enums.md) — `AlertSource`, `AlertStatus`, `IncidentUpdateType`
- [maintenance.md](maintenance.md) — Pruning schedules and retention policies
- [scene-intel.md](scene-intel.md) — Scene Intel feature using `incident_updates`
- [sources/toronto-fire.md](../sources/toronto-fire.md) — Fire incident feed integration
- [sources/toronto-police.md](../sources/toronto-police.md) — Police call feed integration
- [sources/ttc-transit.md](../sources/ttc-transit.md) — TTC transit alert feed integration
- [sources/go-transit.md](../sources/go-transit.md) — GO Transit alert feed integration
- [sources/miway.md](../sources/miway.md) — MiWay transit alert feed integration
- [sources/yrt.md](../sources/yrt.md) — YRT service advisory feed integration
