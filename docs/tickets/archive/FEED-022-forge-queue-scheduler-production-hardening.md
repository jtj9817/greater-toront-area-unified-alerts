---
ticket_id: FEED-022
title: "[Deployment] Harden Forge queue and scheduler production configuration"
status: Closed
priority: Critical
assignee: Unassigned
created_at: 2026-03-10
tags: [deployment, forge, hetzner, queue, scheduler, redis, production]
related_files:
  - .env.example
  - routes/console.php
  - config/queue.php
  - config/cache.php
  - scripts/forge-deploy.sh
  - app/Services/ScheduledFetchJobDispatcher.php
  - app/Jobs/FetchFireIncidentsJob.php
  - app/Jobs/FetchPoliceCallsJob.php
  - app/Jobs/FetchTransitAlertsJob.php
  - app/Jobs/FetchGoTransitAlertsJob.php
  - app/Console/Commands/SchedulerRunAndLogCommand.php
  - app/Console/Commands/SchedulerStatusCommand.php
  - docs/runbooks/forge-go-live-checklist.md
  - docs/runbooks/queue-troubleshooting.md
  - docs/backend/production-scheduler.md
---

## Summary

The current application contains several production deployment risks around
queue execution, scheduler observability, cache-backed locking, and default
runtime configuration when deployed to a Hetzner VPS managed through Laravel
Forge.

The codebase already includes meaningful resilience features, including:

- fetch-job uniqueness via `ShouldBeUnique`
- scheduler overlap protection via `withoutOverlapping()`
- queue worker restart on deploy via `php artisan queue:restart`
- scheduler heartbeat commands via `scheduler:run-and-log` and
  `scheduler:status`

However, the documented Forge runtime and the default environment values do not
fully align with those safeguards. That gap creates avoidable failure modes in
production.

## Implementation Update (2026-03-10)

The following hardening work has been applied in this repository:

- Queue retry timing defaults were aligned to fetch job timeout behavior by
  setting `DB_QUEUE_RETRY_AFTER`, `REDIS_QUEUE_RETRY_AFTER`, and
  `BEANSTALKD_QUEUE_RETRY_AFTER` defaults to `180` in `config/queue.php`.
- Queue depth monitoring now uses configurable settings:
  - `QUEUE_DEPTH_ALERT_THRESHOLD` (default `100`) via `config/queue.php`
  - `QUEUE_DEPTH_ALERT_LOG_CHANNEL` via `config/logging.php`
  - `queue_alerts` stack log channel via `config/logging.php`
- Scheduler queue-depth monitoring in `routes/console.php` now logs threshold
  breaches to the configured alert channel, including queue connection context.
- `.env.example` now documents production-safe Forge overrides for:
  - `QUEUE_CONNECTION=redis`
  - `CACHE_STORE=redis`
  - `SESSION_DRIVER=redis`
  - `QUEUE_UNIQUE_LOCK_STORE=redis`
  - `DB_QUEUE_RETRY_AFTER=180`
  - `REDIS_QUEUE_RETRY_AFTER=180`
  - queue-depth alert routing variables
- Forge and troubleshooting runbooks now use
  `php artisan scheduler:run-and-log --no-interaction` for cron guidance.
- Forge runtime preflight documentation now explicitly includes `pcntl`.
- Runbooks now define an actionable queue-depth alert delivery path through
  `QUEUE_DEPTH_ALERT_LOG_CHANNEL` / `QUEUE_ALERT_CHANNELS`.
- Redis queue behavior tradeoff for `ScheduledFetchJobDispatcher` has been
  documented (database-row pre-enqueue dedupe is database-driver-only; Redis
  relies on lock-based uniqueness).

## Problem Statement

Production operations on Forge currently depend on a combination of:

- environment variables copied or adapted from `.env.example`
- a Forge queue daemon running `php artisan queue:work`
- a Forge cron entry running the scheduler
- cache and queue drivers that may differ between local and production

In the current state, the following validated issues remain:

1. queue retry timing is unsafe relative to job timeout values
2. scheduler observability is implemented in code but not wired into the Forge
   cron guidance
3. unique-lock and cache defaults are still local-oriented rather than
   production-oriented
4. Redis-backed queue deployments reduce one layer of pre-enqueue dedupe now
   used by the scheduled fetch dispatcher
5. queue backlog monitoring only logs locally and does not provide an
   operational alert path
6. server runtime prerequisites needed by queue workers are not fully captured
   in the production preflight documentation

## Validated Findings

### 1. Queue `retry_after` is shorter than fetch job timeout

`config/queue.php` sets the database queue `retry_after` to `90` seconds, while
the scheduled fetch jobs declare `$timeout = 120`.

This mismatch creates a race where a job can become visible for retry before
the original execution has actually timed out. On a slow upstream response, the
queue can produce duplicate concurrent executions for the same logical fetch.

Affected configuration and jobs:

- `config/queue.php`
- `app/Jobs/FetchFireIncidentsJob.php`
- `app/Jobs/FetchPoliceCallsJob.php`
- `app/Jobs/FetchTransitAlertsJob.php`
- `app/Jobs/FetchGoTransitAlertsJob.php`

### 2. Forge scheduler guidance bypasses the implemented heartbeat and logging path

The codebase includes `scheduler:run-and-log` and `scheduler:status`, which add
heartbeat tracking and structured scheduler logs. Despite that, the Forge
runbooks still document plain `php artisan schedule:run >> /dev/null 2>&1`.

That means a Forge deployment can ignore the application’s built-in scheduler
observability and fall back to a silent cron configuration. In that state:

