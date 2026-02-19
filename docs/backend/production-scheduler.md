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

The scheduler now ships with multiple guardrails to prevent silent failures and minimize long-lived outages:

- **Job-based ingestion with retries:** Fetchers run as queued jobs with `$tries=3`, `$backoff=30`, `$timeout=120`.
- **Overlap protection without 24-hour lockouts:** Scheduled events use `withoutOverlapping(10)`; job middleware releases locks after 30 seconds on failure and expires at 10 minutes.
- **Empty feed protection:** `ALLOW_EMPTY_FEEDS=false` (default) causes empty feed responses to throw and **skip deactivation**, preventing mass data loss.
- **Circuit breaker:** After 5 consecutive failures per feed, fetch attempts are skipped for 5 minutes to reduce upstream load.
- **Queue depth monitoring:** A scheduled check logs an error when queue depth exceeds 100.
- **Failed job pruning:** `queue:prune-failed --hours=168` runs daily to prevent unbounded growth.

### Monitoring Thresholds

| Signal | Threshold | Behavior |
| --- | --- | --- |
| Queue depth | > 100 | Logs error (every 5 minutes) |
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
- Queue depth threshold errors (`depth > 100`)
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

5) Queue backlog grows:
- The queue depth monitor logs an error when depth exceeds 100.
- Verify the queue worker is running and that jobs are not blocked by overlap locks.

## Runbooks

- `docs/runbooks/scheduler-troubleshooting.md`
- `docs/runbooks/queue-troubleshooting.md`

## Remaining Work (Not Implemented Yet)

- Add a production `compose` / deployment manifest for a `scheduler` service that builds `docker/scheduler/Dockerfile` and sets required env vars.
- Decide whether the scheduler image should be based on a published runtime image (instead of `sail-8.5/app`) for CI/CD.
- Optionally add Pest tests for:
  - heartbeat keys after `scheduler:run-and-log`
  - `scheduler:status` behavior for missing/stale heartbeat
