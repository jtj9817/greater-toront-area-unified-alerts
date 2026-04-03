# Specification: DRT Service Alerts and Detours Integration

## Overview
Integrate Durham Region Transit (DRT) “Service Alerts and Detours” into the GTA Alerts unified-alerts architecture by scraping the DRT HTML listing + detail pages, persisting normalized alert records, exposing them through the provider-tagged unified query, and mapping them into the Inertia + React domain model.

Primary planning reference: `docs/plans/DRT_Scraping_Implementation_Plan.md` (source validated on 2026-03-30).

## Business Goal
Add DRT as a first-class transit source in the same operational and UX pathways as existing sources without regressing current feed behavior, query performance, or frontend alert rendering.

## Source of Truth and Ingestion Boundaries
- Canonical listing page:
  - `GET https://www.durhamregiontransit.com/Modules/News/en/ServiceAlertsandDetours`
  - Pagination uses `?page=N`.
- Canonical detail page pattern:
  - `https://www.durhamregiontransit.com/en/news/{slug}.aspx`
- Not an ingestion source:
  - `/Modules/NewsModule/services/getAlertBannerFeeds.ashx` (observed 404 body error; treat as unreliable).
- No reliable RSS/JSON feed for Service Alerts/Detours was identified as of 2026-03-30; ingestion is HTML scraping only.

## Architecture Direction
Preserve the current unified alerts architecture:
- `DrtAlert` persistence model
- `DrtAlertSelectProvider` tagged in `alerts.select-providers`
- `UnifiedAlertsQuery` union criteria flow
- existing API resource and frontend domain mapping flow

Keep source identity explicit as `drt`. Persist coordinates as `NULL` (`lat`, `lng`) for all DRT rows. Follow existing feed resilience patterns already used by transit providers.

## Scraping Optimizations and Guard Rails
- List-first, detail-on-demand:
  - Always scrape list pages to define the active set.
  - Only fetch detail pages when needed for full text.
- Skip unchanged detail fetches:
  - Persist `list_hash` + `details_fetched_at`.
  - If existing row has matching `list_hash` and non-empty `body_text`, skip detail fetch.
  - Optional: refresh details when `details_fetched_at` is older than threshold (e.g. 24h).
- Parse only the content block on detail pages (avoid page chrome noise).
- Pagination guard rails:
  - Cap list pagination (e.g. max 10 pages) to prevent unexpected crawl expansion.
  - Keep concurrency low; sequential requests are acceptable.

## Persistence Contract
### Table
Create `drt_alerts` with these persisted fields:
- `external_id` (unique key; derived from detail URL slug)
- `title`
- `posted_at`
- `when_text` (nullable)
- `route_text` (nullable)
- `details_url`
- `body_text` (nullable)
- `list_hash` (nullable; sha1 hex)
- `details_fetched_at` (nullable)
- `is_active` (boolean, default true)
- `feed_updated_at` (nullable)
- Laravel timestamps

### Indexes
- unique index on `external_id`
- index on `posted_at`
- composite index on `is_active, posted_at`

### Model
- `app/Models/DrtAlert.php`
- `fillable` includes all persisted write fields.
- `casts()` includes:
  - datetime: `posted_at`, `details_fetched_at`, `feed_updated_at`
  - boolean: `is_active`
- `scopeActive()` returns only active records.

## Normalization Rules
For each scraped item, normalization must produce a deterministic record:
- `external_id`: basename slug from `details_url` pathname without `.aspx`.
- `title`: trimmed, must not be empty after trim.
- `posted_at`: parsed from list timestamp in `America/Toronto`, stored in UTC.
- `when_text` / `route_text`: best-effort extraction from labeled list/detail blocks; store raw, human-readable text.
- `details_url`: absolute URL; reject empty/non-URL values.
- `list_hash`: `sha1` of stable concatenation of list fields (title + posted timestamp + when/route + excerpt text).
- `feed_updated_at`: current UTC time for this scrape run.

