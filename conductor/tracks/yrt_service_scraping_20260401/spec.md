# Specification: YRT Service Advisories Integration

## Overview
Integrate York Region Transit (YRT) service advisories into the GTA Alerts unified-alerts pipeline by ingesting the YRT advisories JSON feed, persisting normalized alert records, exposing them via the existing provider-tagged unified query architecture, and mapping them into the Inertia + React alert domain.

Source planning reference: `docs/plans/YRT_Service_Implementation_Plan.md` (validated 2026-03-31).

## Architecture Direction
- Use `https://www.yrt.ca/Modules/NewsModule/services/getServiceAdvisories.ashx?...` as the source-of-truth feed.
- Preserve current backend flow:
  - `YrtAlert` persistence table
  - `YrtAlertSelectProvider` tagged in `alerts.select-providers`
  - `UnifiedAlertsQuery` union criteria flow
  - existing API resource + frontend domain mapper flow
- Keep YRT source identity distinct as `yrt`.
- Keep location coordinates `NULL` for YRT alerts (source has no lat/lng).
- Treat detail-page scraping as optional enrichment only when needed.

## Functional Requirements
- Persist YRT alerts in a dedicated `yrt_alerts` table with unique `external_id` upsert key.
- Implement `YrtAlert` model with typed casts and `active()` scope.
- Implement `YrtServiceAdvisoriesFeedService` that:
  - fetches the JSON list feed with timeout/retry + feed circuit breaker behavior
  - normalizes records into persisted fields (`external_id`, `title`, `posted_at`, `details_url`, `description_excerpt`, `route_text`, `list_hash`, optional `body_text`)
  - optionally fetches detail HTML only when required (new/changed/missing body/stale detail fetch timestamp)
- Implement sync command `yrt:fetch-alerts` that:
  - upserts active advisories
  - deactivates stale advisories missing from latest feed
  - dispatches `AlertCreated` for newly created or re-activated advisories
- Add queued/scheduled wrapper path through `FetchYrtAlertsJob` and `ScheduledFetchJobDispatcher::dispatchYrtAlerts()`.
- Add `YrtAlertSelectProvider` with unified columns and criteria filtering (`source`, `status`, `sinceCutoff`, `query`) matching provider contracts.
- Extend `AlertSource` enum with `Yrt = 'yrt'`.
- Extend frontend domain/resource mapping to support `kind: 'yrt'`, including mapper + presentation integration.

## Non-Functional Requirements
- Follow strict TDD for every implementation phase (red -> green -> refactor).
- Add failure-mode tests for feed outages, malformed JSON/HTML payloads, and empty-feed behavior.
- Apply existing resilience patterns (timeouts, retries, circuit-breaker, safe empty-feed behavior).
- Preserve existing unified alerts response contracts and avoid unrelated schema/API changes.
- Keep ingestion bounded and operationally safe (list processing cap, low concurrency, defensive parsing).

## Acceptance Criteria
- Running `vendor/bin/sail artisan yrt:fetch-alerts` ingests YRT advisories and keeps `yrt_alerts` synchronized.
- Re-running sync with unchanged list content skips unnecessary detail fetches based on `list_hash` + freshness rules.
- Unified alerts queries filtered by `source=yrt` return YRT rows with correct unified metadata shape.
- New or reactivated YRT advisories trigger expected notification event behavior.
- Frontend mapper/source handling renders YRT advisories as a first-class transit source without regressing existing sources.
- Track passes QA gates (targeted + full tests, coverage threshold, lint/type/format checks, audits) and required docs updates.

## Out of Scope
- Scraping the public advisories list HTML as primary source.
- Parsing unsupported or unreliable banner-feed endpoints.
- Geospatial enrichment (lat/lng inference) for YRT advisories.
- UI redesign outside source/domain integration needed for existing unified alert presentation.
