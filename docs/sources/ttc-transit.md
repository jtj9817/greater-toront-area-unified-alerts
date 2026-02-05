# TTC Transit Alerts - Data Extraction & Integration Plan

## Context

The Toronto Transit Commission (TTC) provides service alerts, planned closures, construction notices, and accessibility information across multiple web properties. Unlike the Fire (XML) and Police (ArcGIS REST) sources which each have a single feed, the TTC distributes data across three distinct technical systems, requiring a composite scraping strategy.

This document outlines the reverse-engineered API architecture, data schemas, and implementation plan for integrating TTC transit alerts into the GTA Alerts unified dashboard.

---

## Data Sources (Reverse-Engineered)

### Source 1: `alerts.ttc.ca` JSON API (Primary, Real-Time)

The TTC's main service alerts page (`ttc.ca/service-alerts`) loads data from a separate alerts API at `alerts.ttc.ca`. This is a clean JSON API requiring no authentication.

**Endpoints:**

| Endpoint | Purpose | Key Data |
|---|---|---|
| `GET https://alerts.ttc.ca/api/alerts/live-alerts` | Full live state (recommended) | `routes[]`, `accessibility[]`, `siteWideCustom[]`, `generalCustom[]`, `stops[]` |
| `GET https://alerts.ttc.ca/api/alerts/list` | Route alerts only | `routes[]` |
| `GET https://alerts.ttc.ca/api/alerts/site-wide` | Banner alerts | `siteWideCustom[]`, `siteWide` |

**Cache Control:** `public, max-age=25, no-store` (refreshes every ~25 seconds)

**Discovery Method:** Network request interception via Playwright on `ttc.ca/service-alerts`. The page makes XHR requests to these endpoints on load and when switching between "Line & route alerts", "Accessibility alerts", and "General alerts" tabs. Tab filtering is client-side; the API returns all data.

**Response Envelope:**
```json
{
  "total": 24,
  "lastUpdated": "2026-02-03T04:41:06.633Z",
  "rszLastUpdated": "2026-02-02T10:58:13.807Z",
  "routes": [ ... ],           // 16 route/line alerts
  "accessibility": [ ... ],    // 7 elevator alerts
  "siteWideCustom": [ ... ],   // 1 banner alert
  "generalCustom": [],         // general notices
  "stops": [],                 // stop-level alerts
  "status": "success"
}
```

**Alert Object Schema:**
```json
{
  "id": "61748",
  "priority": 1,
  "alertType": "Planned",
  "lastUpdated": "2026-02-02T10:22:53.697Z",
  "activePeriod": {
    "start": "2026-02-02T10:22:53.697Z",
    "end": "2026-02-05T23:00:00Z"
  },
  "activePeriodGroup": ["Current"],
  "routeOrder": 1,
  "route": "1",
  "routeBranch": "",
  "routeTypeSrc": "400",
  "routeType": "Subway",
  "stopStart": "Finch",
  "stopEnd": "Eglinton",
  "title": "There will be no subway service between Finch and Eglinton stations...",
  "description": "",
  "url": "https://files.ttc.ca/public-images/public-image-639056245751675264.png",
  "urlPlaceholder": "",
  "accessibility": "Routes",
  "effect": "REDUCED_SERVICE",
  "effectDesc": "Subway Closure - Early Access",
  "severityOrder": 1,
  "severity": "Critical",
  "customHeaderText": "Line 1 Yonge-University: ...",
  "headerText": "Line 1 Yonge-University: ...",
  "direction": "Both Ways",
  "cause": "OTHER_CAUSE",
  "causeDescription": "Other",
  "stopIDList": ["Eglinton", "Lawrence", "York Mills", "Sheppard-Yonge", "North York Centre", "Finch"],
  "stopNameList": [],
  "stopRouteList": [],
  "rszLength": null,
  "distance": null,
  "trackPercent": null,
  "reducedSpeed": null,
  "averageSpeed": null,
  "targetRemoval": null,
  "shuttleType": "Will Operate",
  "elevatorCode": null,
  "childAlerts": [
    { "id": "61750", "startTime": "2026-02-03T23:00:00Z", "endTime": "2026-02-04T03:30:00Z" },
    { "id": "61751", "startTime": "2026-02-04T23:00:00Z", "endTime": "2026-02-05T03:30:00Z" }
  ]
}
```

