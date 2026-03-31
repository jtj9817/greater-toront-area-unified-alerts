# YRT Service Advisories Implementation Plan

Integrate **York Region Transit (YRT)** service advisories into the GTA Alerts **Unified Alerts** architecture.

This plan is written to match the current codebase patterns (Laravel 12, Provider-tagged unified query, scheduled fetch job wrappers, Inertia + React domain mapping).

---

## Source Discovery (Research Findings)

Validated on **2026-03-31** using browser network + DOM inspection:

- **Public UI page:** `https://www.yrt.ca/modules/news/en/serviceadvisories`
  - Loads the advisory list via XHR (not just static HTML).
- **Primary feed (JSON):** `GET https://www.yrt.ca/Modules/NewsModule/services/getServiceAdvisories.ashx?categories=b8f1acba-f043-ec11-9468-0050569c41bf&lang=en`
  - Returns a JSON array (served with `Content-Type: application/javascript; charset=utf-8`).
  - Fields observed: `title`, `description` (often truncated with `...`), `link`, `postedDate`, `postedTime`, plus split date components.
  - Confirmed to work without cookies (`fetch(..., { credentials: 'omit' })` succeeded).
- **Detail pages (optional enrichment):** `https://www.yrt.ca/en/news/{slug}.aspx`
  - Full article body typically includes labeled fields embedded in prose: `When:`, `Where:`, `Reason:`, `Routes affected:`.
- **Not used / unreliable:** `GET /Modules/NewsModule/services/getAlertBannerFeeds.ashx`
  - Returned a body-string error `The remote server returned an error: (404) Not Found.` during the same inspection.

**Conclusion:** ingest the `getServiceAdvisories.ashx` JSON feed as the source of truth; fetch detail pages only when we actually need richer body text.

---

## Fetch Optimizations (Avoid Unnecessary Work)

1. **List-first ingestion**
   - Treat the JSON feed as the authoritative active set.
   - Do not scrape the HTML list page.
2. **Skip unchanged work**
   - Persist `list_hash` (SHA1 of a stable JSON subset) per alert.
   - If `list_hash` has not changed and we already have `body_text`, skip detail fetch.
3. **Detail fetch on-demand**
   - Fetch `link` HTML only when one of the following is true:
     - new alert
     - `list_hash` changed
     - `body_text` is empty
     - `details_fetched_at` is older than a refresh threshold (optional, e.g. 24h)
4. **Guard rails**
   - Cap list size processed per run (e.g. max 200).
   - Low/no concurrency; timeouts + retries consistent with existing feed services.

---

## Data Model (Observed Shape)

Persist these fields:

- `external_id` — derived from `link` slug (path basename without `.aspx`)
- `title`
- `posted_at` — parsed from `postedDate + postedTime` (America/Toronto → UTC)
- `details_url` — the `link` field
- `description_excerpt` — normalized `description` from the feed (even if truncated)
- `route_text` — derived best-effort (from title like `52 - Holland Landing` or from `Routes affected:` in excerpt)
- `body_text` — optional full detail page text (normalized whitespace)
- `list_hash` — supports skipping unchanged detail fetches
- `details_fetched_at` — timestamp when detail page was last fetched
- `is_active` — true when present in the latest feed, false when missing
- `feed_updated_at` — scrape time (UTC) to aid ops/debugging

Coordinates are not present; unified `lat`/`lng` are always `NULL` for this source.

---

## Architecture Fit (GTA Alerts Unified Query)

Backend flow:

```
YrtAlert table
  → YrtAlertSelectProvider (tagged in AppServiceProvider)
  → UnifiedAlertsQuery (UNION)
  → API resources → Inertia/React domain mappers
```

Frontend flow:

```
UnifiedAlertResource (source: 'yrt')
  → fromResource() switch
  → mapYrtAlert()
  → presentation mapping (reuse transit presentation utilities)
```

---

## Phase 1: Database + Model

Create `yrt_alerts` using the existing alert table conventions (see `database/migrations/2026_02_05_233653_create_go_transit_alerts_table.php`).

Suggested columns:

- `id`
- `external_id` (unique)
- `title` (string)
- `posted_at` (dateTime)
- `details_url` (string)
- `description_excerpt` (text, nullable)
- `route_text` (string, nullable)
- `body_text` (text, nullable)
- `list_hash` (string(40), nullable)
- `details_fetched_at` (timestamp, nullable)
- `is_active` (boolean default true)
- `feed_updated_at` (timestamp nullable)
- `created_at`, `updated_at`

Indexes:

- `index(['is_active', 'posted_at'])`
- `index('posted_at')`
- `index('feed_updated_at')`

Model:

- `app/Models/YrtAlert.php`
- `fillable` for persisted columns
- `casts()` for `posted_at`, `feed_updated_at`, `details_fetched_at` as datetimes and `is_active` as boolean
- `scopeActive()` consistent with other alert models

---

## Phase 2: Feed Service (JSON + Optional Detail HTML)

Add `app/Services/YrtServiceAdvisoriesFeedService.php` following `app/Services/TtcAlertsFeedService.php` patterns:

- Use `Http::timeout(15)->retry(2, 200, throw: false)` with browser-ish headers.
- Use `FeedCircuitBreaker`:
  - `throwIfOpen('yrt')`
  - `recordSuccess('yrt')` / `recordFailure('yrt', $exception)`
- Respect `config('feeds.allow_empty_feeds')`.
- Parse JSON via `$response->json()`.
- Parse detail HTML with `DOMDocument` + `DOMXPath` only when needed.

