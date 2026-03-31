# DRT Service Alerts Scraping Implementation Plan

Integrate **Durham Region Transit (DRT)** “Service Alerts and Detours” into the GTA Alerts **Unified Alerts** architecture.

This plan is written to match the current codebase patterns (Laravel 12, Provider-tagged unified query, scheduled fetch job wrappers, Inertia + React domain mapping).

---

## Source Discovery (Research Findings)

Validated on **2026-03-30** using a browser network + DOM inspection:

- **Base URL:** `https://www.durhamregiontransit.com`
- **List page (active alerts):** `https://www.durhamregiontransit.com/Modules/News/en/ServiceAlertsandDetours`
  - **Pagination:** `?page=N` (e.g. `.../ServiceAlertsandDetours?page=2`)
  - Page HTML includes `div.blogItem` entries, each with:
    - `h2 > a.newsTitle[href]` detail URL + title
    - `.blogPostDate p` with a timestamp like: `Posted on Tuesday, February 24, 2026 11:16 AM`
    - Inline body snippet containing `When:` + `Route:` / `Routes:` lines
- **Detail page pattern:** `https://www.durhamregiontransit.com/en/news/{slug}.aspx`
  - Body uses simple HTML (`p`, `strong`, `br`) with labeled fields like:
    - `<p><strong>When:</strong> …</p>`
    - `<p><strong>Route:</strong> …</p>` or `<p><strong>Routes:</strong> …</p>`
- **Not used:** `GET /Modules/NewsModule/services/getAlertBannerFeeds.ashx` returned a body string error (`(404) Not Found`) on 2026-03-30; treat as unreliable.

**Conclusion:** scrape the server-rendered HTML list + detail pages.

---

## Alternate Feeds (Investigated)

Validated on **2026-03-30/31**:

- **RSS:** no `link[rel="alternate"][type="application/rss+xml"]` (or similar) discovered on the listing page; common RSS-style query params (`output=rss`, `rss=1`, etc.) still returned `text/html`.
- **JSON (News-only):** `GET /Modules/NewsModule/services/getTopFiveNews.ashx?limit=N&lang=en` returns a JSON array (served with `Content-Type: application/javascript`), but it appears limited to general “News” content.
  - Attempts to filter via `categories=Service%20Alerts%20and%20Detours` returned an empty response body.
  - It also appears to depend on a site-issued `__RequestVerificationToken` cookie, so it is not a clean unauthenticated feed to build on.

**Conclusion:** no reliable RSS/JSON feed for Service Alerts/Detours was identified; proceed with HTML scraping.

---

## Scraping Optimizations (Avoid Unnecessary Fetching)

Even though the listing is currently small, apply these to keep the scraper efficient and polite:

1. **List-first, detail-on-demand**
   - Always scrape list pages to define the *active set*.
   - Only fetch detail pages when needed for full text.
2. **Skip unchanged detail fetches**
   - Add `list_hash` (SHA1) + `details_fetched_at` columns.
   - Compute `list_hash` from stable list signals (title + posted timestamp + when/route lines + excerpt text).
   - If `external_id` exists and `list_hash` matches and `body_text` is present, skip detail fetch.
   - Optional: force-refresh details if `details_fetched_at` is older than 24h (guards against silent edits that don’t affect the excerpt).
3. **Parse only the content block**
   - On detail pages, extract text from the post content container (e.g., `div.iCreateDynaToken` in observed HTML) rather than `main` to avoid nav/footer noise and reduce parsing work.
4. **Guard rails**
   - Cap pagination (e.g. max 10 pages) to prevent unexpected crawl expansion.
   - Keep concurrency low (sequential fetch is fine for this source).

---

## Data Model (Observed Shape)

DRT items appear to be “posts” that remain listed while active/relevant. The listing includes older posts when their `When:` ranges extend into the future (example observed: posted in 2025 with “Extended until … 2026”).

Persist these fields:

- `external_id` — derived from detail URL slug (stable upsert key)
- `title`
- `posted_at` — from list page (includes time)
- `when_text` — raw `When:` line text (not reliably machine-parseable)
- `route_text` — raw `Route:` / `Routes:` line text (not reliably machine-parseable)
- `details_url` — canonical detail URL (for UI + debugging)
- `body_text` — full detail body as plain text (normalized whitespace)
- `is_active` — true when present in the currently scraped list; false when missing
- `feed_updated_at` — scrape time (UTC) to aid ops/debugging

