# YRT Service Advisories Integration

## Status

Implemented. YRT (York Region Transit) service advisories are ingested into `yrt_alerts` and exposed as unified source `yrt`.

## Runtime Components

- Feed service: `app/Services/YrtServiceAdvisoriesFeedService.php` (depends on `FeedCircuitBreaker`)
- Sync command: `app/Console/Commands/FetchYrtAlertsCommand.php`
- Queue job: `app/Jobs/FetchYrtAlertsJob.php`
- Model: `app/Models/YrtAlert.php`
- Unified provider: `app/Services/Alerts/Providers/YrtAlertSelectProvider.php`
- Schedule: `routes/console.php` -> `yrt:fetch-alerts` every 5 minutes (`withoutOverlapping(10)`)
- Job uniqueness: `ShouldBeUnique` (`uniqueFor = 600 s`, store `QUEUE_UNIQUE_LOCK_STORE`), `WithoutOverlapping('fetch-yrt-alerts')` (`releaseAfter = 30 s`, `expireAfter = 600 s`), `timeout = 120 s`, 3 retries with 30 s backoff

## Upstream Endpoint

- List: `https://www.yrt.ca/Modules/NewsModule/services/getServiceAdvisories.ashx?categories=b8f1acba-f043-ec11-9468-0050569c41bf&lang=en`

`YrtServiceAdvisoriesFeedService` performs a two-phase fetch:

1. **List JSON** — Returns a JSON array of advisory summaries with title, link, description excerpt, posted date/time, and category.
2. **Detail HTML** (conditional) — For each advisory, fetches the detail page HTML to extract full body text. Detail fetch is only triggered when at least one condition is met:
   - Advisory is new (no existing DB record)
   - `list_hash` has changed since last sync
   - `body_text` is empty/null
   - `details_fetched_at` is older than the configured refresh threshold (default 24 hours)

When detail fetch is skipped, existing `body_text` is preserved from the DB record. When detail fetch fails (network/parse error), existing `body_text` and `details_fetched_at` are preserved (graceful degradation).

## Persistence Contract

Table: `yrt_alerts` (migration `2026_04_01_221138_create_yrt_alerts_table.php`)

Key columns:
- `external_id` (unique) — URL slug extracted from `details_url` pathname
- `title` — Advisory headline
- `posted_at` — Parsed from `postedDate + postedTime` in `America/Toronto`, stored in UTC
- `details_url` — Absolute URL from feed `link`
- `description_excerpt` (nullable) — Normalized whitespace feed description
- `route_text` (nullable) — Best-effort route derivation from title prefix or "Routes affected:" body text
- `body_text` (nullable) — Full advisory body extracted from detail HTML
- `list_hash` — SHA-1 of stable list fields (`title|description|postedDate|postedTime|link`)
- `details_fetched_at` (nullable) — Timestamp of last successful detail fetch
- `is_active` (boolean, default true)
- `feed_updated_at` (nullable) — Feed-level sync timestamp

Indexes: unique on `external_id`, `posted_at`, `feed_updated_at`, composite `(is_active, posted_at)`.

## Sync Behavior

`yrt:fetch-alerts`:

1. Fetches and normalizes the current advisory list.
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
| Malformed JSON | Throws `RuntimeException` |
| Malformed detail HTML | `extractBodyTextFromHtml` returns `null`, existing `body_text` preserved |
| Empty payload | Respects `ALLOW_EMPTY_FEEDS` flag |
| Detail fetch network error | Existing `body_text` and `details_fetched_at` preserved |

## Notification Mapping

`NotificationAlertFactory::fromYrtAlert()` maps YRT alerts to the notification pipeline:

- Alert ID: `yrt:{external_id}`
- Source: `yrt`
- Severity: `MAJOR` (all YRT advisories)
- Routes: extracted via `splitRoutes($alert->route_text)`

## Unified Feed Mapping

`YrtAlertSelectProvider` maps `yrt_alerts` to unified columns:

- `id`: `yrt:{external_id}` (driver-safe: `||` concat on SQLite/PostgreSQL, `CONCAT()` on MySQL)
- `source`: `yrt`
- `timestamp`: `posted_at`
- `title`: `title`
- `location_name`: `route_text` (nullable — YRT alerts carry no geographic coordinates)
- `lat`/`lng`: `NULL`
- `meta`: JSON payload with `details_url`, `description_excerpt`, `body_text`, `route_text`, `posted_at`, `feed_updated_at`

Text search (`q` parameter) uses `ILIKE` on PostgreSQL and SQLite; MySQL uses `LIKE` fallback. YRT has no dedicated FULLTEXT or GIN index migration.

## Frontend Mapping

YRT alerts map to `kind: 'transit'` in the frontend domain layer (transit-adjacent). Source-specific files:

- Schema: `resources/js/features/gta-alerts/domain/alerts/transit/yrt/schema.ts` (includes Zod schemas and inferred types)
- Mapper: `resources/js/features/gta-alerts/domain/alerts/transit/yrt/mapper.ts`

YRT alerts render with shared transit presentation helpers (not TTC-specific branches). Severity defaults to `low` unless presentation logic derives otherwise.

## Usage

```bash
# Run manually
sail artisan yrt:fetch-alerts

# Dispatch to queue
sail artisan tinker --execute="App\Jobs\FetchYrtAlertsJob::dispatch();"

# Query active alerts
sail artisan tinker --execute="echo App\Models\YrtAlert::active()->count();"

# Check schedule registration
sail artisan schedule:list
```

### Querying the Model

```php
use App\Models\YrtAlert;

// All currently active alerts
YrtAlert::active()->get();

// Active alerts ordered by posted time
YrtAlert::active()->orderByDesc('posted_at')->get();

// Filter by route text
YrtAlert::active()->where('route_text', 'like', '%52%')->get();

// Alerts with body text fetched
YrtAlert::active()->whereNotNull('body_text')->get();
```

## Configuration

| Key | Default | Purpose |
|---|---|---|
| `feeds.yrt.max_records` | `200` | Maximum advisories to process per sync |
| `feeds.yrt.details_refresh_hours` | `24` | Hours before stale `details_fetched_at` triggers re-fetch |
