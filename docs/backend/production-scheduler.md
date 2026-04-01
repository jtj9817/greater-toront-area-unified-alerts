# Production Scheduler Container (Cron + Observability)

## Context / Why This Exists

This project relies on Laravel's scheduler to dispatch queued jobs and run maintenance commands on intervals. Fetch tasks run as jobs (not commands directly) so the queue handles retries and overlap locking — for example `FetchFireIncidentsJob` every 5 minutes and `FetchPoliceCallsJob` every 10 minutes in `routes/console.php`.

In development (Laravel Sail), it’s common to run the scheduler as a long-lived process:

```bash
./vendor/bin/sail artisan schedule:work
```

In production, a more typical approach is to run `schedule:run` every minute via `cron`:

```cron
* * * * * cd /path && php artisan schedule:run
```

## Phase 0 Pre-Flight Gate (FEED-023)

Complete this gate before any scheduler lock-store migration:

1. Select exactly one scheduler authority per environment:
   - `schedule:work`, or
   - cron `schedule:run` / `scheduler:run-and-log`
2. Verify there is no second authority running:
   - `pgrep -fa "artisan (schedule:work|schedule:run|scheduler:run-and-log)"`
   - `crontab -l | rg -n "schedule:run|scheduler:run-and-log"`
   - if more than one authority is present, stop extras before continuing
3. Verify Redis health before switching shared environments to Redis lock stores:
   - `php artisan tinker --execute="cache()->store('redis')->put('scheduler:phase0:health', 'ok', 60); dump(cache()->store('redis')->get('scheduler:phase0:health'));"`
4. Set explicit lock stores:
   - local single-node: `SCHEDULE_CACHE_STORE=file`, `QUEUE_UNIQUE_LOCK_STORE=file`
   - shared/multi-node: `SCHEDULE_CACHE_STORE=redis`, `QUEUE_UNIQUE_LOCK_STORE=redis`
5. Clear cached framework state before verification:
   - `php artisan optimize:clear`

If any check fails, stop rollout and fix the runtime state first. Do not continue with partial lock-store changes.

During this session, we moved from “inline bash inside `compose.yaml` that installs `cron` at container startup” to a production-friendly approach:

- **Bake cron into an image** (no apt installs at boot, reproducible builds).
- **Log scheduler output into Laravel logs** (so failures aren’t silent).
- **Provide a health check signal** (so orchestration can detect “scheduler stopped running”).
- **Emit a startup report** (so you can see what tasks are registered when the container boots).

## What Was Added

### 1) Scheduler Image (Dockerfile)

**File:** `docker/scheduler/Dockerfile`

Key behaviors:

- Base image: `sail-8.5/app` (the same runtime image Sail uses locally).
- Installs `cron` at build time.
- Copies the app source into the image and runs `composer install --no-dev`.
- Adds:
  - a cron file at `/etc/cron.d/laravel-scheduler`
  - an entrypoint script
  - a healthcheck script
- Runs cron in the foreground: `cron -f`
- Uses Docker `HEALTHCHECK` to ensure the scheduler heartbeat is fresh.

Important note about the base image:

- `FROM sail-8.5/app` assumes you have a compatible runtime image available (locally this is built by Sail from `vendor/laravel/sail/runtimes/8.5/Dockerfile`).
- For production CI/CD, you typically either:
  - build and publish the runtime image (`sail-8.5/app`) first, then build this scheduler image from it, or
  - replace the `FROM` line with your own published PHP runtime image.

### 2) Cron Entry (Runs Every Minute)

**File:** `docker/scheduler/laravel-scheduler`

This is an `/etc/cron.d/*` style entry (note the explicit `sail` user column):

```cron
* * * * * sail cd /var/www/html && php artisan scheduler:run-and-log --no-interaction
```

Why this is different from `schedule:run >> /dev/null`:

- The wrapper command captures scheduler output and writes it to Laravel logs.
- It updates a “heartbeat” used by the health check.

### 3) Entrypoint (Boot-Time Checks + Startup Report)

**File:** `docker/scheduler/entrypoint.sh`

Responsibilities:

- Verifies `artisan` exists.
- Optionally waits for the database to be reachable (using `php artisan migrate:status`).
- Optionally waits for a URL to respond (useful if jobs depend on the web stack being reachable).
- Emits a startup report (logs schedule configuration) via:
  - `php artisan scheduler:report --startup`
