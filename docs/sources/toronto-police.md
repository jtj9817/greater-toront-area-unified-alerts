# Toronto Police Calls for Service Integration

## Status

Implemented. Toronto Police calls are ingested into `police_calls` and surfaced as unified source `police`.

## Runtime Components

- Feed service: `app/Services/TorontoPoliceFeedService.php`
- Sync command: `app/Console/Commands/FetchPoliceCallsCommand.php`
- Queue job: `app/Jobs/FetchPoliceCallsJob.php`
- Model: `app/Models/PoliceCall.php`
- Unified provider: `app/Services/Alerts/Providers/PoliceAlertSelectProvider.php`
- Schedule: `routes/console.php` -> `police:fetch-calls` every 10 minutes

## Upstream Endpoint

- ArcGIS FeatureServer query endpoint:
  - `https://services.arcgis.com/S9th0jAJ7bqgIRjw/arcgis/rest/services/C4S_Public_NoGO/FeatureServer/0/query`

`TorontoPoliceFeedService` performs direct paginated HTTP requests using `resultOffset` + `resultRecordCount` until `exceededTransferLimit` is false.

## Normalization

Each ArcGIS feature is normalized to:
- `object_id`
- `call_type_code`
- `call_type`
- `division`
- `cross_streets`
- `latitude`
- `longitude`
- `occurrence_time` (from epoch milliseconds)

## Persistence Contract

Table: `police_calls` (migration `2026_01_31_204856_create_police_calls_table.php`)

Key columns:
- `object_id` (unique)
- `call_type_code`, `call_type`, `division`, `cross_streets`
- `latitude`, `longitude`
- `occurrence_time`
- `is_active`, `feed_updated_at`

## Sync Behavior

`police:fetch-calls`:
- Fetches latest call snapshot.
- Upserts by `object_id`.
- Marks previously active rows absent from the latest fetch as inactive.
- Stores `feed_updated_at` using current command runtime timestamp.

## Unified Feed Mapping

`PoliceAlertSelectProvider` maps:
- `id`: `police:{object_id}`
- `source`: `police`
- `timestamp`: `occurrence_time`
- `title`: `call_type`
- `location_name`: `cross_streets`
- `lat/lng`: `latitude/longitude`
- `meta`: `division`, `call_type_code`, `object_id`