**Elevator/Accessibility Alert Example (from `accessibility[]` array):**
```json
{
  "id": "61793",
  "alertType": "Planned",
  "routeTypeSrc": "1303",
  "routeType": "Elevator",
  "route": "4,10,24,25,85,167,169,185,325,385,904,925,985",
  "title": "Elevator out of service between Sheppard Ave E north side entrance and concourse.",
  "accessibility": "Elevator",
  "effect": "ACCESSIBILITY_ISSUE",
  "effectDesc": "Out of service",
  "severity": "Critical",
  "headerText": "Don Mills: Elevator out of service between Sheppard Ave E north side entrance and concourse.",
  "elevatorCode": "61P1L",
  "url": "https://www.ttc.ca/subway-stations/don-mills-station"
}
```

**Field Reference:**

| API Field | DB Column | Type | Description |
|---|---|---|---|
| `id` | `external_id` (prefixed `api:`) | string | Unique alert identifier |
| `alertType` | `alert_type` | string | `Planned`, `SiteWide` |
| `routeType` | `route_type` | string | `Subway`, `Bus`, `Streetcar`, `Elevator` |
| `route` | `route` | string | Route number(s), comma-separated for elevators |
| `title` | `title` | string | Alert title text |
| `description` | `description` | text | Full description (may contain HTML) |
| `severity` | `severity` | string | `Critical`, `Minor` |
| `effect` | `effect` | string | `REDUCED_SERVICE`, `DETOUR`, `SIGNIFICANT_DELAYS`, `ACCESSIBILITY_ISSUE` |
| `cause` / `causeDescription` | `cause` | string | Cause description |
| `activePeriod.start` | `active_period_start` | datetime | ISO8601 UTC timestamp |
| `activePeriod.end` | `active_period_end` | datetime | ISO8601 UTC (`0001-01-01T00:00:00Z` = indefinite) |
| `direction` | `direction` | string | `Both Ways`, `Northbound To Finch`, etc. |
| `stopStart` | `stop_start` | string | Start station/stop name |
| `stopEnd` | `stop_end` | string | End station/stop name |
| `url` | `url` | string | Link to TTC page or image |
| `elevatorCode` | (in meta) | string | Elevator identifier (e.g., `61P1L`) |
| `shuttleType` | (in meta) | string | `Will Operate`, null |
| `childAlerts` | (flattened) | array | Sub-alerts with their own time windows |

**Enum Values Observed:**

| Field | Values |
|---|---|
| `alertType` | `Planned`, `SiteWide` |
| `routeType` | `Subway`, `Bus`, `Streetcar`, `Elevator` |
| `effect` | `REDUCED_SERVICE`, `DETOUR`, `SIGNIFICANT_DELAYS`, `ACCESSIBILITY_ISSUE`, `null` |
| `severity` | `Critical`, `Minor` |
| `cause` | `OTHER_CAUSE`, `MAINTENANCE`, `null` |
| `direction` | `Both Ways`, `Northbound To Finch`, `Southbound From Finch`, `Northbound To Vaughan`, `Southbound From Vaughan`, `Eastbound`, `Westbound`, `null` |

---

### Source 2: Sitecore SXA Search API (Secondary, CMS Content)

The TTC website runs on Sitecore CMS with the SXA (Sitecore Experience Accelerator) module. Service advisory pages use an AJAX search component that queries a Sitecore search index. Each page section has unique scope GUIDs.

**URL Pattern:**
```
https://www.ttc.ca//sxa/search/results/?s={SCOPE_GUID}&itemid={ITEM_GUID}&sig=&autoFireSearch=true&v={VIEW_GUID}&p=10&o=EffectiveStartDate,Ascending
```

**Page GUIDs:**

| Page | Scope GUID (`s=`) | Item GUID (`itemid=`) |
|---|---|---|
| Service Changes | `{F79E7245-3705-4E03-827E-02569508B481}` | `{B3DD22A4-3F53-4470-A87A-37A77976B07F}` |
| Subway Service | `{99D7699F-DB47-4BB1-8946-77561CE7B320}` | `{72CC555F-9128-4581-AD12-3D04AB1C87BA}` |
| Construction Notices | `{FB2F6677-50FB-4294-9A0B-34DD78C8EF45}` | `{55AF6373-A0DF-4781-8282-DCAFFF6FA53E}` |
| Accessibility Advisories | `{2EF860AF-9B7D-4460-8281-D428D8E09DC4}` | `{AE874E1E-461E-4EF2-BB4F-8C5A50B6C825}` |