- Finally `exec`s `cron -f`.

Environment variables (all optional):

- `APP_DIR` (default: `/var/www/html`)
- `SCHEDULER_WAIT_SECONDS` (default: `60`)
- `SCHEDULER_WAIT_FOR_DB` (default: `1`)
- `SCHEDULER_WAIT_URL` (default: empty / disabled)

### 4) Healthcheck Script (Stale/Missing Scheduler Detection)

**File:** `docker/scheduler/healthcheck.sh`

Calls:

```bash
php artisan scheduler:status --max-age="$SCHEDULER_MAX_AGE_MINUTES"
```

Environment variables:

- `APP_DIR` (default: `/var/www/html`)
- `SCHEDULER_MAX_AGE_MINUTES` (default: `5`)

### 5) New Artisan Commands (Logging + Status)

Commands were added under `app/Console/Commands/` and auto-registered by enabling command discovery (see next section).

#### `scheduler:run-and-log`

**File:** `app/Console/Commands/SchedulerRunAndLogCommand.php`

What it does:

- Executes `php artisan schedule:run --no-interaction`.
- Logs:
  - start/end events
  - duration
  - the scheduler output (line-by-line)
- Writes a heartbeat to the default cache store:
  - `scheduler:last_tick_at` (unix timestamp)
  - `scheduler:last_tick_exit_code`
  - `scheduler:last_tick_duration_ms`

This heartbeat is what the Docker `HEALTHCHECK` evaluates.

#### `scheduler:status`

**File:** `app/Console/Commands/SchedulerStatusCommand.php`

What it does:

- Reads the heartbeat keys above from cache.
- Fails (exit code non-zero) if:
  - no heartbeat has ever been written, or
  - the heartbeat is older than `--max-age` minutes
- Logs errors to Laravel logs for post-mortems.

#### `scheduler:report`

**File:** `app/Console/Commands/SchedulerReportCommand.php`

What it does:

- Logs a “startup report” that includes:
  - environment / timezone / debug flags
  - `schedule:list` output line-by-line

This is invoked by the scheduler container entrypoint to make it obvious what the container thinks it should be running.

## Laravel Command Discovery Change

**File:** `bootstrap/app.php`

We added:

```php
->withCommands()
```

This enables Laravel to auto-register commands located in `app/Console/Commands` in this Laravel 11+ “bootstrap builder” style project structure.

## How To Use (Sail / Local)

If you want to run and observe the scheduler locally (without the Docker scheduler container):

```bash
./vendor/bin/sail artisan scheduler:report --startup
./vendor/bin/sail artisan scheduler:run-and-log
./vendor/bin/sail artisan scheduler:status --max-age=5
```

If you want to use the production-like scheduler container locally, you’ll need Docker running and a compose service that builds `docker/scheduler/Dockerfile`. (This repo did not add a production compose definition yet; see “Remaining Work”.)

## Logs: Where They Go

All scheduler diagnostics are written using Laravel’s `Log` facade. Where they end up depends on `LOG_CHANNEL` / logging config:

- File logging: typically `storage/logs/laravel.log`
- Container logging: stdout/stderr if using a channel that writes to stderr (common in production)

For production containers, prefer stdout/stderr logging so orchestration platforms can collect logs centrally.

## Scheduler Resilience Guardrails

The scheduler ships with multiple guardrails to prevent silent failures and minimize long-lived outages:

