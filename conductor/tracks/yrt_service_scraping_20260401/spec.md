# Specification: YRT Service Advisories Integration

## Overview
Integrate York Region Transit (YRT) service advisories into the GTA Alerts unified-alerts architecture by ingesting the YRT advisories JSON feed, normalizing and persisting alert records, exposing them through the provider-tagged unified query flow, and mapping them into the Inertia + React domain model.

Primary planning reference: `docs/plans/YRT_Service_Implementation_Plan.md` (validated on 2026-03-31).

## Business Goal
Add YRT as a first-class transit source in the same operational and UX pathways as existing sources (TTC, GO, MiWay) without regressing current feed behavior or frontend alert rendering.

## Source of Truth and Ingestion Boundaries
- Canonical feed endpoint:
  - `GET https://www.yrt.ca/Modules/NewsModule/services/getServiceAdvisories.ashx?categories=b8f1acba-f043-ec11-9468-0050569c41bf&lang=en`
- Source returns a JSON array payload (served with JavaScript-compatible content type).
- The HTML advisories page is not an ingestion source.
- Detail pages are optional enrichment only and must never replace list-feed authority for active/inactive state.

## Architecture Direction
- Preserve the current unified alerts architecture:
  - `YrtAlert` persistence model
  - `YrtAlertSelectProvider` tagged in `alerts.select-providers`
  - `UnifiedAlertsQuery` union criteria flow
  - existing API resource and frontend domain mapping flow
- Keep source identity explicit as `yrt`.
- Persist coordinates as `NULL` (`lat`, `lng`) for all YRT rows.
- Follow established feed resilience patterns already used by transit providers.

## Persistence Contract
### Table
Create `yrt_alerts` with these persisted fields:
- `external_id` (unique key)
- `title`
- `posted_at`
- `details_url`
- `description_excerpt` (nullable)
- `route_text` (nullable)
- `body_text` (nullable)
- `list_hash` (nullable, sha1 hex)
- `details_fetched_at` (nullable)
- `is_active` (boolean, default true)
- `feed_updated_at` (nullable)
- Laravel timestamps

### Indexes
- unique index on `external_id`
- index on `posted_at`
- index on `feed_updated_at`
- composite index on `is_active, posted_at`

### Model
- `app/Models/YrtAlert.php`
- `fillable` includes all persisted write fields.
- `casts()` includes:
  - datetime: `posted_at`, `details_fetched_at`, `feed_updated_at`
  - boolean: `is_active`
- `scopeActive()` returns only active records.

## Normalization Rules
For each feed item, normalization must produce a deterministic record:
- `external_id`: slug from `details_url` pathname basename without `.aspx`.
- `title`: trimmed feed title, must not be empty after trim.
- `posted_at`: parsed from `postedDate + postedTime` in `America/Toronto`, stored in UTC.
- `details_url`: absolute URL from feed `link`; reject empty/non-URL values.
- `description_excerpt`: normalized whitespace feed description (nullable when empty).
- `route_text`: best-effort derivation with this priority:
  1. route-like title prefix (`^[0-9]{1,3}\s*[-]` pattern family)
  2. `Routes affected:` or `Route affected:` segment in excerpt/body text
  3. `NULL` when no reliable route text
- `list_hash`: `sha1` of stable concatenation of list fields (`title|description|postedDate|postedTime|link`).

## Detail Enrichment Rules
Detail page fetch is allowed only when at least one condition is true:
- advisory is new, or
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

## Sync and Lifecycle Semantics (`yrt:fetch-alerts`)
- Upsert all normalized rows from the latest list feed with `is_active=true`.
- Deactivate stale rows currently active but absent from latest feed by `external_id`.
- Do not hard-delete historical rows.
- Dispatch `AlertCreated` only for:
  - newly created rows, or
  - rows transitioning from inactive to active.
- Command must be idempotent across repeated runs with unchanged source content.

## Scheduled Execution Semantics
- Add `FetchYrtAlertsJob` queue wrapper calling `yrt:fetch-alerts`.
- Job must:
  - implement unique job semantics
  - use overlap guard middleware
  - throw on non-zero Artisan exit code
- Register scheduled dispatch through `ScheduledFetchJobDispatcher::dispatchYrtAlerts()` at 5-minute cadence, consistent with existing transit feeds.

## Unified Provider Contract
`YrtAlertSelectProvider` must emit the unified select contract:
- `id`: prefixed source id (`yrt:{external_id}`; driver-safe expression)
- `source`: literal `yrt`
- `external_id`
- `is_active`
- `timestamp`: `posted_at`
- `title`
- `location_name`: mapped from `route_text` (nullable)
- `lat`, `lng`: `NULL`
- `meta`: JSON object containing at minimum:
  - `details_url`
  - `description_excerpt`
  - `body_text`
  - `posted_at`
  - `feed_updated_at`

