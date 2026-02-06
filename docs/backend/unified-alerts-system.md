# Unified Alerts System

**Status:** Implemented (updated February 6, 2026)

The unified alerts system aggregates Fire, Police, TTC Transit, and GO Transit records into one paginated feed using a provider + `UNION ALL` architecture.

## Source Coverage

| Source | Backing Table | Provider |
|---|---|---|
| `fire` | `fire_incidents` | `FireAlertSelectProvider` |
| `police` | `police_calls` | `PoliceAlertSelectProvider` |
| `transit` | `transit_alerts` | `TransitAlertSelectProvider` |
| `go_transit` | `go_transit_alerts` | `GoTransitAlertSelectProvider` |

## Data Flow

```
HTTP Request (/, status/page)
  -> GtaAlertsController
  -> UnifiedAlertsQuery::paginate(UnifiedAlertsCriteria)
  -> UNION ALL over tagged AlertSelectProvider implementations
  -> status filtering (all/active/cleared)
  -> deterministic ordering (timestamp DESC, source ASC, external_id DESC)
  -> UnifiedAlertMapper -> UnifiedAlert DTO
  -> UnifiedAlertResource collection
  -> Inertia props for gta-alerts page
```

## Core Components

### Query Aggregator
- File: `app/Services/Alerts/UnifiedAlertsQuery.php`
- Uses `#[Tag('alerts.select-providers')]` to discover providers.
- Builds `fromSub($union, 'unified_alerts')` query.
- Applies status filter from `AlertStatus`.
- Returns `LengthAwarePaginator` of mapped `UnifiedAlert` DTOs.

### Criteria DTO
- File: `app/Services/Alerts/DTOs/UnifiedAlertsCriteria.php`
- Normalizes:
  - `status` via `AlertStatus::normalize()`
  - `perPage` (1..200)
  - `page` (nullable, >=1 when provided)

### Mapper
- File: `app/Services/Alerts/Mappers/UnifiedAlertMapper.php`
- Validates required row fields.
- Normalizes timestamp to `CarbonImmutable`.
- Decodes JSON `meta` safely.
- Builds `AlertLocation` when location fields exist.

### Controller Entry Point
- File: `app/Http/Controllers/GtaAlertsController.php`
- Validates `status` request parameter.
- Instantiates `UnifiedAlertsCriteria`.
- Returns props:
  - `alerts` (resource collection)
  - `filters.status`
  - `latest_feed_updated_at` (max across fire/police/transit/go_transit feed timestamps)

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

## Ordering and Pagination Guarantees

The feed ordering is deterministic:

1. `timestamp` descending
2. `source` ascending
3. `external_id` descending

This protects pagination stability across mixed sources.

## Related Documentation

- `docs/architecture/provider-adapter-pattern.md`
- `docs/backend/enums.md`
- `docs/backend/dtos.md`
- `docs/backend/mappers.md`