- **Single scheduler authority:** Exactly one scheduler authority may run in an environment (`schedule:work` or cron `schedule:run` / `scheduler:run-and-log`, never both). This prevents duplicate overlap-lock acquisition attempts for the same minute.
- **Explicit scheduler mutex store (`SCHEDULE_CACHE_STORE`):** Scheduler `withoutOverlapping(...)` mutexes use the cache store configured by `SCHEDULE_CACHE_STORE` (`file` for local single-node, `redis` for shared environments) so scheduler lock contention does not hit the database `cache_locks` table.
- **Pre-enqueue deduplication (`ScheduledFetchJobDispatcher`):** Fetch jobs are dispatched via `Schedule::call()` closures that delegate to `App\Services\ScheduledFetchJobDispatcher`. The dispatcher checks for an outstanding database queue row and acquires a `UniqueLock` before enqueuing. If either check indicates a duplicate, the dispatch is skipped and logged. A failed lock-acquire is treated as a skip — locks are never force-released.
- **Configurable unique-lock store (`QUEUE_UNIQUE_LOCK_STORE`):** Fetch-job uniqueness locks use the cache store configured by `QUEUE_UNIQUE_LOCK_STORE` (default `file`; use `redis` for shared multi-node environments). This avoids noisy `cache_locks` duplicate-key errors during normal skip behavior.
- **Job-based ingestion with retries:** Fetchers run as queued jobs with `$tries=3`, `$backoff=30`, `$timeout=120`. Each job implements `ShouldBeUnique` as a second-layer execution-time guard.
- **Overlap protection without 24-hour lockouts:** Scheduled events use `withoutOverlapping(10)`; job middleware releases locks after 30 seconds on failure and expires at 10 minutes.
- **Empty feed protection:** `ALLOW_EMPTY_FEEDS=false` (default) causes empty feed responses to throw and **skip deactivation**, preventing mass data loss.
- **Circuit breaker:** After 5 consecutive failures per feed, fetch attempts are skipped for 5 minutes to reduce upstream load.
- **Queue depth monitoring:** A scheduled check logs an error when queue depth exceeds `QUEUE_DEPTH_ALERT_THRESHOLD` (default `100`) and routes to `QUEUE_DEPTH_ALERT_LOG_CHANNEL`.
- **Failed job pruning:** `queue:prune-failed --hours=168` runs daily to prevent unbounded growth.

### Monitoring Thresholds

| Signal | Threshold | Behavior |
| --- | --- | --- |
| Queue depth | > `QUEUE_DEPTH_ALERT_THRESHOLD` (default `100`) | Logs error to `QUEUE_DEPTH_ALERT_LOG_CHANNEL` (every 5 minutes) |
| Circuit breaker | 5 failures | Opens for 5 minutes |
| Scene intel failures | > 50% | Logs warning + console warning |
| Overlap lock expiry | 10 minutes | Prevents 24-hour lockouts |
| Retry backoff | 30 seconds | Aligns with lock release |

### Empty Feed Strategy (`ALLOW_EMPTY_FEEDS`)

- **Default:** `ALLOW_EMPTY_FEEDS=false` (strict mode)
- **Behavior:** Empty feeds trigger a `RuntimeException` and **skip deactivation** to prevent mass data loss.
- **When to enable:** Only during controlled testing or known upstream maintenance windows where empty feeds are expected.

### Alerting Signals

Use log-based alerts for these events:

- `Fetch*Command failed` errors (command failure rate)
- Queue depth threshold errors (`depth > QUEUE_DEPTH_ALERT_THRESHOLD`)
- `Scene intel failure rate exceeded threshold`
- `Circuit breaker open` events (repeated upstream failures)

## Failure Modes / Debugging Checklist

1) Scheduler container is “healthy” but jobs don’t run:
- Run `php artisan schedule:list` (via `scheduler:report`) and ensure tasks are registered.
- Confirm the server timezone and expected schedule timezone (`config('app.timezone')`).

2) Healthcheck failing (stale heartbeat):
- Check the scheduler container logs for `Scheduler tick…` entries.
- Ensure cron is running (`cron -f` is PID 1 in the scheduler container).
- Confirm the default cache driver is writable inside the container (file cache writes to `storage/framework/cache`).

3) `schedule:run` errors:
- The wrapper logs `Artisan::output()` line-by-line; search logs for `Scheduler tick output`.
- If a scheduled command fails, ensure those commands log their own exceptions (some already do, e.g. `FetchPoliceCallsCommand` logs to `Log::error` on fetch failures).

4) Fetch jobs keep failing or are skipped:
- Look for `Circuit breaker open` logs — repeated upstream failures will pause fetch attempts for 5 minutes.
- If errors mention “zero alerts” or “zero events”, review `ALLOW_EMPTY_FEEDS` behavior and upstream status.
- Check failed jobs via `php artisan queue:failed` and retry if appropriate.

