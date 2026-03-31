# MiWay Service Alerts (GTFS-RT) Implementation Plan

Integrate **MiWay (Mississauga Transit)** service alerts into the GTA Alerts **Unified Alerts** architecture.

This plan is written to match the current codebase patterns (Laravel 12, Provider-tagged unified query, scheduled fetch job wrappers, Inertia + React domain mapping).

---

## Source Discovery (Research Findings)

Validated on **2026-03-31** using a browser DOM/network inspection and a direct HTTP fetch of the feed:

- **Primary feed:** GTFS Realtime **Alerts** protobuf
  - URL: `https://www.miapp.ca/gtfs_rt/Alerts/Alerts.pb`
  - `Content-Type: application/protocol-buffer`
  - Supports conditional fetch via **ETag** and **Last-Modified** headers (observed on 2026-03-31)
- **Public UI (derived):** `https://www.mississauga.ca/miway-transit/service-updates/`
  - Server-rendered accordion listing (no XHR/JSON endpoints observed)
  - Each route panel contains list items like:
    - `ul.alert-list-items > li.small.current > div.alert-text`
    - Links to detour PDFs via: `/miway-transit/r?url=https://www7.mississauga.ca/documents/miway/detours/{id}.pdf`
- **Developer download page (source of truth for endpoints):** `https://www.mississauga.ca/miway-transit/developer-download/`
  - Lists the three GTFS-RT feeds including “GTFS Real-Time Alerts Feed”

Observed in the Alerts protobuf payload (via `strings(...)` sampling on 2026-03-31):

- Route label strings like `2 Hurontario`, `17 Hurontario`, `103 Hurontario Express`
- Alert text describing stop relocations/closures (matches the public UI wording)
- Cause/effect-like strings such as `Construction` and `Stop Moved`
- A timestamp-like string in `YYYYMMDD HH:MM:SS` format (likely derived from active period start)

**Conclusion:** use the GTFS-RT Alerts protobuf as the primary ingestion source. Treat the public UI as a debugging/fallback reference only.

---

## Fetch Optimizations (Avoid Unnecessary Work)

1. **Single-request ingestion**
   - Fetch only the protobuf feed; do not scrape per-route UI pages.
2. **Conditional GET**
   - Persist last seen `ETag` and/or `Last-Modified` in cache (recommended) and send:
     - `If-None-Match: {etag}`
     - `If-Modified-Since: {last_modified}`
   - If the response is `304 Not Modified`, skip DB writes entirely.
3. **Do not fetch PDFs**
   - Store or derive PDF URLs for UI use, but never download PDF contents.
   - When `external_id` appears to be the detour bulletin ID, build:
     - `https://www7.mississauga.ca/documents/miway/detours/{external_id}.pdf`
4. **Polite defaults**
   - Timeouts + retries consistent with existing feed services.
   - Low/no concurrency (feed is a single request).

---

## Data Model (Observed Shape)

Persist these fields from GTFS-RT `Alert` entities:

- `external_id` — stable upsert key (prefer GTFS-RT entity id; observed to align with MiWay detour PDF IDs)
- `header_text` — route/summary label (e.g., `2 Hurontario`)
- `description_text` — full human text (stop closure/relocation details)
- `cause` / `effect` — if present (enum-to-string)
- `starts_at` / `ends_at` — derived from `active_period` ranges (store min start / max end)
- `url` — if present in the GTFS-RT alert (used by UI)
- `is_active` — true when present in the latest feed, false when missing
- `feed_updated_at` — scrape time (UTC) to aid ops/debugging

Coordinates are not present; unified `lat`/`lng` are always `NULL` for this source.

---

## Architecture Fit (GTA Alerts Unified Query)

Backend flow:

```
MiwayAlert table
  → MiwayAlertSelectProvider (tagged in AppServiceProvider)
  → UnifiedAlertsQuery (UNION)
  → API resources → Inertia/React domain mappers
```

Frontend flow:

```
UnifiedAlertResource (source: 'miway')
  → fromResource() switch
  → mapMiwayAlert()
  → presentation mapping (reuse transit presentation utilities)
```

---

## Phase 1: Database + Model

Create `miway_alerts` using the existing alert table conventions (see `database/migrations/2026_02_05_233653_create_go_transit_alerts_table.php`).

Suggested columns:

- `id`
- `external_id` (unique)
- `header_text` (string)
- `description_text` (text, nullable)
- `cause` (string, nullable)
- `effect` (string, nullable)
- `starts_at` (dateTime, nullable)
- `ends_at` (dateTime, nullable)
- `url` (string, nullable)
- `detour_pdf_url` (string, nullable)
- `is_active` (boolean default true)
- `feed_updated_at` (timestamp nullable)
- `created_at`, `updated_at`

Indexes:

- `index(['is_active', 'starts_at'])`
- `index('feed_updated_at')`

Model:

- `app/Models/MiwayAlert.php`
- `casts()` for `starts_at`, `ends_at`, `feed_updated_at` as datetime and `is_active` as boolean
- `scopeActive()` consistent with other alert models

---

## Phase 2: Feed Service (GTFS-RT Protobuf)

Add `app/Services/MiwayGtfsRtAlertsFeedService.php` following `app/Services/GoTransitFeedService.php` patterns:

- Use `Http::timeout(15)->retry(2, 200, throw: false)`.
- Use `FeedCircuitBreaker`:
  - `throwIfOpen('miway')`
  - `recordSuccess('miway')` / `recordFailure('miway', $exception)`