- scheduler heartbeat checks are never populated
- scheduler failures are harder to diagnose
- production behavior diverges from the intended operational model

Affected files:

- `app/Console/Commands/SchedulerRunAndLogCommand.php`
- `app/Console/Commands/SchedulerStatusCommand.php`
- `docs/runbooks/forge-go-live-checklist.md`
- `docs/tickets/FEED-012-forge-postgresql-go-live-preflight-checklist.md`

### 3. Default env values remain local-development oriented

`.env.example` still defaults to:

- `DB_CONNECTION=sqlite`
- `QUEUE_CONNECTION=database`
- `CACHE_STORE=database`
- `SESSION_DRIVER=database`
- `QUEUE_UNIQUE_LOCK_STORE=file`

Those values are acceptable for local development, but they are high-risk
defaults for a Forge production site if copied forward incompletely. On a small
VPS, these settings increase database contention and make queue/cache/lock
behavior more fragile than necessary.

The primary risks are:

- accidental production use of SQLite or database-heavy defaults
- cache, session, and queue contention on the primary database
- file-backed unique locks that are not ideal for production coordination

Affected files:

- `.env.example`
- `config/queue.php`
- `config/cache.php`

### 4. Scheduled fetch pre-enqueue dedupe weakens when production uses Redis queues

`ScheduledFetchJobDispatcher` checks the `jobs` table for outstanding queue rows
only when the configured queue driver is `database`.

That behavior works as intended for database queues, but the production
runbooks recommend `QUEUE_CONNECTION=redis`. Under Redis queues, the dispatcher
skips its queue-row existence check and relies on cache-based uniqueness alone.

This is not automatically wrong, but it changes the safety model. If workers
are down long enough for uniqueness locks to expire, scheduled fetch jobs can
accumulate again even though database-queue deployments would have had an
additional outstanding-row guard.

Affected files:

- `app/Services/ScheduledFetchJobDispatcher.php`
- `docs/runbooks/forge-go-live-checklist.md`
- `docs/tickets/FEED-012-forge-postgresql-go-live-preflight-checklist.md`

### 5. Queue depth monitoring does not create an actionable production alert

The scheduler includes a queue depth check that logs an error when depth
exceeds `100`. That gives local visibility, but it does not establish any
delivery path for production alerting.

On a bare VPS or lightly monitored Forge environment, backlog growth can go
unnoticed unless someone is already reading the logs. This leaves an
observability gap between “the app logged a problem” and “an operator was
notified in time to act on it.”

Affected files:

- `routes/console.php`
- `docs/runbooks/queue-troubleshooting.md`
- `docs/backend/production-scheduler.md`

### 6. Queue worker runtime prerequisites are not fully documented in preflight checks

The current preflight checklist covers several required extensions, but it does
not explicitly call out queue-worker signal and timeout prerequisites such as
`pcntl`.

That omission matters on self-managed VPS environments, where package sets can
vary. If queue worker signal handling is incomplete, timeout behavior and clean
restarts become less reliable.

Affected files:

- `docs/tickets/FEED-012-forge-postgresql-go-live-preflight-checklist.md`
- `docs/runbooks/forge-go-live-checklist.md`

## Non-Findings / Rejected Claims

The following claims were reviewed and should not be treated as open defects in
this repository without additional evidence:

- “Production lacks queue worker restart on deploy”  
  Rejected because `scripts/forge-deploy.sh` already runs
  `php artisan queue:restart`.

- “Forge production has no process manager for queue workers”  
  Rejected as a repository defect because Forge daemons are the expected
  process management layer for `queue:work`.

- “File-based locks never work across processes”  
  Rejected as stated. File-based locks are unsafe across non-shared filesystems
  or multi-node/container boundaries, but they can coordinate correctly on a
  single shared filesystem.

- “The Docker scheduler image blocks Forge deployment”  
  Rejected as a general production blocker. The Docker scheduler path depends on
  a Sail runtime image, but the documented Forge production path already uses
  cron rather than that container.

## Required Outcome

Production queue and scheduler operations must be documented and configured so
that:

- worker timeout, job timeout, and retry timing are internally consistent
- Forge uses the intended scheduler logging and heartbeat command
- Redis is the documented production default for queue, cache, session, and
  unique-lock coordination
- the Redis queue behavior change in `ScheduledFetchJobDispatcher` is either
  explicitly accepted or further hardened
- queue backlog conditions can generate an actionable alert path
- Forge preflight checks validate the PHP extensions needed for queue workers

## Acceptance Criteria

- Forge runbooks and deployment guidance specify production environment values
  that are safe for a single-site VPS deployment.
- Scheduler instructions use the application’s heartbeat/logging path rather
  than a silent `schedule:run >> /dev/null` cron entry.
- Queue configuration guidance documents a `retry_after` value greater than the
  effective queue worker/job timeout.
- Production guidance explicitly recommends Redis for:
  - `QUEUE_CONNECTION`
  - `CACHE_STORE`
  - `SESSION_DRIVER`
  - `QUEUE_UNIQUE_LOCK_STORE`
- The operational impact of using Redis queues with
  `ScheduledFetchJobDispatcher` is documented and addressed.
- Preflight docs include queue-worker runtime prerequisites, including `pcntl`.
- Queue backlog monitoring guidance defines how an operator is expected to be
  alerted when depth thresholds are exceeded.

## Notes

- This ticket intentionally excludes rollout sequencing and timelines.
- This ticket is focused on production hardening and documentation alignment,
  not on redesigning the ingestion architecture.