**Response Format:**
```json
{
  "TotalTime": 157,
  "QueryTime": 106,
  "Signature": "",
  "Index": "sitecore_sxa_web_index",
  "Count": 15,
  "Results": [
    {
      "Id": "4976b805-daf7-43f7-96c1-c3da717a7877",
      "Language": "en",
      "Path": "/sitecore/content/TTC/DevProto/Home/service-advisories/Service Changes/510 310 ...",
      "Url": "/service-advisories/Service-Changes/510-310-Temporary-service-change...",
      "Name": null,
      "Html": "<a title=\"...\" href=\"...\"><div class=\"sa-title ...\"><span class=\"field-route\">510|310</span><span class=\"sa-dash\">&#8211;</span><span class=\"field-satitle\">Temporary service change...</span></div></a><div class=\"sa-effective-date ...\"><span class=\"ed-start-date field-starteffectivedate\">February 2, 2026 - 11:00 PM</span><span class=\"effective-date-tolabel\">to </span><span class=\"field-endeffectivedate\">February 5, 2026 - 04:00 AM</span></div>"
    }
  ]
}
```

**HTML Fragment Structure (CSS classes for parsing):**

| CSS Class | Content | Example |
|---|---|---|
| `field-route` | Route number(s) | `510\|310`, `97`, `1` |
| `field-routename` | Route name (lowercase) | `yonge`, `runnymede` |
| `field-satitle` | Advisory title | `Temporary route change due to sewer installation` |
| `field-starteffectivedate` | Start date | `February 2, 2026 - 11:00 PM` |
| `field-endeffectivedate` | End date | `February 5, 2026 - 04:00 AM` |

**Facets Endpoint (for filter dropdowns):**
```
https://www.ttc.ca//sxa/search/facets/?f=saroutelist||sastationlist&s={SCOPE}&itemid={ITEM}&sig=
```

**Pagination:** `p=10` controls page size. Paginate by incrementing the `e` parameter (offset).

---

### Source 3: Static CMS Page (Tertiary)

**URL:** `https://www.ttc.ca/service-advisories/Streetcar-Service-Changes`

The streetcar service changes page is a standard Sitecore-rendered page with no dynamic API calls. Content is server-rendered HTML with expandable/collapsible accordion sections. Requires HTML parsing via DOMDocument or similar.

**Discovery:** No SXA search requests observed in network traffic. Only `alerts.ttc.ca` live-alerts and site-wide API calls (for the footer banner), which are unrelated to the page content.

---

## Supplementary APIs

**Route Detail API** (for enrichment, not primary scraping):
```
GET https://www.ttc.ca/ttcapi/routedetail/listroutes?routeIds=1,2,337
```
Returns route metadata: `longName`, `shortName`, `type`, `serviceLevel`, `mapUrl`, `inService`, etc. Could be used to enrich transit alerts with full route names.

**Site-Wide Alert URL Resolution:**
```
GET https://www.ttc.ca/ttcapi/routedetail/GetSiteWideAlertApiUrl
```
Returns: `"https://alerts.ttc.ca/api/alerts/site-wide"` - used by the TTC website to discover the alerts API base URL.

---

## Implementation Plan

### Architecture

Single `transit_alerts` table for all three sources. A `source_feed` column distinguishes origin. External IDs are prefixed to ensure uniqueness across sources.

```
app/
├── Services/
│   └── TtcAlertsFeedService.php           # Composite: JSON API + SXA + static
├── Console/Commands/
│   └── FetchTransitAlertsCommand.php      # transit:fetch-alerts
├── Jobs/
│   └── FetchTransitAlertsJob.php          # Queue wrapper
├── Models/
│   └── TransitAlert.php                   # Eloquent model
├── Services/Alerts/Providers/
│   └── TransitAlertSelectProvider.php     # Replace existing stub
database/
├── migrations/
│   └── [timestamp]_create_transit_alerts_table.php
├── factories/
│   └── TransitAlertFactory.php
```

### External ID Strategy

| Source | Format | Example |
|---|---|---|
| JSON API | `api:{id}` | `api:61748` |
| SXA Search | `sxa:{sitecore_guid}` | `sxa:4976b805-daf7-43f7-96c1-c3da717a7877` |
| Static CMS | `static:{md5(title+route)}` | `static:a1b2c3d4e5f6...` |

