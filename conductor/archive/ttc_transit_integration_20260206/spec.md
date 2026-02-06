# Track Specification: TTC Transit Integration

## Purpose
Implement TTC alert ingestion and unify it into the existing alerts architecture so the transit provider is no longer a placeholder and transit feed freshness is included in the GTA alerts page.

## Architecture Baseline (Current Repo State)
- `App\Enums\AlertSource` already includes `Transit`.
- `App\Providers\AppServiceProvider` already tags `TransitAlertSelectProvider` in `alerts.select-providers`.
- `App\Services\Alerts\Providers\TransitAlertSelectProvider` is currently a placeholder returning `WHERE 1 = 0`.
- `App\Services\Alerts\UnifiedAlertsQuery` already unions all tagged providers and requires non-empty `source`, `external_id`, `timestamp`, `title`.
- `App\Http\Controllers\GtaAlertsController` currently computes `latest_feed_updated_at` from fire + police only.
- Frontend already supports `source: 'transit'` in `AlertService.ts`, but transit severity/icon logic is generic and not TTC-feed-aware.

## Scope
- Add TTC ingestion and persistence (`transit_alerts` table, model, factory, service, command, job).
- Replace transit provider placeholder with real select mapping.
- Include transit feed freshness in `latest_feed_updated_at`.
- Upgrade frontend transit display mapping to use real TTC metadata.
- Add tests matching existing suite structure and style.

## Non-Goals
- GTFS real-time vehicle position integration.
- Rider journey planning or routing.
- Historical analytics beyond current active/cleared lifecycle used by unified alerts.

## Upstream Source Alignment
This spec follows the implementation direction in `docs/sources/ttc-transit.md`:
1. `alerts.ttc.ca/api/alerts/live-alerts` as the primary source.
2. Sitecore SXA search endpoints as secondary source.
3. Static TTC advisory page parsing as tertiary source.

Primary source failure is fatal for a sync run. Secondary/tertiary failures are best-effort with warning logs.

## Data Model
New table: `transit_alerts`

Required fields:
- `external_id` (string, unique; prefixed `api:`, `sxa:`, `static:`).
- `source_feed` (`live-api`, `sxa`, `static`).
- `title` (string), `is_active` (boolean), `feed_updated_at` (timestamp nullable).
- `active_period_start` / `active_period_end` (nullable datetimes).

Transit context fields:
- `alert_type`, `route_type`, `route`, `description`, `severity`, `effect`, `cause`.
- `direction`, `stop_start`, `stop_end`, `url`.

Index expectations (aligned to fire/police table patterns + transit query needs):
- unique index on `external_id`.
- composite index on `is_active` + `active_period_start`.
- index on `feed_updated_at`.
- index on `source_feed`.
- index on `route_type`.

`description` should use `mediumText` to avoid truncation risk for CMS HTML-derived content.

## Ingestion Contract
New service: `App\Services\TtcAlertsFeedService`

Return shape:
- `updated_at`: `Carbon` timestamp for the run.
- `alerts`: normalized alert rows for persistence.

Normalization rules:
- Strip unsafe HTML from descriptions before storage.
- Treat sentinel `activePeriod.end = 0001-01-01T00:00:00Z` as `null`.
- Prefix external IDs per source to keep uniqueness across feeds.
- If a previously active row is missing from the latest aggregated source set, mark it inactive.

HTTP expectations:
- Use timeout + retry strategy consistent with existing feed services.
- Include browser-like headers (`User-Agent`, `Referer`, `Accept-Language`) per `docs/sources/ttc-transit.md` to reduce WAF blocks.

## Unified Provider Contract
`TransitAlertSelectProvider` must emit the same column contract as existing providers:
- `id`, `source`, `external_id`, `is_active`, `timestamp`, `title`, `location_name`, `lat`, `lng`, `meta`.

Mapping requirements:
- `source` fixed to `transit`.
- `external_id` from `transit_alerts.external_id`.
- `timestamp` from `COALESCE(active_period_start, created_at)`.
- `location_name` built from available route/stop fields.
- `lat`/`lng` as `NULL` (route-based alerts).
- `meta` includes transit context used by frontend (`route_type`, `route`, `severity`, `effect`, `source_feed`, `alert_type`, `description`, `url`, `direction`, `cause`).

## Controller + Scheduling Integration
- `GtaAlertsController::latestFeedUpdatedAt()` must consider `TransitAlert`.
- `routes/console.php` must register `Schedule::command('transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping();`.

## Frontend Integration Requirements
`resources/js/features/gta-alerts/services/AlertService.ts` must:
- map TTC severity/effect into `high` / `medium` / `low`.
- choose transit icon based on route/equipment type (`subway`, `bus`, `streetcar`, `elevator`) instead of single generic train icon.
- produce a transit description from provider metadata (route + effect + supplemental details).

## File Targets
Create:
- `database/migrations/*_create_transit_alerts_table.php`
- `app/Models/TransitAlert.php`
- `database/factories/TransitAlertFactory.php`
- `app/Services/TtcAlertsFeedService.php`
- `app/Console/Commands/FetchTransitAlertsCommand.php`
- `app/Jobs/FetchTransitAlertsJob.php`
- `tests/Feature/Services/TtcAlertsFeedServiceTest.php`
- `tests/Feature/Commands/FetchTransitAlertsCommandTest.php`
- `tests/Feature/Jobs/FetchTransitAlertsJobTest.php`
- `tests/Unit/Models/TransitAlertTest.php`

Modify:
- `app/Services/Alerts/Providers/TransitAlertSelectProvider.php`
- `tests/Unit/Services/Alerts/Providers/TransitAlertSelectProviderTest.php`
- `app/Http/Controllers/GtaAlertsController.php`
- `routes/console.php`
- `database/seeders/UnifiedAlertsTestSeeder.php`
- `tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php`
- `tests/Feature/GtaAlertsTest.php`
- `resources/js/features/gta-alerts/services/AlertService.ts`
- `resources/js/features/gta-alerts/services/AlertService.test.ts`

## Acceptance Criteria
- `transit:fetch-alerts` command persists normalized TTC alerts and deactivates stale records.
- `TransitAlertSelectProvider` returns non-empty rows when transit data exists and conforms to unified select schema.
- Unified alerts query returns mixed fire/police/transit rows with deterministic ordering.
- `latest_feed_updated_at` reflects the max timestamp across fire, police, and transit feeds.
- Frontend transit cards show type-aware icon + severity from TTC metadata.
- New and updated tests pass in both backend (Pest) and frontend (Vitest).
