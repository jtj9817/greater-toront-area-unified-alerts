# DRT Service Alerts Integration

## Status

Implemented. Durham Region Transit (DRT) Service Alerts and Detours are ingested into `drt_alerts` and exposed as unified source `drt`.

## Runtime Components

- Feed service: `app/Services/DrtServiceAlertsFeedService.php` (depends on `FeedCircuitBreaker`)
- Sync command: `app/Console/Commands/FetchDrtAlertsCommand.php`
- Queue job: `app/Jobs/FetchDrtAlertsJob.php`
- Model: `app/Models/DrtAlert.php`
- Unified provider: `app/Services/Alerts/Providers/DrtAlertSelectProvider.php`
- Schedule: `routes/console.php` -> `drt:fetch-alerts` every 5 minutes (`withoutOverlapping(10)`)
- Job uniqueness: `ShouldBeUnique` (`uniqueFor = 600 s`, store `QUEUE_UNIQUE_LOCK_STORE`), `WithoutOverlapping('fetch-drt-alerts')` (`releaseAfter = 30 s`, `expireAfter = 600 s`), `timeout = 120 s`, 3 retries with 30 s backoff

## Upstream Endpoint

- List: `https://www.durhamregiontransit.com/Modules/News/en/ServiceAlertsandDetours`
- Pagination: `?page=N`
- Detail: `https://www.durhamregiontransit.com/en/news/{slug}.aspx`

`DrtServiceAlertsFeedService` performs a two-phase fetch:

1. **List HTML** (paginated) — Scrapes alert summaries with title, posted date, `When:`, `Route:`/`Routes:`, and a `Read more` link to the detail page.
2. **Detail HTML** (conditional) — Fetches the detail page HTML to extract full body text. Detail fetch is only triggered when at least one condition is met:
   - Alert is new (no existing DB record)
   - `list_hash` has changed since last sync
   - `body_text` is empty/null
   - `details_fetched_at` is older than the configured refresh threshold (default 24 hours)

When detail fetch is skipped, existing `body_text` is preserved from the DB record. When detail fetch fails (network/parse error), existing `body_text` and `details_fetched_at` are preserved (graceful degradation).

### Scraping Resilience

The scraper avoids coupling to fragile CSS classes and instead relies on stable semantic anchors:
- List entries are identified by the detail URL pattern (`/en/news/*.aspx`) plus nearby `Posted on` text.
- `posted_at` is parsed from the human-readable `Posted on {DayOfWeek}, {Month} {dd}, {yyyy} {hh:mm} {AM|PM}` string in `America/Toronto`, stored in UTC.
- `when_text` and `route_text` are extracted by matching label prefixes `When:`, `Route:`, and `Routes:` (case-insensitive, whitespace normalized).
- `body_text` is extracted from the detail page by selecting content between `Back to Search` and `Subscribe` links.
- URLs are canonicalized to `https://www.durhamregiontransit.com/...` when possible.

## Persistence Contract

Table: `drt_alerts` (migration `2026_04_03_174048_create_drt_alerts_table.php`)

Key columns:
- `external_id` (unique) — URL slug extracted from `details_url` pathname (basename without `.aspx`)
- `title` — Alert headline
- `posted_at` — Parsed from list timestamp in `America/Toronto`, stored in UTC
- `when_text` (nullable) — Human-readable schedule/when information
- `route_text` (nullable) — Route numbers or names affected
- `details_url` — Absolute URL to the detail page
- `body_text` (nullable) — Full advisory body extracted from detail HTML
- `list_hash` (nullable) — SHA-1 of stable list fields for change detection
- `details_fetched_at` (nullable) — Timestamp of last successful detail fetch
- `is_active` (boolean, default true)
- `feed_updated_at` (nullable) — Feed-level sync timestamp

Indexes: unique on `external_id`, `posted_at`, composite `(is_active, posted_at)`.

## Sync Behavior

`drt:fetch-alerts`:

1. Fetches and normalizes paginated list pages (up to `feeds.drt.max_pages`, default 10).
2. Upserts rows by `external_id`, conditionally fetching detail HTML per the rules above.
3. Marks previously active rows not present in the latest snapshot as inactive.
4. Dispatches `AlertCreated` only for newly created or reactivated rows.
5. Persists `feed_updated_at` in UTC.
6. Idempotent across repeated runs with unchanged source content.

### Circuit Breaker

Integrates with `FeedCircuitBreaker` — records success on happy path, records failure on exceptions. After 5 consecutive failures, fetch attempts are blocked for 5 minutes.

### Failure Modes