## Detail Enrichment Rules
Detail page fetch is allowed only when at least one condition is true:
- alert is new, or
- `list_hash` changed since last sync, or
- `body_text` is empty/null, or
- `details_fetched_at` is older than configured refresh threshold.

When detail fetch runs:
- parse HTML defensively (invalid HTML must not crash sync).
- extract and normalize readable body text.
- set `details_fetched_at` to current UTC time.

When detail fetch is skipped:
- existing `body_text` remains unchanged.
- avoid network call to detail URL.

## Sync and Lifecycle Semantics (`drt:fetch-alerts`)
- Upsert all normalized rows from the latest list scrape with `is_active=true`.
- Deactivate stale rows currently active but absent from latest list by `external_id`.
- Do not hard-delete historical rows.
- Dispatch `AlertCreated` only for:
  - newly created rows, or
  - rows transitioning from inactive to active.
- Command must be idempotent across repeated runs with unchanged source content.

## Scheduled Execution Semantics
- Add `FetchDrtAlertsJob` queue wrapper calling `drt:fetch-alerts`.
- Job must:
  - implement unique job semantics
  - use overlap guard middleware
  - throw on non-zero Artisan exit code
- Register scheduled dispatch through `ScheduledFetchJobDispatcher::dispatchDrtAlerts()` at 5-minute cadence, consistent with existing transit feeds.

## Unified Provider Contract
`DrtAlertSelectProvider` must emit the unified select contract:
- `id`: prefixed source id (`drt:{external_id}`; driver-safe expression)
- `source`: literal `drt`
- `external_id`
- `is_active`
- `timestamp`: `posted_at`
- `title`
- `location_name`: mapped from `route_text` (nullable)
- `lat`, `lng`: `NULL`
- `meta`: JSON object containing at minimum:
  - `details_url`
  - `when_text`
  - `route_text`
  - `body_text`
  - `feed_updated_at`
  - `posted_at`

Provider must obey existing criteria semantics:
- `source`
- `status`
- `sinceCutoff`
- `query`

## Backend Contract Updates
- Add `Drt = 'drt'` to `AlertSource` enum.
- Ensure API resource/mapper paths accept and pass through `drt` consistently.
- Preserve compatibility for all existing source values.

## Frontend Contract
- Add `drt` in the unified resource source allow-list.
- Register `drt` in domain union types and `fromResource()` switch.
- Implement `mapDrtAlert()` with strict schema validation and null-safe metadata fallbacks.
- Map DRT presentation through shared transit presentation helpers (not TTC-specific branches).

## Non-Functional Requirements
- Strict TDD per phase: red -> green -> refactor.
- Add failure-mode tests for network errors, malformed HTML, parse failures, and empty feed scenarios (subject to `feeds.allow_empty_feeds`).
- Keep ingestion bounded and safe (pagination cap, conservative request behavior, no concurrency fan-out).
- Avoid unrelated API schema, UI redesign, or cross-domain refactors in this track.

## Implementation Phases
1. Phase 1: Database + Model
2. Phase 2: Feed Service (HTML List + Conditional Detail HTML)
3. Phase 3: Fetch Command (Sync + Notifications)
4. Phase 4: Queue Job Wrapper + Scheduler
5. Phase 5: Unified Alerts Provider
6. Phase 6: Source Enum + Backend Contract Plumbing
7. Phase 7: Frontend Domain + Presentation Integration
8. Phase 8: QA Phase
9. Phase 9: Documentation Phase (if required)

## Acceptance Criteria
1. `vendor/bin/sail artisan drt:fetch-alerts` ingests items into `drt_alerts` with correct active-state synchronization.
2. `UnifiedAlertsQuery` returns DRT items when filtered by `source=drt`.
3. Frontend renders DRT items with correct title/timestamp/route metadata and a working details URL.
4. Scheduled job dispatch runs every 5 minutes without overlaps and failures are observable via existing logging paths.
5. Targeted backend/frontend tests covering parsing, sync, and mapping are added and pass.