In the unified layer, these become `transit:api:61748`, `transit:sxa:{guid}`, etc.

### Database Schema

Table: `transit_alerts`

```sql
id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY
external_id           VARCHAR(255) UNIQUE       -- 'api:61748', 'sxa:{guid}', 'static:{hash}'
source_feed           VARCHAR(255)              -- 'live-api', 'sxa', 'static'
alert_type            VARCHAR(255) NULLABLE     -- 'Planned', 'SiteWide'
route_type            VARCHAR(255) NULLABLE     -- 'Subway', 'Bus', 'Streetcar', 'Elevator'
route                 VARCHAR(255) NULLABLE     -- Route number(s)
title                 VARCHAR(255)              -- Alert title
description           TEXT NULLABLE             -- Full description
severity              VARCHAR(255) NULLABLE     -- 'Critical', 'Minor'
effect                VARCHAR(255) NULLABLE     -- 'REDUCED_SERVICE', 'DETOUR', etc.
cause                 VARCHAR(255) NULLABLE     -- Cause description
active_period_start   DATETIME NULLABLE         -- When alert becomes active
active_period_end     DATETIME NULLABLE         -- When alert expires
direction             VARCHAR(255) NULLABLE     -- 'Both Ways', 'Northbound', etc.
stop_start            VARCHAR(255) NULLABLE     -- Start station/stop
stop_end              VARCHAR(255) NULLABLE     -- End station/stop
url                   VARCHAR(255) NULLABLE     -- Link to TTC page
is_active             BOOLEAN DEFAULT TRUE
feed_updated_at       TIMESTAMP NULLABLE
created_at            TIMESTAMP
updated_at            TIMESTAMP

INDEX (is_active, active_period_start)
INDEX (source_feed)
INDEX (route_type)
INDEX (feed_updated_at)
```

### Feed Service Design

`TtcAlertsFeedService::fetch()` returns `array{updated_at: Carbon, alerts: list<array>}`.

Internally delegates to three methods:

1. **`fetchLiveApi()`** (critical): `Http::timeout(15)->retry(2, 200)->acceptJson()->get(...)`. Iterates `routes[]`, `accessibility[]`, `siteWideCustom[]`, `generalCustom[]`. Throws `RuntimeException` on failure.

2. **`fetchSxaPages()`** (non-critical): Fetches 4 SXA endpoints. Parses HTML fragments via DOMDocument for `field-route`, `field-satitle`, dates. Logs warnings on failure, returns empty array.

3. **`fetchStaticPage()`** (non-critical): Fetches streetcar page HTML. Parses accordion sections. Uses `md5(title+route)` for deterministic IDs. Logs warnings on failure, returns empty array.

### Unified Provider Mapping

`TransitAlertSelectProvider` maps `transit_alerts` to the unified schema:

| Unified Column | Source Expression |
|---|---|
| `id` | `'transit:' \|\| external_id` |
| `source` | `'transit'` |
| `external_id` | `external_id` |
| `is_active` | `is_active` |
| `timestamp` | `COALESCE(active_period_start, created_at)` |
| `title` | `title` |
| `location_name` | Constructed from `route` + `stop_start` / `stop_end` |
| `lat` | `NULL` (route-based, not point-based) |
| `lng` | `NULL` |
| `meta` | JSON: `route_type`, `route`, `severity`, `effect`, `source_feed`, `alert_type`, `description` |

### Frontend Changes (`AlertService.ts`)

- **Severity**: `severity='Critical'` -> high; `effect` in (SIGNIFICANT_DELAYS, REDUCED_SERVICE) -> medium; else low
- **Description**: Built from `meta.route_type`, `meta.route`, `meta.effect`, `meta.description`
- **Icons**: Route-type aware: subway -> `directions_subway`, bus -> `directions_bus`, streetcar -> `tram`, elevator -> `elevator`

### Scheduling

```php
// routes/console.php
Schedule::command('transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping();
```

Matches the fire schedule frequency. The JSON API cache is 25s, but 5-minute polling is appropriate for dashboard use. `withoutOverlapping()` prevents concurrent fetches since SXA pages may be slow.

---

## Files to Create

