# Provider & Adapter Pattern

This project unifies heterogeneous alert tables by adapting each source into a shared SQL row contract, then combining providers with `UNION ALL`.

## Pattern Summary

```
Source tables -> AlertSelectProvider implementations -> UnifiedAlertsQuery UNION ALL -> UnifiedAlert DTO
```

## Provider Contract

- File: `app/Services/Alerts/Contracts/AlertSelectProvider.php`
- Method: `select(): Illuminate\Database\Query\Builder`

Every provider must project the same columns:

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

## Registered Providers

- `app/Services/Alerts/Providers/FireAlertSelectProvider.php`
- `app/Services/Alerts/Providers/PoliceAlertSelectProvider.php`
- `app/Services/Alerts/Providers/TransitAlertSelectProvider.php`
- `app/Services/Alerts/Providers/GoTransitAlertSelectProvider.php`
- `app/Services/Alerts/Providers/MiwayAlertSelectProvider.php`

Tag registration is in `app/Providers/AppServiceProvider.php` under `alerts.select-providers`.

## Driver Compatibility

Providers branch SQL expressions for SQLite vs MySQL-compatible syntax (string concatenation and JSON object construction). This allows consistent behavior in tests/dev/prod.

## Why This Pattern

- Adds new sources without modifying `UnifiedAlertsQuery`.
- Keeps source-specific SQL mapping isolated.
- Preserves DB-level pagination/sorting over mixed datasets.
- Improves testability by unit testing each provider independently.