Fetch algorithm:

1. GET the JSON endpoint.
2. For each item:
   - `external_id`: slug from `link` (basename without `.aspx`)
   - `title`: `title` (trim)
   - `posted_at`: parse `postedDate + postedTime` in `America/Toronto`, store UTC
   - `description_excerpt`: normalize whitespace, keep as-is (may be truncated)
   - `route_text`: best-effort parse:
     - if title matches `^[0-9]{1,3}\\s*[-–]` then take the left route segment (or full title if it is clearly route-labeled)
     - else look for `Routes affected:` / `Route affected:` in the excerpt
   - `details_url`: `link`
   - compute `list_hash` from a stable subset (e.g. `title|description|postedDate|postedTime|link`)
3. Optionally fetch and parse the detail page HTML (see “Detail fetch on-demand” rules above) to populate `body_text`.
4. Return shape:

```php
// array{updated_at: CarbonInterface, alerts: list<array<string, mixed>>}
```

`updated_at` can be `now('UTC')` (the feed does not publish a trustworthy last-updated timestamp; `postedDateTime` was observed as a placeholder).

---

## Phase 3: Fetch Command (Sync + Notifications)

Add `app/Console/Commands/FetchYrtAlertsCommand.php` matching the error-handling style of:

- `app/Console/Commands/FetchTransitAlertsCommand.php`
- `app/Console/Commands/FetchGoTransitAlertsCommand.php`

Responsibilities:

- Call `YrtServiceAdvisoriesFeedService->fetch()`.
- Upsert each item into `yrt_alerts` with `is_active=true` and `feed_updated_at`.
- Deactivate stale rows:
  - `where('is_active', true)->whereNotIn('external_id', $activeExternalIds)->update(['is_active' => false])`
- Dispatch `AlertCreated` for newly created or re-activated alerts:
  - Add `NotificationAlertFactory::fromYrtAlert(YrtAlert $alert)` as needed.

Command signature:

- `yrt:fetch-alerts`

---

## Phase 4: Queue Job Wrapper + Scheduler

Follow the existing scheduler pattern (command invoked via a unique queued job):

- `app/Jobs/FetchYrtAlertsJob.php` (modelled on `app/Jobs/FetchGoTransitAlertsJob.php`)
  - `Artisan::call('yrt:fetch-alerts')`, throw on non-zero
  - `ShouldQueue` + `ShouldBeUnique`, `WithoutOverlapping` middleware
- Update `app/Services/ScheduledFetchJobDispatcher.php`
  - Add `dispatchYrtAlerts(): bool` that dispatches `FetchYrtAlertsJob`
- Update `routes/console.php`
  - `Schedule::call(fn (ScheduledFetchJobDispatcher $d) => $d->dispatchYrtAlerts())`
  - `->everyFiveMinutes()->withoutOverlapping(10)` (match TTC/GO cadence)

---

## Phase 5: Unified Alerts Provider (SelectProvider)

Create `app/Services/Alerts/Providers/YrtAlertSelectProvider.php` implementing:

- `App\Services\Alerts\Contracts\AlertSelectProvider`
  - `source(): string` returns `AlertSource::Yrt->value`
  - `select(UnifiedAlertsCriteria $criteria): Builder`

Provider query should emit the unified columns:

- `id` as `{source}:{external_id}` (driver-specific concat, see other providers)
- `source` literal `'yrt'`
- `external_id`
- `is_active`
- `timestamp` = `posted_at`
- `title`
- `location_name` = `route_text` (or `NULL` when empty)
- `lat` / `lng` = `NULL`
- `meta` = JSON object built from table columns (`details_url`, `description_excerpt`, `body_text`, `posted_at`, `feed_updated_at`)

Register provider tag:

- Update `app/Providers/AppServiceProvider.php` to include `YrtAlertSelectProvider::class` in the `'alerts.select-providers'` tag list.

---

## Phase 6: AlertSource Enum

Update `app/Enums/AlertSource.php`:

- Add `case Yrt = 'yrt';`

---

## Phase 7: Frontend Domain

Follow the same pattern as DRT/MiWay:

- `resources/js/features/gta-alerts/domain/alerts/transit/yrt/schema.ts`
- `resources/js/features/gta-alerts/domain/alerts/transit/yrt/mapper.ts`
- Register `'yrt'` in:
  - `resources/js/features/gta-alerts/domain/alerts/fromResource.ts`
  - `resources/js/features/gta-alerts/domain/alerts/resource.ts` (source enum)
  - `resources/js/features/gta-alerts/domain/alerts/types.ts` (DomainAlert union)

Meta fields should reflect what we actually persist (for example: `details_url`, `description_excerpt`, `body_text`).

Presentation:

- In `mapDomainAlertToPresentation.ts`, map `'yrt'` using the existing “transit alert” presentation helpers (avoid TTC-specific naming).

---

## Verification

Run with Sail:

```bash
vendor/bin/sail artisan yrt:fetch-alerts
vendor/bin/sail artisan tinker --execute='\\App\\Models\\YrtAlert::count(); \\App\\Models\\YrtAlert::latest(\"posted_at\")->first();'
vendor/bin/sail artisan tinker --execute='app(\\App\\Services\\Alerts\\UnifiedAlertsQuery::class)->cursorPaginate(\\App\\Services\\Alerts\\DTOs\\UnifiedAlertsCriteria::fromRequest([\"source\" => \"yrt\"]))'
```