| File | Purpose |
|------|---------|
| `database/migrations/..._create_transit_alerts_table.php` | Schema |
| `app/Models/TransitAlert.php` | Eloquent model with `HasFactory`, `scopeActive()`, casts |
| `database/factories/TransitAlertFactory.php` | Factory with `inactive()`, `subway()`, `elevator()`, `sxa()` states |
| `app/Services/TtcAlertsFeedService.php` | Composite feed service |
| `app/Console/Commands/FetchTransitAlertsCommand.php` | `transit:fetch-alerts` command |
| `app/Jobs/FetchTransitAlertsJob.php` | Queue job wrapper |
| `tests/Unit/Models/TransitAlertTest.php` | Model unit tests |
| `tests/Unit/Services/Alerts/Providers/TransitAlertSelectProviderTest.php` | Provider tests |
| `tests/Feature/Services/TtcAlertsFeedServiceTest.php` | Feed service tests |
| `tests/Feature/Commands/FetchTransitAlertsCommandTest.php` | Command tests |
| `tests/Feature/Jobs/FetchTransitAlertsJobTest.php` | Job tests |

## Files to Modify

| File | Change |
|------|--------|
| `app/Services/Alerts/Providers/TransitAlertSelectProvider.php` | Replace `WHERE 1=0` stub with real implementation |
| `app/Http/Controllers/GtaAlertsController.php` | Add `TransitAlert` to `latestFeedUpdatedAt()` |
| `routes/console.php` | Add schedule entry |
| `resources/js/features/gta-alerts/services/AlertService.ts` | Transit severity, description, icon mapping |
| `database/seeders/UnifiedAlertsTestSeeder.php` | Add transit alert rows |
| `tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php` | Update assertions for transit |
| `tests/Feature/GtaAlertsTest.php` | Add transit to test scenarios |

---

## Observations & Constraints

- **Update Frequency:** The JSON API refreshes every ~25 seconds (per cache headers). SXA content changes are editorial and infrequent (days/weeks).
- **No Authentication:** All endpoints are publicly accessible with no API keys, tokens, or CORS restrictions for server-side access.
- **Alert Lifecycle:** The JSON API only returns currently-active alerts. Once resolved, alerts disappear from the feed. We mirror this by marking alerts inactive when absent from the feed.
- **`activePeriod.end` Sentinel:** The value `0001-01-01T00:00:00Z` indicates an indefinite/unknown end time. Should be treated as `null`.
- **HTML in Descriptions:** The `description` field from the JSON API may contain HTML (anchor tags, etc.). Should be stripped with `strip_tags()` before storage.
- **SXA Fragility:** Sitecore CMS template changes can break SXA HTML parsing without notice. Wrap per-result parsing in try/catch and log warnings.
- **Static Page IDs:** The streetcar changes page has no natural unique identifiers. Deterministic MD5 hashing of normalized content provides stable-enough IDs; wording changes produce new alerts.
- **Child Alerts:** Some alerts have `childAlerts[]` with specific time windows (e.g., nightly closures). These can be flattened into separate rows or stored in meta - flattening is recommended for independent lifecycle tracking.
- **Multi-Route Elevator Alerts:** Elevator alerts may list many routes (e.g., `"4,10,24,25,85,167,169,185,325,385,904,925,985"`). Store the full comma-separated string; parsing is a frontend concern.

---

## Architectural Review & Recommendations (2026-02-05)

### Risks & Mitigations

| Risk | Impact | Mitigation |
|---|---|---|
| **WAF / Bot Detection** | Hetzner VPS IPs are often flagged as bots, leading to 403 Forbidden errors. | Spoof a modern browser `User-Agent` and include legitimate `Referer` headers in all HTTP calls. |
| **DOM Fragility** | Changes to TTC theme/HTML will cause Source 3 (Static) to fail silently. | Implement "Zero-Result Warning" logic to log an alert if the scraper consistently finds 0 items over a 24h period. |
| **Data Truncation** | Large HTML descriptions in CMS content may exceed standard `TEXT` limits. | Use `MEDIUMTEXT` or `LONGTEXT` for the `description` column in the migration. |

### Implementation Amendments

The `TtcAlertsFeedService` should utilize a configured HTTP client to avoid detection:

```php
protected function getHttpClient()
{
    return Http::timeout(15)
        ->retry(2, 200)
        ->withHeaders([
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept-Language' => 'en-US,en;q=0.9',
            'Referer' => 'https://www.ttc.ca/',
        ]);
}
```
