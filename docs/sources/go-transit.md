# GO Transit Alerts Integration

## Status

Implemented. GO Transit alerts are ingested into `go_transit_alerts` and exposed as unified source `go_transit`.

## Runtime Components

- Feed service: `app/Services/GoTransitFeedService.php`
- Sync command: `app/Console/Commands/FetchGoTransitAlertsCommand.php`
- Queue job: `app/Jobs/FetchGoTransitAlertsJob.php`
- Model: `app/Models/GoTransitAlert.php`
- Unified provider: `app/Services/Alerts/Providers/GoTransitAlertSelectProvider.php`
- Schedule: `routes/console.php` -> `go-transit:fetch-alerts` every 5 minutes (`withoutOverlapping()`)

## Upstream Endpoint

- `https://api.metrolinx.com/external/go/serviceupdate/en/all`

`GoTransitFeedService` parses:
- Train notifications
- Bus notifications
- Station notifications
- Train SAAG notifications (delay-oriented records)

## Normalized Alert Shape

Feed parser output contains:
- `external_id`
- `alert_type` (`notification` or `saag`)
- `service_mode` (`GO Train`, `GO Bus`, `Station`)
- `corridor_or_route`, `corridor_code`
- `sub_category`
- `message_subject`, `message_body`
- `direction`, `trip_number`, `delay_duration`
- `status`, `line_colour`
- `posted_at`

## Persistence Contract

Table: `go_transit_alerts` (migration `2026_02_05_233653_create_go_transit_alerts_table.php`)

Key columns:
- `external_id` (unique)
- `alert_type`, `service_mode`
- `corridor_or_route`, `corridor_code`, `sub_category`
- `message_subject`, `message_body`
- `direction`, `trip_number`, `delay_duration`, `status`, `line_colour`
- `posted_at`, `is_active`, `feed_updated_at`

## Sync Behavior

`go-transit:fetch-alerts`:
- Fetches current GO feed snapshot.
- Upserts rows by `external_id`.
- Marks previously active rows not present in snapshot as inactive.
- Converts `posted_at` and `feed_updated_at` to UTC before persistence.

## Unified Feed Mapping

`GoTransitAlertSelectProvider` maps:
- `id`: `go_transit:{external_id}`
- `source`: `go_transit`
- `timestamp`: `posted_at`
- `title`: `message_subject`
- `location_name`: `corridor_or_route`
- `meta`: JSON payload with service mode, sub-category, corridor code, delay/direction/trip details, and message body
