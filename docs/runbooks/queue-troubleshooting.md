# Queue Troubleshooting

## Scope

This runbook covers queue backlog diagnosis, failed job recovery, and pruning behavior for scheduled ingestion jobs.

For full Forge go-live steps, see:
`docs/runbooks/forge-go-live-checklist.md`.

## Signals & Thresholds

- **Queue depth alert:** Logged as an error when depth exceeds 100 (checked every 5 minutes).
- **Failed jobs growth:** `failed_jobs` table grows without pruning or retries.

## Inspect the Queue

- List failed jobs: `php artisan queue:failed`
- Retry a job: `php artisan queue:retry <id>`
- Retry all failed jobs: `php artisan queue:retry all`
- Forget a bad job: `php artisan queue:forget <id>`

## Common Causes

- **Worker down or stuck:** No active queue worker to process jobs.
- **Overlap locks:** `WithoutOverlapping` prevents concurrent fetch jobs; locks release after 30 seconds on failure and expire after 10 minutes.
- **Upstream outages:** Circuit breakers open after repeated failures, pausing fetch attempts.

## Recovery Steps

1. Confirm queue worker is running and healthy.
2. Resolve upstream or database failures.
3. Retry failed jobs once the root cause is fixed.
4. Monitor queue depth and logs to confirm recovery.

## Forge Production Checks

- Confirm Forge daemon status is `active` for queue workers.
- Expected daemon command:
  `php artisan queue:work --sleep=1 --tries=3 --timeout=90 --max-time=3600`
- Restart workers after deploy:
  `php artisan queue:restart`

## Failed Job Pruning

- Command: `php artisan queue:prune-failed --hours=168`
- Scheduled daily at `00:00` (7-day retention)
- Use manual pruning when `failed_jobs` grows abnormally and failures are resolved.
