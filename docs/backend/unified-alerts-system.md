# Unified Alerts System

**Status:** Implemented (updated April 2, 2026)

The unified alerts system aggregates Fire, Police, TTC Transit, GO Transit, MiWay, YRT, and DRT records into one feed using tagged providers + `UNION ALL`, server-side filters, and cursor pagination for infinite scroll.

## Source Coverage

| Source | Backing Table | Provider |
|---|---|---|
| `fire` | `fire_incidents` | `FireAlertSelectProvider` |
| `police` | `police_calls` | `PoliceAlertSelectProvider` |
| `transit` | `transit_alerts` | `TransitAlertSelectProvider` |
| `go_transit` | `go_transit_alerts` | `GoTransitAlertSelectProvider` |
| `miway` | `miway_alerts` | `MiwayAlertSelectProvider` |
| `yrt` | `yrt_alerts` | `YrtAlertSelectProvider` |
| `drt` | `drt_alerts` | `DrtAlertSelectProvider` |

## Request + Query Flow

```
HTTP Request (/, /api/feed) with status/source/q/since/cursor
  -> GtaAlertsController (Inertia) or Api\FeedController (JSON)
  -> UnifiedAlertsCriteria normalization + validation
  -> UnifiedAlertsQuery::cursorPaginate(...)
  -> UNION ALL over tagged AlertSelectProvider implementations
  -> status/source/since/q filtering
  -> deterministic seek ordering (timestamp DESC, id DESC)
  -> UnifiedAlertMapper -> UnifiedAlert DTO
  -> UnifiedAlertResource collection
  -> Inertia props or JSON { data, next_cursor }
```

## Feed Query Parameters

Both the Inertia feed page (`/`) and JSON feed endpoint (`/api/feed`) support:

| Param | Allowed Values | Notes |
|---|---|---|
| `status` | `all`, `active`, `cleared` | Defaults to `all`. |
| `source` | `fire`, `police`, `transit`, `go_transit`, `miway`, `yrt`, `drt` | Unified source enum only. |
| `q` | string, max 200 | Trimmed; whitespace-only acts as unset. |
| `since` | `30m`, `1h`, `3h`, `6h`, `12h` | Converted to server-side cutoff (`timestamp >= now - since`). |
| `cursor` | opaque base64url payload | Encodes `(ts, id)` and is validated/decoded server-side. |
| `per_page` (`/api/feed` only) | `1..100` | Optional batch size override for API requests. |

## Cursor Semantics and Infinite Scroll Guarantees

- Cursor payload is base64url JSON: `{"ts":"<iso8601>","id":"<source:external_id>"}`.
- Query ordering is deterministic: `timestamp DESC`, then `id DESC`.
- Seek condition for the next batch:
  - `timestamp < cursor_ts`, or
  - `timestamp = cursor_ts AND id < cursor_id`
- Batch response shape:
  - `data`: mapped `UnifiedAlertResource[]`
  - `next_cursor`: next cursor string or `null` when there are no more results
- Changing `status`, `source`, `q`, or `since` must reset the list and cursor on the client.

## Search Performance: Cross-Driver Full-Text Search

The `q` parameter is handled at the provider layer and behaves differently per database driver:

### PostgreSQL (production)
- Provider-level FTS using `to_tsvector('simple', coalesce(col1, '') || ' ' || coalesce(col2, '')) @@ plainto_tsquery('simple', ?)`.
- A substring fallback using `ILIKE` is applied **in addition** to FTS (via `OR`) to preserve partial-match UX.
- GIN indexes created by migration: `database/migrations/2026_02_26_000001_add_pgsql_fulltext_indexes_to_alert_tables.php`
  - Index names: `fire_incidents_fulltext`, `police_calls_fulltext`, `transit_alerts_fulltext`, `go_transit_alerts_fulltext`
  - **Note:** `miway_alerts`, `yrt_alerts`, and `drt_alerts` do not have dedicated PostgreSQL GIN index migrations — their providers fall back to `ILIKE` on PostgreSQL.
  - Indexes use `CREATE INDEX CONCURRENTLY` and are skipped on non-PostgreSQL connections.

### MySQL (local/dev)
- Provider-level `MATCH (...) AGAINST (... IN NATURAL LANGUAGE MODE)`.
- FULLTEXT indexes created by migration: `database/migrations/2026_02_19_120000_add_fulltext_indexes_to_alert_tables.php`
- Provider-level `LIKE` fallback predicates are applied alongside FULLTEXT for substring matching.

### SQLite (tests/dev fallback)
- No provider-level full-text search.
- Outer query in `UnifiedAlertsQuery` applies a case-insensitive `LIKE` over `title` and `location_name`.

## Core Components

### Query Aggregator
- File: `app/Services/Alerts/UnifiedAlertsQuery.php`
- Uses `#[Tag('alerts.select-providers')]` to discover providers.
- Builds `fromSub($union, 'unified_alerts')` and applies shared filters.
- Supports both `paginate(...)` (legacy/internal) and `cursorPaginate(...)` (feed UX).

### Criteria DTO
- File: `app/Services/Alerts/DTOs/UnifiedAlertsCriteria.php`
- Normalizes:
  - `status`
  - `source`
  - `query` (`q`)
  - `since` + computed `sinceCutoff`
  - `cursor` via `UnifiedAlertsCursor::decode(...)`
  - `perPage` / `page` constraints

### Mapper
- File: `app/Services/Alerts/Mappers/UnifiedAlertMapper.php`
- Validates required row fields.
- Normalizes timestamp to `CarbonImmutable`.
- Decodes JSON `meta` safely.
- Builds `AlertLocation` when location fields exist.

### Controller Entry Points
- Inertia page controller: `app/Http/Controllers/GtaAlertsController.php`
- API batch controller: `app/Http/Controllers/Api/FeedController.php`
- Both validate the same filter contract and return cursor-ready feed payloads.

## Unified Row Contract

Every provider selects the same columns:

- `id`
- `source`
- `external_id`
- `is_active`
- `timestamp`
- `title`
- `location_name`
- `lat`
- `lng`
- `meta`

## Related Documentation

- `docs/architecture/provider-adapter-pattern.md`
- `docs/backend/enums.md`
- `docs/backend/dtos.md`
- `docs/backend/mappers.md`
- `docs/tickets/FEED-001-server-side-filters-infinite-scroll.md`
