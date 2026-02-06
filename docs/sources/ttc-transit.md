# TTC Transit Alerts Integration

## Status

Implemented. TTC alerts are ingested into `transit_alerts` and surfaced in the unified alerts feed as source `transit`.

## Runtime Components

- Feed service: `app/Services/TtcAlertsFeedService.php`
- Sync command: `app/Console/Commands/FetchTransitAlertsCommand.php`
- Queue job: `app/Jobs/FetchTransitAlertsJob.php`
- Model: `app/Models/TransitAlert.php`
- Unified provider: `app/Services/Alerts/Providers/TransitAlertSelectProvider.php`
- Schedule: `routes/console.php` -> `transit:fetch-alerts` every 5 minutes (`withoutOverlapping()`)

## Upstream TTC Sources

`TtcAlertsFeedService` merges three source families:

1. Live API (primary, critical)
- `https://alerts.ttc.ca/api/alerts/live-alerts`
- Buckets consumed: `routes`, `accessibility`, `siteWideCustom`, `generalCustom`, `stops`

2. Sitecore SXA search results (secondary, best-effort)
- `https://www.ttc.ca//sxa/search/results/`
- Multiple fixed scope/item GUID endpoint configurations

3. Static service-advisory page (secondary, best-effort)
- `https://www.ttc.ca/service-advisories/Streetcar-Service-Changes`

If SXA/static fetch/parsing fails, warnings are logged and ingestion continues with live API data.

## Persistence Contract

Table: `transit_alerts` (migration `2026_02_05_203656_create_transit_alerts_table.php`)

Key columns:
- `external_id` (unique; prefixed by source family: `api:*`, `sxa:*`, `static:*`)
- `source_feed` (`live-api`, `sxa`, `static`)
- `alert_type`, `route_type`, `route`, `title`, `description`
- `severity`, `effect`, `cause`
- `active_period_start`, `active_period_end`
- `direction`, `stop_start`, `stop_end`, `url`
- `is_active`, `feed_updated_at`

## Sync Behavior

`transit:fetch-alerts`:
- Fetches and normalizes current feed snapshot.
- Upserts rows by `external_id`.
- Marks rows absent from the latest snapshot as inactive.
- Stores `feed_updated_at` from source feed timestamp.

## Unified Feed Mapping

`TransitAlertSelectProvider` maps `transit_alerts` to unified columns:
- `id`: `transit:{external_id}`
- `source`: `transit`
- `timestamp`: `COALESCE(active_period_start, created_at)`
- `title`: `title`
- `location_name`: derived from route + stop segment
- `meta`: JSON payload including route/effect/source-feed/description/cause/direction/stops
