# Backend Maintenance

This document covers automated maintenance policies for backend operational data.

## Notification Log Retention

### Policy

- `notification_logs` entries older than 30 days are permanently deleted.
- Boundary behavior: entries with `sent_at` exactly 30 days old are retained.

### Command

- Artisan command: `notifications:prune`
- Implementation: `app/Console/Commands/PruneNotificationsCommand.php`

### Scheduler

- Scheduled in `routes/console.php`
- Frequency: daily at `00:00` (cron expression: `0 0 * * *`)

### Verification

Automated coverage:
- `tests/Feature/Commands/PruneNotificationsCommandTest.php`
- `tests/Feature/Console/NotificationPruningScheduleTest.php`

Manual check command:

```bash
APP_ENV=testing php artisan schedule:list
```

Expected signal: `notifications:prune` appears in scheduled commands.

## Scene Intel Retention

### Policy

- `incident_updates` entries older than 90 days are permanently deleted.
- Boundary behavior: entries with `created_at` exactly 90 days old are retained.

### Implementation

- Model: `App\Models\IncidentUpdate`
- Trait: `Illuminate\Database\Eloquent\MassPrunable`
- Policy: `prunable()` method defined with a 90-day cutoff.

### Scheduler

- Scheduled in `routes/console.php`
- Command: `php artisan model:prune --model="App\Models\IncidentUpdate"`
- Frequency: daily at `00:00` (cron expression: `0 0 * * *`)

### Verification

Automated coverage:
- `tests/Feature/SceneIntel/IncidentUpdatePruningTest.php`

Manual check command:

```bash
APP_ENV=testing php artisan schedule:list
```

Expected signal: `model:prune` for `IncidentUpdate` appears in scheduled commands.