5) Scheduler keeps logging “Scheduled fetch job skipped”:
- This is normal when a previous fetch job for that source is still pending or running (pre-enqueue dedupe working correctly).
- If skips persist indefinitely with no queue row present, the unique lock may be stale. Verify the cache store named by `QUEUE_UNIQUE_LOCK_STORE` is reachable and the lock TTL has not been extended abnormally.
- Log entry `reason: outstanding_queue_row_exists` means the worker is alive but slow; `reason: unique_lock_held` means a lock is held without a matching queue row (e.g., job is executing and holds the lock).

6) Queue backlog grows:
- The queue depth monitor logs an error when depth exceeds `QUEUE_DEPTH_ALERT_THRESHOLD`.
- Ensure `QUEUE_DEPTH_ALERT_LOG_CHANNEL` routes to an operator notification path (for example `queue_alerts` with `QUEUE_ALERT_CHANNELS=single,slack`).
- Verify the queue worker is running and that jobs are not blocked by overlap locks.

## Runbooks

- `docs/runbooks/scheduler-troubleshooting.md`
- `docs/runbooks/queue-troubleshooting.md`

## Scheduled Fetch Dispatch Architecture

### ScheduledFetchJobDispatcher

All five feed-fetch entries in `routes/console.php` use `Schedule::call()` closures that delegate to `App\Services\ScheduledFetchJobDispatcher`. This service enforces pre-enqueue uniqueness so the `jobs` table never accumulates redundant fetch-job rows when a worker is paused or slow.

**Dispatch logic (per source):**

1. Check the `jobs` table for an outstanding row with the same job class name. If one exists, log a skip and return.
2. Attempt to acquire a `UniqueLock` against the cache store configured in `QUEUE_UNIQUE_LOCK_STORE`. If the lock is already held, log a skip and return — locks are never force-released.
3. Re-check the `jobs` table after acquiring the lock (TOCTOU guard). If a row appeared in the interim, release the lock and return.
4. Dispatch the job to the queue. If dispatch throws, release the lock and rethrow.
5. Log the successful enqueue with the lock key.

Both the pre-lock and post-lock queue-row checks only apply when the default queue connection uses the `database` driver. Other drivers return `false` from the check.

**Unique lock store (`QUEUE_UNIQUE_LOCK_STORE`):**

Fetch-job uniqueness locks are kept on a dedicated non-database cache store to avoid noisy `cache_locks` duplicate-key errors during normal skip behavior.

```
# .env / .env.example
QUEUE_UNIQUE_LOCK_STORE=file      # default — safe for single-node dev
QUEUE_UNIQUE_LOCK_STORE=redis     # recommended for multi-node production
```

The value is read from `config/queue.php` (`queue.unique_lock_store`). Each job class implements `uniqueVia()` to read this config key and return the appropriate cache store.

**Each job class** (`FetchFireIncidentsJob`, `FetchPoliceCallsJob`, `FetchTransitAlertsJob`, `FetchGoTransitAlertsJob`, `FetchMiwayAlertsJob`) implements:
- `ShouldBeUnique` with `$uniqueFor = 600` seconds
- `uniqueVia()` returning the `QUEUE_UNIQUE_LOCK_STORE` cache store
- `WithoutOverlapping` middleware as a second execution-time concurrency guard
- `$tries = 3`, `$backoff = 30`, `$timeout = 120`

**Observable log keys:**
- `Scheduled fetch job enqueued` — job was new and dispatched
- `Scheduled fetch job skipped` with `reason: outstanding_queue_row_exists` — skipped at queue-row check
- `Scheduled fetch job skipped` with `reason: unique_lock_held` — skipped because lock was already held
- `Scheduled fetch job skipped` with `reason: outstanding_queue_row_exists_after_lock` — skipped at TOCTOU check

### fire_incidents.units_dispatched Column Width

The `units_dispatched` column was widened from `varchar(255)` to `text` in migration `2026_03_06_change_fire_incidents_units_dispatched_to_text.php`. The Toronto Fire feed can emit unit lists longer than 255 characters; the narrow column caused `FetchFireIncidentsJob` to fail with a PostgreSQL `value too long for type character varying(255)` error and enter a retry loop.

## Remaining Work (Not Implemented Yet)

- Add a production `compose` / deployment manifest for a `scheduler` service that builds `docker/scheduler/Dockerfile` and sets required env vars.
- Decide whether the scheduler image should be based on a published runtime image (instead of `sail-8.5/app`) for CI/CD.
