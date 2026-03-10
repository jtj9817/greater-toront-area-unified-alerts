# Scheduler Troubleshooting

## Scope

This runbook covers scheduled ingestion failures, overlap lock behavior, empty feed protection, and circuit breaker behavior for the GTA Alerts scheduler.

For full Forge go-live steps, see:
`docs/runbooks/forge-go-live-checklist.md`.

## Quick Triage

- Check scheduler heartbeat: `php artisan scheduler:status --max-age=5`
- Verify tasks are registered: `php artisan scheduler:report --startup` (or `php artisan schedule:list`)
- Review recent logs: `storage/logs/laravel.log` or container stdout/stderr
- Ensure queue workers are running (job-based ingestion)

## Common Failure Modes & Recovery

### Scheduler heartbeat is stale

**Signals**
- `scheduler:status` exits non-zero
- No recent “Scheduler tick…” logs

**Recovery**
- Ensure cron is running in the scheduler container (`cron -f`)
- Confirm the cache store is writable (heartbeat stored in cache)
- In Forge production, confirm scheduler cron entry exists:
  `* * * * * cd /home/forge/<site> && php artisan scheduler:run-and-log --no-interaction >> /dev/null 2>&1`

### Fetch jobs failing or retrying

**What happens**
- Jobs retry 3 times with 30-second backoff
- Overlap locks are released after 30 seconds and expire after 10 minutes
- This avoids legacy 24-hour lockouts and supports quick retry recovery

**Recovery**
- Inspect failures: `php artisan queue:failed`
- Retry transient failures: `php artisan queue:retry <id|all>`
- Validate upstream availability and credentials

### Empty feed protection triggered

**Signals**
- Logs show “feed returned zero …”
- Command exits with failure and **does not deactivate** existing records

**Recovery**
- Confirm upstream source status
- If empty feed is expected (rare), temporarily set `ALLOW_EMPTY_FEEDS=true`
- Revert to `ALLOW_EMPTY_FEEDS=false` after recovery to prevent mass deactivation

### Circuit breaker open

**What happens**
- After 5 consecutive failures per feed, fetch attempts are skipped for 5 minutes
- Logs include “Circuit breaker open” or similar

**Recovery**
- Fix upstream or network issue
- Wait for TTL expiry, then verify fetch resumes

### Queue backlog grows

**Signals**
- Queue depth monitor logs an error when depth > 100
- Fetch jobs execute late or bunch together

**Recovery**
- Ensure queue worker is running and not stuck
- Verify no long-running job is holding overlap locks
- Scale workers if backlog persists

### Scene intel failure rate warning

**Signals**
- Warning: “Scene intel failure rate exceeded threshold”

**Recovery**
- Check `SceneIntelProcessor` logs for underlying exceptions
- Investigate data integrity or database errors

## Persistent vs Transient Failures

- **Transient:** Job retries + overlap lock release + circuit breaker TTL allow auto-recovery.
- **Persistent:** Repeated upstream outages or database failures will keep jobs failing and accumulate in `failed_jobs` until resolved.

## Related Runbooks

- `docs/runbooks/queue-troubleshooting.md`
