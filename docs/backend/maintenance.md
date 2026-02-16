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

### Command

- Artisan command: `scene-intel:prune` (supports `--days` option, default 90)
- Implementation: `app/Console/Commands/PruneSceneIntelCommand.php`

### Scheduler

- Scheduled in `routes/console.php`
- Frequency: daily at `00:00` (cron expression: `0 0 * * *`)

### Verification

Automated coverage:
- `tests/Feature/Commands/PruneSceneIntelCommandTest.php`

Manual check command:

```bash
APP_ENV=testing php artisan schedule:list
```

Expected signal: `scene-intel:prune` appears in scheduled commands.