- Respect `config('feeds.allow_empty_feeds')`.
- Implement **conditional GET** using cached `ETag`/`Last-Modified`.

Parsing approach:

- Decode the protobuf to GTFS-RT types:
  - Preferred: add a production dependency on `google/protobuf` and generate PHP classes from `transit_realtime.proto`.
  - Extract `FeedMessage.entity[]` where `entity.alert` is present.
  - Prefer English translations (`translation.language == 'en'`), fallback to first translation.
- Normalize each alert to the persisted model fields:
  - `external_id`: `entity.id` (fallback to a hash if blank)
  - `header_text`: alert header/route label
  - `description_text`: alert description
  - `starts_at` / `ends_at`: min/max across active periods (if any)
  - `cause` / `effect`: map enums to strings
  - `url`: alert url (if present)
  - `detour_pdf_url`: derive from numeric-ish `external_id` (if applicable)

Return shape:

```php
// array{updated_at: CarbonInterface, alerts: list<array<string, mixed>>, not_modified?: bool}
```

---

## Phase 3: Fetch Command (Sync + Notifications)

Add `app/Console/Commands/FetchMiwayAlertsCommand.php` matching the error-handling style of:

- `app/Console/Commands/FetchTransitAlertsCommand.php`
- `app/Console/Commands/FetchGoTransitAlertsCommand.php`

Responsibilities:

- Call `MiwayGtfsRtAlertsFeedService->fetch()`.
- If `not_modified` is true, exit early (no DB writes).
- Upsert each item into `miway_alerts` with `is_active=true` and `feed_updated_at`.
- Deactivate stale rows:
  - `where('is_active', true)->whereNotIn('external_id', $activeExternalIds)->update(['is_active' => false])`
- Dispatch `AlertCreated` for newly created or re-activated alerts (align with other fetch commands):
  - Add `NotificationAlertFactory::fromMiwayAlert(MiwayAlert $alert)` as needed.

Command signature:

- `miway:fetch-alerts`

---

## Phase 4: Queue Job Wrapper + Scheduler

Follow the existing scheduler pattern (command invoked via a unique queued job):

- `app/Jobs/FetchMiwayAlertsJob.php` (modelled on `app/Jobs/FetchGoTransitAlertsJob.php`)
  - `Artisan::call('miway:fetch-alerts')`, throw on non-zero
  - `ShouldQueue` + `ShouldBeUnique`, `WithoutOverlapping` middleware
- Update `app/Services/ScheduledFetchJobDispatcher.php`
  - Add `dispatchMiwayAlerts(): bool` that dispatches `FetchMiwayAlertsJob`
- Update `routes/console.php`
  - `Schedule::call(fn (ScheduledFetchJobDispatcher $d) => $d->dispatchMiwayAlerts())`
  - `->everyFiveMinutes()->withoutOverlapping(10)` (match TTC/GO cadence)

---

## Phase 5: Unified Alerts Provider (SelectProvider)

Create `app/Services/Alerts/Providers/MiwayAlertSelectProvider.php` implementing:

- `App\Services\Alerts\Contracts\AlertSelectProvider`
  - `source(): string` returns `AlertSource::Miway->value`
  - `select(UnifiedAlertsCriteria $criteria): Builder`

Provider query should emit the unified columns:

- `id` as `{source}:{external_id}` (driver-specific concat, see other providers)
- `source` literal `'miway'`
- `external_id`
- `is_active`
- `timestamp` = `COALESCE(starts_at, feed_updated_at, updated_at)`
- `title` = `header_text`
- `location_name` = `header_text`
- `lat` / `lng` = `NULL`
- `meta` = JSON object built from table columns (`description_text`, `cause`, `effect`, `starts_at`, `ends_at`, `url`, `detour_pdf_url`, `feed_updated_at`)

Criteria support:

- `source` filter
- `status` filter (active/cleared)
- `sinceCutoff` filter on the chosen timestamp column(s)
- `query` support consistent with other providers (MySQL/Postgres can add richer query later if needed)

Register provider tag:

- Update `app/Providers/AppServiceProvider.php` to include `MiwayAlertSelectProvider::class` in the `'alerts.select-providers'` tag list.

---

## Phase 6: AlertSource Enum

Update `app/Enums/AlertSource.php`:

- Add `case Miway = 'miway';`

---

## Phase 7: Frontend Domain

Same pattern as the DRT plan Phase 7:

```
UnifiedAlertResource (source: 'miway')
  → fromResource() switch case 'miway'
  → mapMiwayAlert()
  → MiwayTransitAlert (kind: 'miway')
```

Notes:

- Keep MiWay as its own `source` (`'miway'`) so the UI can filter it separately from TTC (`'transit'`) and GO (`'go_transit'`).
- Presentation can reuse the existing transit presentation utilities (severity + metadata builders), as long as the MiWay domain schema extends the same base transit schema.

---

## Verification

```bash
vendor/bin/sail artisan miway:fetch-alerts
vendor/bin/sail artisan tinker --execute 'MiwayAlert::count(); MiwayAlert::latest("feed_updated_at")->first();'
```

Run the unified feed query:

```bash
vendor/bin/sail artisan tinker --execute 'app(\App\Services\Alerts\UnifiedAlertsQuery::class)->cursorPaginate(\App\Services\Alerts\DTOs\UnifiedAlertsCriteria::fromRequest(["source" => "miway"]))'
```
