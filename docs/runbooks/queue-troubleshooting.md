# Queue Troubleshooting

## Scope

This runbook covers queue backlog diagnosis, failed job recovery, and pruning behavior for scheduled ingestion jobs.

For full Forge go-live steps, see:
`docs/runbooks/forge-go-live-checklist.md`.

## Signals & Thresholds

- **Queue depth alert:** Logged as an error when depth exceeds `QUEUE_DEPTH_ALERT_THRESHOLD` (default `100`; checked every 5 minutes).
- **Failed jobs growth:** `failed_jobs` table grows without pruning or retries.

## Worker Exit 137 (OOM Kill)

Exit code `137` is SIGKILL — typically the host or container OOM killer terminating the process. The long-lived `queue:listen` command accumulates memory across many job executions, making it susceptible in memory-constrained environments.

**Mitigation (dev):**

The dev script (`composer dev`) now runs a small restart wrapper:

```
./scripts/dev-queue-worker.sh
```

That wrapper launches the bounded worker inside Sail:

```
./vendor/bin/sail artisan queue:work --tries=3 --max-jobs=1000 --sleep=3
```

- `--max-jobs=1000`: worker exits cleanly after 1000 jobs, preventing unbounded memory growth.
- `--sleep=3`: poll interval when the queue is empty.
- `--tries=3`: retry limit per job.
- `scripts/dev-queue-worker.sh` immediately restarts clean `queue:work` exits, so the worker does not disappear mid-session.
- Non-zero exits still fail fast so real worker crashes remain visible.

**Mitigation (production / Forge):**

```
php artisan queue:work --sleep=1 --tries=3 --timeout=120 --max-time=3600
```

`--max-time=3600` bounds the worker to a 1-hour lifetime; Forge restarts it on exit.

Queue timing alignment requirements:

- Fetch jobs use `$timeout=120`.
- Set queue `retry_after` greater than worker/job timeout to avoid duplicate concurrent retries.
- Recommended production env values:
  - `DB_QUEUE_RETRY_AFTER=180`
  - `REDIS_QUEUE_RETRY_AFTER=180`

**Dev orchestration:**

`composer dev` uses `concurrently --kill-others-on-fail` (not `--kill-others`). Clean queue-worker recycling no longer tears down the stack because the wrapper restarts it in place. A non-zero worker exit still tears down the rest of the dev session, which is desirable for surfacing actual crashes. If you need strict fail-fast behaviour for every queue stop, including clean bounded exits, run:

```
npx concurrently --kill-others ...
```

manually or add a `dev:strict` alias locally.

## Interpreting Multi-Fan-Out Behavior

Seeing multiple `FanOutAlertNotificationsJob` entries in the queue log within a single scheduler window is **expected and normal** — it is not a sign of duplicate listener registration or runaway dispatch.

Each scheduler cycle can enqueue several feed-fetch jobs (fire/transit/GO every 5 min, police every 10 min). Every new or re-activated alert emits one `AlertCreated` event, producing one fan-out job. N new alerts → N fan-out dispatches.

**Normal pattern:**
```
[queue] FetchFireIncidentsJob         (1 run)
[queue] FanOutAlertNotificationsJob   (N runs — one per new/reactivated fire alert)
[queue] FetchTransitAlertsJob         (1 run)
[queue] FanOutAlertNotificationsJob   (M runs — one per new/reactivated transit alert)
```

**Anomaly signals to investigate:**
- Fan-out count grows unboundedly across cycles for alerts that have not changed.
- `LOG_LEVEL=debug` shows suppression messages (`'suppressed duplicate fan-out for same alert state'`) being emitted but chunk jobs still dispatching — this would indicate a broken cache driver.
- `failed_jobs` fills with `FanOutAlertNotificationsJob` entries — the dedupe cache may be unavailable.

Dedupe suppression is logged at `DEBUG` level. Set `LOG_LEVEL=debug` or watch the `queue_enqueues` log channel (requires `QUEUE_DEBUG_ENQUEUES=true`) to observe fan-out and chunk job dispatch rates per cycle.

For scheduled ingestion visibility, local development also logs actual fetch-job execution to `storage/logs/queue_execution.log`. This makes it easier to distinguish:

- scheduler dispatch / enqueue activity
- actual queue worker execution
- queue-job failures

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
  `php artisan queue:work --sleep=1 --tries=3 --timeout=120 --max-time=3600`
- Confirm retry-after env values are greater than timeout:
  - `DB_QUEUE_RETRY_AFTER=180`
  - `REDIS_QUEUE_RETRY_AFTER=180`
- Confirm queue-depth alert routing:
  - `QUEUE_DEPTH_ALERT_LOG_CHANNEL=queue_alerts`
  - `QUEUE_ALERT_CHANNELS=single,slack` (or your central log destination)
- Restart workers after deploy:
  `php artisan queue:restart`

## Failed Job Pruning

- Command: `php artisan queue:prune-failed --hours=168`
- Scheduled daily at `00:00` (7-day retention)
- Use manual pruning when `failed_jobs` grows abnormally and failures are resolved.
