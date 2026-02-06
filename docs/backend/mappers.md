# Alert Mappers

## UnifiedAlertMapper

- File: `app/Services/Alerts/Mappers/UnifiedAlertMapper.php`
- Responsibility: map unified SQL rows into `UnifiedAlert` DTOs.

### Input Row Contract

Expected properties:

- `source`
- `external_id`
- `is_active`
- `timestamp`
- `title`
- `location_name`
- `lat`
- `lng`
- `meta`

### Key Behavior

- Validates required string fields (`source`, `external_id`, `title`, `timestamp`).
- Parses timestamp with `CarbonImmutable::parse`.
- Builds composite `id` via `AlertId::fromParts()`.
- Creates `AlertLocation` when any location field is present.
- Decodes `meta` from JSON/object input; invalid JSON falls back to `[]`.

### Query Integration

`UnifiedAlertsQuery` applies:

```php
->through(fn (object $row) => $this->mapper->fromRow($row));
```

This keeps SQL provider concerns separate from DTO construction/validation.

## Related Documentation

- `docs/backend/dtos.md`
- `docs/backend/unified-alerts-system.md`
- `docs/architecture/provider-adapter-pattern.md`