Coordinates are not present; unified `lat`/`lng` are always `NULL` for this source.

---

## Architecture Fit (GTA Alerts Unified Query)

Backend flow:

```
DrtAlert table
  → DrtAlertSelectProvider (tagged in AppServiceProvider)
  → UnifiedAlertsQuery (UNION)
  → API resources → Inertia/React domain mappers
```

Frontend flow:

```
UnifiedAlertResource (source: 'drt')
  → fromResource() switch
  → mapDrtAlert()
  → presentation mapping (reuse transit presentation utilities)
```

---

## Phase 1: Database + Model

Create `drt_alerts` using the existing alert table conventions (see `database/migrations/2026_02_05_233653_create_go_transit_alerts_table.php` and `database/migrations/2026_01_31_204856_create_police_calls_table.php`).

Suggested columns:

- `id`
- `external_id` (unique)
- `title`
- `posted_at` (datetime)
- `when_text` (string, nullable)
- `route_text` (string, nullable)
- `details_url` (string)
- `body_text` (text, nullable)
- `list_hash` (string(40), nullable) — supports skipping unchanged detail fetches
- `details_fetched_at` (timestamp nullable) — supports periodic refresh of details
- `is_active` (boolean default true)
- `feed_updated_at` (timestamp nullable)
- `created_at`, `updated_at`

Indexes:

- `index(['is_active', 'posted_at'])`
- `index('posted_at')`

Model:

- `app/Models/DrtAlert.php`
- `fillable` for all persisted fields
- `casts()` for `posted_at`, `feed_updated_at` as datetime and `is_active` as boolean
- `scopeActive()` consistent with other alert models

---

## Phase 2: Feed Service (HTML Scraper)

Add `app/Services/DrtServiceAlertsFeedService.php` following `app/Services/TtcAlertsFeedService.php` patterns:

- Use `Http::timeout(15)->retry(2, 200, throw: false)` with browser-ish headers.
- Use `FeedCircuitBreaker`:
  - `throwIfOpen('drt')`
  - `recordSuccess('drt')` / `recordFailure('drt', $exception)`
- Respect `config('feeds.allow_empty_feeds')`.
- Parse HTML with `DOMDocument` + `DOMXPath` (already used in the repo).

Fetch algorithm:

1. Fetch list page page=1.
2. Parse `div.blogItem` blocks:
   - `title`: `string(.//h2/a[@class="newsTitle"])`
   - `details_url`: `string(.//h2/a[@class="newsTitle"]/@href)`
   - `posted_at_raw`: `string(.//*[contains(@class,"blogPostDate")]//p)`
   - `when_text`: first `<p>` containing a `<strong>` that starts with `When`
   - `route_text`: first `<p>` containing a `<strong>` that starts with `Route`/`Routes`
3. Follow pagination via `.PagedList-skipToNext a[href]` until none (guard with a sane max page count).
4. Compute a `list_hash` for each item from stable list fields (title + posted timestamp + when/route + excerpt text).
5. Only fetch and parse detail pages when one of the following is true:
   - the alert is new (no existing row)
   - `list_hash` differs from the stored value
   - `body_text` is null/empty
   - `details_fetched_at` is older than a refresh threshold (optional, e.g. 24h)

Normalization:

- `external_id` = detail URL slug (path basename without `.aspx`)
- `posted_at` should parse with `'America/Toronto'` timezone then convert to UTC (match other feeds).
- `feed_updated_at` = `now()->utc()` for this scrape run.
- `details_fetched_at` = `now()->utc()` when the detail page is actually fetched

Return shape:

```php
// array{updated_at: CarbonInterface, alerts: list<array<string, mixed>>}
```

---

## Phase 3: Fetch Command (Sync + Notifications)

Add `app/Console/Commands/FetchDrtAlertsCommand.php` matching the error-handling style of:

- `app/Console/Commands/FetchTransitAlertsCommand.php`
- `app/Console/Commands/FetchGoTransitAlertsCommand.php`

Responsibilities:

- Call `DrtServiceAlertsFeedService->fetch()`.
- Upsert each item into `drt_alerts` with `is_active=true` and `feed_updated_at`.
- Deactivate stale rows:
  - `where('is_active', true)->whereNotIn('external_id', $activeExternalIds)->update(['is_active' => false])`
- Dispatch `AlertCreated` for newly created or re-activated alerts (align with other fetch commands):
  - Add `NotificationAlertFactory::fromDrtAlert(DrtAlert $alert)` as needed.

