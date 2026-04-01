# MiWay Service Alerts Integration

## Status

Implemented. MiWay alerts are ingested into `miway_alerts` and exposed as unified source `miway`.

## Runtime Components

- Feed service: `app/Services/MiwayGtfsRtAlertsFeedService.php` (depends on `FeedCircuitBreaker`)
- Sync command: `app/Console/Commands/FetchMiwayAlertsCommand.php`
- Queue job: `app/Jobs/FetchMiwayAlertsJob.php`
- Model: `app/Models/MiwayAlert.php`
- Unified provider: `app/Services/Alerts/Providers/MiwayAlertSelectProvider.php`
- Schedule: `routes/console.php` -> `miway:fetch-alerts` every 5 minutes (`withoutOverlapping(10)`)

## Upstream Endpoint

- `https://www.miapp.ca/gtfs_rt/Alerts/Alerts.pb`

MiWay publishes GTFS-RT protobuf alerts. `MiwayGtfsRtAlertsFeedService` decodes the `FeedMessage` and normalizes each `Alert` entity.

`Google\Transit\Realtime` protobuf classes are generated from the standard GTFS-RT schema (`gtfs-rt.proto`).

## Persistence Contract

Table: `miway_alerts` (migration `2026_03_31_040514_create_miway_alerts_table.php`)

Key columns:
- `external_id` (unique)
- `header_text`, `description_text`
- `cause`, `effect` (GTFS-RT enum names as strings, e.g. `NO_SERVICE`, `DETOUR`)
- `starts_at`, `ends_at` (active period; UTC)
- `url`, `detour_pdf_url`
- `is_active`, `feed_updated_at`

MySQL/MariaDB deployments include a fulltext index on `(header_text, description_text)` for text search (migration `2026_03_31_082123_add_fulltext_index_to_miway_alerts_table.php`). No-op on SQLite/PostgreSQL.

## Sync Behavior

`miway:fetch-alerts`:

1. Reads the most recent `feed_updated_at` from any existing `MiwayAlert` and converts it to RFC-7231 format for the `If-Modified-Since` header.
2. Calls `MiwayGtfsRtAlertsFeedService::fetch($etag, $lastModified)`.
3. On `304 Not Modified`: exits immediately with no database writes.
4. Upserts rows by `external_id`, setting `is_active = true` for all alerts present in the feed.
5. Deactivates any previously-active rows whose `external_id` is absent from the current feed snapshot. If the feed returns zero alerts, all active rows are deactivated (correctly handles empty-feed case).
6. Dispatches `AlertCreated` for each newly-inserted or reactivated alert.
7. Persists `feed_updated_at` from the protobuf header timestamp (or current UTC time if the header is zero).

## Conditional Fetch

The service sends `If-None-Match` (ETag) and `If-Modified-Since` (Last-Modified) headers when a prior `feed_updated_at` exists. The MiWay server returns `304 Not Modified` when neither header has changed, skipping all database writes on that cycle.

**Note:** MiWay's server does not issue ETags; `If-Modified-Since` is the effective conditional-fetch mechanism. The command currently persists only `feed_updated_at` (Last-Modified) and passes it to the service. The ETag header is supported by the service layer (`fetch($etag, $lastModified)`) but is not exercised by the production command flow since there is no ETag to load.

### Failure Modes

| Scenario | Behavior |
|---|---|
| HTTP non-2xx | `RuntimeException`, circuit breaker records failure |
| Empty body (`''`) | Throws unless `feeds.allow_empty_feeds=true` |
| Binary `"0"` body | Treated as malformed protobuf, throws `RuntimeException` |
| Unknown GTFS-RT cause/effect enum value | Falls back to `UNKNOWN_CAUSE` / `UNKNOWN_EFFECT` |
| Zero alerts returned | Throws unless `feeds.allow_empty_feeds=true` |

## Notification Severity Mapping

`NotificationAlertFactory::mapMiwayEffectSeverity()` maps GTFS-RT effect names to `NotificationSeverity`:

| Effect | Severity |
|---|---|
| `NO_SERVICE` | `CRITICAL` |
| `REDUCED_SERVICE`, `SIGNIFICANT_DELAYS`, `DETOUR` | `MAJOR` |
| All others (including `UNKNOWN_EFFECT`, null, empty) | `MINOR` |

## Unified Feed Mapping

`MiwayAlertSelectProvider` maps `miway_alerts` to unified columns:

- `id`: `miway:{external_id}`
- `source`: `miway`
- `timestamp`: `COALESCE(starts_at, created_at)`
- `title`: `header_text`
- `location_name`: `NULL` (MiWay alerts carry no geographic coordinates)
- `lat`/`lng`: `NULL`
- `meta`: JSON payload with `header_text`, `description_text`, `cause`, `effect`, `url`, `detour_pdf_url`, `ends_at`, `feed_updated_at`

Text search (`q` parameter) uses MySQL/MariaDB fulltext index with `MATCH...AGAINST` + `LIKE` fallback; PostgreSQL uses `tsvector` + `ILIKE`; SQLite uses `LIKE` on both fields.

## Usage

```bash
# Run manually
sail artisan miway:fetch-alerts

# Dispatch to queue
sail artisan tinker --execute="App\Jobs\FetchMiwayAlertsJob::dispatch();"

# Query active alerts
sail artisan tinker --execute="echo App\Models\MiwayAlert::active()->count();"

# Check schedule registration
sail artisan schedule:list
```

### Querying the Model

```php
use App\Models\MiwayAlert;

// All currently active alerts
MiwayAlert::active()->get();

// Active alerts ordered by start time
MiwayAlert::active()->orderByDesc('starts_at')->get();

// Filter by effect
MiwayAlert::active()->where('effect', 'NO_SERVICE')->get();

// Filter by cause
MiwayAlert::active()->where('cause', 'CONSTRUCTION')->get();
```
