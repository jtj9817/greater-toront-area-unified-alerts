# Specification: MiWay Service Alerts (GTFS-RT) Integration

## Overview
Integrate MiWay (Mississauga Transit) service alerts into the GTA Alerts unified-alerts pipeline by ingesting the MiWay GTFS-RT Alerts protobuf feed, persisting normalized alert records, exposing them through the existing unified query architecture, and mapping them into the Inertia + React alert domain.

## Architecture Direction
- Use MiWay GTFS-RT Alerts protobuf (`https://www.miapp.ca/gtfs_rt/Alerts/Alerts.pb`) as the sole ingestion source of truth.
- Preserve current backend architecture:
  - `MiwayAlert` persistence table
  - `MiwayAlertSelectProvider` tagged into `alerts.select-providers`
  - `UnifiedAlertsQuery` union + criteria filtering
  - existing API resource + frontend domain mapper flow
- Keep MiWay source identity distinct as `miway` (not merged into existing `transit` source labels).
- Keep location coordinates `NULL` for MiWay alerts because source data does not include lat/lng.

## Functional Requirements
- Persist MiWay alerts in a dedicated `miway_alerts` table with a unique `external_id` upsert key.
- Fetch the protobuf feed with conditional headers (`If-None-Match`, `If-Modified-Since`) and skip writes on `304 Not Modified`.
- Parse and normalize alert payload fields into:
  - `external_id`, `header_text`, `description_text`
  - `cause`, `effect`
  - `starts_at`, `ends_at`
  - `url`, `detour_pdf_url`
  - `is_active`, `feed_updated_at`
- Implement a sync command (`miway:fetch-alerts`) that:
  - upserts active alerts
  - deactivates stale alerts not present in latest feed
  - dispatches `AlertCreated` for newly created or reactivated alerts
- Add a queued/scheduled fetch path using a unique job and the existing scheduled dispatcher pattern.
- Add a `MiwayAlertSelectProvider` that emits unified columns and supports criteria filters (`source`, `status`, `sinceCutoff`, `query`) consistent with existing providers.
- Extend `AlertSource` enum with `Miway = 'miway'`.
- Map unified MiWay resources to frontend domain models so the UI can render and filter MiWay alerts as a first-class source.

## Non-Functional Requirements
- Follow strict TDD for every implementation phase (red → green → refactor).
- Add failure-mode tests for feed outages, malformed payloads, and `304` short-circuit behavior.
- Use existing feed resilience patterns (`FeedCircuitBreaker`, retries, timeouts, safe empty-feed behavior).
- Do not fetch or parse detour PDF files; only persist/generate URLs.
- Preserve existing unified-alerts response contracts and avoid unrelated schema/API changes.

## Acceptance Criteria
- Running `vendor/bin/sail artisan miway:fetch-alerts` ingests MiWay alerts and updates `miway_alerts` accurately.
- Re-running fetch with unchanged ETag/Last-Modified performs no writes and exits cleanly.
- Unified alerts queries filtered by `source=miway` return MiWay rows with correct metadata shape.
- Newly created/reactivated MiWay alerts trigger notification event dispatch behavior aligned with other providers.
- Frontend mapper and source handling render MiWay alerts without breaking existing alert sources.
- Track passes QA gates (tests, coverage threshold, lint/type/format checks, audits) and is documented for operations/maintenance.

## Out of Scope
- Scraping the public service-updates HTML as a primary source.
- Downloading or parsing detour PDF document contents.
- Geospatial enrichment (lat/lng inference) for MiWay alerts.
- New UI redesign work beyond source/domain integration needed for existing unified alert presentation.

## Implementation Notes

The following deviations from the initial specification were discovered and resolved during implementation and QA:

### Unknown GTFS-RT Enum Values (Post-Phase-4 Hardening — b569d20)
GTFS-RT feeds may contain integer enum values for `cause`/`effect` that are not defined in the official GTFS-RT spec. The implementation uses `try/catch` on `Alert\Cause::name()` / `Alert\Effect::name()` to fall back to `'UNKNOWN_CAUSE'` / `'UNKNOWN_EFFECT'` rather than throwing. This ensures malformed upstream data does not crash the ingest pipeline.

### Empty-Feed Deactivation Guard Bug (Post-Phase-4 Hardening — e6d03df)
The original deactivation logic guarded `whereNotIn('external_id', $syncedIds)` with `if (count($syncedIds) > 0)`. When the feed returned zero alerts, `$syncedIds` was empty and the guard prevented deactivation of all active rows. Fixed by restructuring the query to always apply `where('is_active', true)` and conditionally add `whereNotIn` only when `$syncedIds !== []`.

### Binary `"0"` Body Handling (Post-Phase-4 Hardening — b569d20)
The condition `empty($body)` returned `false` for the string `"0"`, causing it to be passed to `mergeFromString()` where it failed with a cryptic protobuf error. Fixed with strict `$body === ''` comparison.

### UTC Normalization on 304/Empty Paths (Post-Phase-4 Hardening — b569d20)
`Carbon::now()` was used for the `updated_at` timestamp on 304 and empty-feed responses without calling `.utc()`. All `updated_at` values are now explicitly UTC-normalized.

### MiWay Presentation Gaps (FEED-051 — bc6c166 / af0bc7c)
After Phase 7 frontend integration, QA found two presentation-layer gaps:
1. `getSourceLabel()` in `AlertCard` and `AlertTableView` returned `undefined` for `kind === 'miway'` — fixed to return `'MiWay'`.
2. `AlertDetailsView` lacked a `buildMiwaySections()` branch, leaving MiWay alerts with no detail rendering — fixed with an orange-themed header, metadata panel, and specialized content callout.

### MySQL Fulltext Index Migration (Post-Phase-5 — 4eb507f)
The text-search query in `MiwayAlertSelectProvider` uses `MATCH...AGAINST` on MySQL/MariaDB. A post-Phase-5 migration (`2026_03_31_082123_add_fulltext_index_to_miway_alerts_table.php`) adds a fulltext index on `(header_text, description_text)` to support this efficiently on MySQL. No-op on SQLite/PostgreSQL (those drivers use `ILIKE`/`LIKE` fallback).

### AlertSource Enum (Post-Phase-5 — 4eb507f)
The `case Miway = 'miway'` entry in `AlertSource` enum was added during Phase 5 review to ensure the source value is explicitly registered in the enum, rather than relying on implicit string matching.