Command signature:

- `drt:fetch-alerts`

---

## Phase 4: Queue Job Wrapper + Scheduler

Follow the existing scheduler pattern (command invoked via a unique queued job):

- `app/Jobs/FetchDrtAlertsJob.php` (modelled on `app/Jobs/FetchGoTransitAlertsJob.php`)
  - `Artisan::call('drt:fetch-alerts')`, throw on non-zero
  - `ShouldQueue` + `ShouldBeUnique`, `WithoutOverlapping` middleware
- Update `app/Services/ScheduledFetchJobDispatcher.php`
  - Add `dispatchDrtAlerts(): bool` that dispatches `FetchDrtAlertsJob`
- Update `routes/console.php`
  - `Schedule::call(fn (ScheduledFetchJobDispatcher $d) => $d->dispatchDrtAlerts())`
  - `->everyFiveMinutes()->withoutOverlapping(10)` (match TTC/GO cadence)

---

## Phase 5: Unified Alerts Provider (SelectProvider)

Create `app/Services/Alerts/Providers/DrtAlertSelectProvider.php` implementing:

- `App\Services\Alerts\Contracts\AlertSelectProvider`
  - `source(): string` returns `AlertSource::Drt->value`
  - `select(UnifiedAlertsCriteria $criteria): Builder`

Provider query should emit the unified columns:

- `id` as `{source}:{external_id}` (driver-specific concat, see other providers)
- `source` literal `'drt'`
- `external_id`
- `is_active`
- `timestamp` = `posted_at`
- `title`
- `location_name` = prefer `route_text` (or `NULLIF(route_text, '')`)
- `lat` / `lng` = `NULL`
- `meta` = JSON object built from table columns (`when_text`, `route_text`, `details_url`, `body_text`, `feed_updated_at`)

Criteria support:

- `source` filter
- `status` filter (active/cleared)
- `sinceCutoff` filter on `posted_at`
- `query` support:
  - SQLite: handled at `UnifiedAlertsQuery` level (title/location only)
  - MySQL/Postgres: mirror other providers’ approach (add fulltext index later if needed)

Register provider tag:

- Update `app/Providers/AppServiceProvider.php` to include `DrtAlertSelectProvider::class` in the `'alerts.select-providers'` tag list.

---

## Phase 6: AlertSource Enum

Update `app/Enums/AlertSource.php`:

- Add `case Drt = 'drt';`

---

## Phase 7: Frontend Domain (Inertia/React)

Add `drt` as a new `source` and `kind`.

1. Transport envelope:
   - Update `resources/js/features/gta-alerts/domain/alerts/resource.ts` source enum to include `'drt'`.
2. Mapper switch:
   - Update `resources/js/features/gta-alerts/domain/alerts/fromResource.ts` to handle `'drt'`.
3. Domain types:
   - Add `resources/js/features/gta-alerts/domain/alerts/transit/drt/schema.ts`
   - Add `resources/js/features/gta-alerts/domain/alerts/transit/drt/mapper.ts` (pattern-match `.../transit/go/mapper.ts`)
   - Update `resources/js/features/gta-alerts/domain/alerts/types.ts` union to include `DrtTransitAlert`.
4. Presentation:
   - Update `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.ts` to handle `kind: 'drt'`.
   - Reuse shared transit helpers in `resources/js/features/gta-alerts/domain/alerts/transit/presentation.ts`.

---

## Tests (Required)

Backend (Pest):

- Feed parser unit tests:
  - Store HTML fixtures for list + detail pages.
  - Assert parsing of `external_id`, `posted_at`, `when_text`, `route_text`, `details_url`.
- Command test:
  - Fake HTTP responses and assert upserts + deactivations.

Frontend (Vitest):

- `mapDrtAlert()` returns `DrtTransitAlert` for valid input and warns/returns `null` for invalid input.

Run minimal targeted tests via Sail:

- `vendor/bin/sail artisan test --compact tests/...`
- `vendor/bin/sail pnpm test` (or the repo’s existing frontend test command)

---

## Verification (Manual)

- `vendor/bin/sail artisan drt:fetch-alerts`
- Confirm `UnifiedAlertsQuery` returns DRT items:
  - `vendor/bin/sail artisan tinker --execute 'app(App\\Services\\Alerts\\UnifiedAlertsQuery::class)->cursorPaginate(App\\Services\\Alerts\\DTOs\\UnifiedAlertsCriteria::fromRequest([\"source\" => \"drt\"]))'`