Provider must obey existing criteria semantics:
- `source`
- `status`
- `sinceCutoff`
- `query`

## Backend Contract Updates
- Add `Yrt = 'yrt'` to `AlertSource` enum.
- Ensure API resource/mapper paths accept and pass through `yrt` consistently.
- Preserve compatibility for all existing source values.

## Frontend Contract
- Add YRT domain schema/mapper under transit domain path.
- Register source `yrt` in:
  - resource schema source enum
  - domain union types
  - `fromResource()` switch
- Implement `mapYrtAlert()` with strict schema validation and null-safe metadata fallbacks.
- Map YRT presentation through shared transit presentation helpers (not TTC-specific branches).

## Non-Functional Requirements
- Strict TDD per phase: red -> green -> refactor.
- Add failure-mode tests for network errors, malformed payloads, parse failures, and empty feed scenarios.
- Keep ingestion bounded and safe:
  - list processing cap
- conservative request behavior
- no uncontrolled concurrency fan-out
- Avoid unrelated API schema, UI redesign, or cross-domain refactors in this track.

## Implementation Phases
1. Phase 1: Database + Model
2. Phase 2: Feed Service (List JSON + Conditional Detail HTML)
3. Phase 3: Fetch Command (Sync + Notifications)
4. Phase 4: Queue Job Wrapper + Scheduler
5. Phase 5: Unified Alerts Provider
6. Phase 6: Source Enum + Backend Contract Plumbing
7. Phase 7: Frontend Domain + Presentation Integration
8. Phase 8: QA Phase
9. Phase 9: Documentation Phase

## Documentation Deliverables (Phase 9)

### New Documentation To Create
- `docs/sources/yrt.md` as the canonical YRT source integration reference.

### Existing Documentation To Update
- `docs/README.md`:
  - include YRT in docs tree, current scope, source integration list, and implementation status table.
- `docs/backend/unified-alerts-system.md`:
  - include `yrt` in source coverage and filter enum documentation.
- `docs/backend/enums.md`:
  - include `AlertSource::Yrt` and updated enum value lists/snippets.
- `docs/backend/dtos.md`:
  - include `yrt` anywhere source allow-lists are documented.
- `docs/backend/architecture-walkthrough.md`:
  - include YRT service/command/job/provider and scheduler wiring in topology and examples.
- `docs/backend/unified-alerts-qa.md`:
  - update source-set references and source-onboarding guidance to reflect YRT as implemented.
- `docs/backend/database-schema.md`:
  - add `yrt_alerts` schema/index/provider/migration history and related cross-links.
- `docs/backend/production-scheduler.md`:
  - update enumerated fetch-source examples so YRT is consistently represented.
- `docs/backend/notification-system.md`:
  - update transit-family matching notes if YRT metadata participates in subscription-route matching.
- `docs/frontend/alert-service.md`:
  - include `yrt` in source dispatch documentation.
- `docs/frontend/types.md`:
  - include YRT domain type/schema/mapper and meta/presentation notes.
- `docs/frontend/alert-location-map.md`:
  - include YRT in source-coverage table for coordinate availability semantics.
- `docs/CHANGELOG.md`:
  - add a dated entry summarizing YRT documentation additions/changes.

### Documentation Quality Gates
- Source-list consistency check across updated docs for: `fire`, `police`, `transit`, `go_transit`, `miway`, `yrt`.
- Internal link verification for newly added `yrt` references.
- Explicit note of any intentionally deferred documentation updates in track implementation notes.

## Acceptance Criteria
1. `vendor/bin/sail artisan yrt:fetch-alerts` ingests advisories into `yrt_alerts` with correct active-state synchronization.
2. Unchanged advisories avoid unnecessary detail fetches based on `list_hash` and freshness rules.
3. `source=yrt` unified query returns contract-valid rows and metadata.
4. Newly created and reactivated YRT advisories trigger expected notification event behavior.
5. Frontend domain mapping renders YRT alerts with existing feed UI without source regressions.
6. Quality gates pass (targeted suites, full tests, coverage threshold, lint/type/format, audits).

## Out of Scope
- HTML list-page scraping as primary ingestion path.
- Banner-feed endpoint ingestion.
- Geospatial enrichment (lat/lng inference) for YRT alerts.
- New visual redesign work outside minimal source integration.