| Scenario | Behavior |
|---|---|
| HTTP non-2xx | `RuntimeException`, circuit breaker records failure |
| Malformed HTML | Returns empty array, respects `ALLOW_EMPTY_FEEDS` flag |
| Empty payload | Respects `ALLOW_EMPTY_FEEDS` flag |
| Detail fetch network error | Existing `body_text` and `details_fetched_at` preserved |
| Detail page returns empty body | Existing `body_text` preserved |

## Notification Mapping

`NotificationAlertFactory::fromDrtAlert()` maps DRT alerts to the notification pipeline:

- Alert ID: `drt:{external_id}`
- Source: `drt`
- Severity: `MAJOR` (all DRT advisories)
- Routes: extracted via `splitRoutes($alert->route_text)`

## Unified Feed Mapping

`DrtAlertSelectProvider` maps `drt_alerts` to unified columns:

- `id`: `drt:{external_id}` (driver-safe: `||` concat on SQLite/PostgreSQL, `CONCAT()` on MySQL)
- `source`: `drt`
- `timestamp`: `posted_at`
- `title`: `title`
- `location_name`: `route_text` (nullable — DRT alerts carry no geographic coordinates)
- `lat`/`lng`: `NULL`
- `meta`: JSON payload with `details_url`, `when_text`, `route_text`, `body_text`, `feed_updated_at`, `posted_at`

Text search (`q` parameter) uses `ILIKE` on PostgreSQL and SQLite; MySQL uses `LIKE` fallback. DRT has no dedicated FULLTEXT or GIN index migration.

## Frontend Mapping

DRT alerts map to `kind: 'transit'` in the frontend domain layer. Source-specific files:

- Schema: `resources/js/features/gta-alerts/domain/alerts/transit/drt/schema.ts` (includes Zod schemas and inferred types)
- Mapper: `resources/js/features/gta-alerts/domain/alerts/transit/drt/mapper.ts`
- Presentation: `resources/js/features/gta-alerts/domain/alerts/transit/presentation.ts` (severity derivation and description building)

DRT alerts render with shared transit presentation helpers. Severity is derived from title keywords:
- `high`: CANCEL, SUSPEND, NO SERVICE, CLOSED
- `medium`: DETOUR, DELAY, REDUCED SERVICE
- `low`: all others

## Usage

```bash
# Run manually
sail artisan drt:fetch-alerts

# Dispatch to queue
sail artisan tinker --execute="App\Jobs\FetchDrtAlertsJob::dispatch();"

# Query active alerts
sail artisan tinker --execute="echo App\Models\DrtAlert::active()->count();"

# Check schedule registration
sail artisan schedule:list
```

### Querying the Model

```php
use App\Models\DrtAlert;

// All currently active alerts
DrtAlert::active()->get();

// Active alerts ordered by posted time
DrtAlert::active()->orderByDesc('posted_at')->get();

// Filter by route text
DrtAlert::active()->where('route_text', 'like', '%920%')->get();

// Alerts with body text fetched
DrtAlert::active()->whereNotNull('body_text')->get();

// Stale alerts (details older than 24 hours)
DrtAlert::active()
    ->whereNotNull('details_fetched_at')
    ->where('details_fetched_at', '<', now()->subHours(24))
    ->get();
```

## Configuration

| Key | Default | Purpose |
|---|---|---|
| `feeds.drt.max_pages` | `10` | Maximum list pages to scrape per sync |
| `feeds.drt.details_refresh_hours` | `24` | Hours before stale `details_fetched_at` triggers re-fetch |

## Testing

DRT-specific tests:
- `tests/Unit/Models/DrtAlertTest.php` — Schema, indexes, fillable, casts, scopeActive
- `tests/Feature/DrtServiceAlertsFeedServiceTest.php` — List parsing, detail hydration, conditional fetch, pagination, circuit breaker
- `tests/Feature/Commands/FetchDrtAlertsCommandTest.php` — Sync, deactivation, idempotency, event dispatch
- `tests/Feature/Jobs/FetchDrtAlertsJobTest.php` — Job config, middleware, retry behavior
- `tests/Feature/UnifiedAlerts/Providers/DrtAlertSelectProviderTest.php` — Provider select, criteria filtering, meta shape
- `tests/Feature/Api/FeedControllerTest.php` — DRT source/status filtering
- `tests/Feature/GtaAlertsTest.php` — DRT in unified home page feed
- `tests/Feature/Fixtures/DrtFixtureCaptureTest.php` — Fixture integrity
- Frontend Vitest tests in `resources/js/features/gta-alerts/domain/alerts/transit/drt/`
