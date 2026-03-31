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
