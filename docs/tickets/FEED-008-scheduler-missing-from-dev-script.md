---
ticket_id: FEED-008
title: "[Bug] No Scheduler Runs in Development — Scheduled Jobs Never Dispatched to Queue"
status: Closed
priority: Critical
assignee: Unassigned
created_at: 2026-02-24
tags: [bug, backend, scheduler, queue, infrastructure, dev-environment]
related_files:
  - composer.json
  - routes/console.php
  - docker/scheduler/
---

## Summary

FEED-007 fixed the queue worker placement (moved `queue:listen` from the host into the Sail container so `DB_HOST=mysql` resolves). However, the root cause of stale feed data has a second half: **no scheduler is running in development at all.** Without a scheduler ticking every minute, `Schedule::job()` never fires, no fetch jobs are ever dispatched to the queue, and the queue worker FEED-007 fixed sits permanently idle.

The dedicated scheduler lives in `docker/scheduler/` as a standalone Docker image. It is **not wired into `compose.yaml`** and does not start with `sail up`. The `composer run dev` script makes no mention of `php artisan schedule:work` or any equivalent. The result is a development environment where feeds will never auto-refresh regardless of the queue worker being healthy.

**Note:** Production is unaffected. The `docker/scheduler/` container runs `php artisan scheduler:run-and-log` via cron every minute in production, dispatching jobs that Forge's queue daemon processes.

## Reproduction

Run `composer run dev` and inspect running processes inside the container:

```bash
$ ./vendor/bin/sail exec laravel.test ps aux | grep -E "schedule|artisan"
sail   16  php artisan serve --host=0.0.0.0 --port=80
sail   84  php artisan queue:listen --tries=3 --timeout=0
sail  ...  php artisan pail ...
```

No `schedule:work` or `schedule:run` process exists. Confirm with `scheduler:status`:

```bash
$ ./vendor/bin/sail artisan scheduler:status
Scheduler heartbeat missing (no scheduler ticks recorded yet)
```

Queue depth stays at 0 indefinitely — not because jobs are being processed, but because no jobs are ever dispatched:

```bash
$ ./vendor/bin/sail artisan queue:monitor database
[database] database .... [0] OK
Pending jobs ........... 0   ← no dispatches happening, not healthy consumption
Delayed jobs ........... 0
Reserved jobs .......... 0
```

## Root Cause Analysis

### Architecture

```
routes/console.php
  Schedule::job(new FetchFireIncidentsJob)   ← requires scheduler tick to fire
  Schedule::job(new FetchPoliceCallsJob)     ← requires scheduler tick to fire
  Schedule::job(new FetchTransitAlertsJob)   ← requires scheduler tick to fire
  Schedule::job(new FetchGoTransitAlertsJob) ← requires scheduler tick to fire
    ↓
  No scheduler running in dev
    ↓
  dispatch() never called
    ↓
  MySQL jobs table stays empty
    ↓
  Queue worker (now correctly inside container) has nothing to process
    ↓
  Feed data grows stale indefinitely
```

### Why `php artisan schedule:work` Must Run Via Sail

`schedule:work` dispatches due `Schedule::job()` entries by calling `dispatch(new Fetch*Job)`. Because `QUEUE_CONNECTION=database`, this write targets the MySQL `jobs` table using `DB_HOST=mysql` — a hostname that only resolves inside the Docker bridge network (`greater-toronto-area-alerts_sail`).

Running `php artisan schedule:work` on the host has the same failure mode as the FEED-007 queue worker: the host cannot resolve `mysql`, so the scheduler would crash on every dispatch attempt.

| Command | Runs on | Resolves `mysql`? | Works? |
|---|---|---|---|
| `php artisan schedule:work` | Host | No | Broken |
| `./vendor/bin/sail artisan schedule:work` | Container | Yes | Correct |

### Why the Dedicated Scheduler Container Doesn't Help in Dev

`docker/scheduler/` is a standalone Docker image built for production deployment (Forge). It:
- Is not defined as a service in `compose.yaml`
- Is not started by `sail up`
- Requires a separate build step (`docker build`)
- Is intentionally absent from the Sail development stack

There is no mechanism by which it participates in `composer run dev`.

### FEED-007 Relationship

FEED-007 fixed: queue worker on host → queue worker inside container.
This ticket fixes: no scheduler on host or container → scheduler inside container.

Both bugs were present simultaneously. FEED-007 was diagnosed first because the dead-job evidence (4077 accumulated jobs, all `attempts=0`) was visible. This bug was masked by it — a functioning queue worker with zero jobs dispatched looks identical to a broken queue worker with zero jobs dispatched.

## Fix Specification

### Approach

Add `./vendor/bin/sail artisan schedule:work` as a fifth concurrent process in the `composer run dev` (and `dev:ssr`) script. This mirrors the same pattern used for the queue worker in FEED-007.

`schedule:work` polls every minute, calling `schedule:run` to dispatch any due jobs. Because it runs inside the container via `sail artisan`, `DB_HOST=mysql` resolves and `dispatch()` writes successfully to the `jobs` table. The existing queue worker (also inside the container) then picks up and executes the jobs normally.

### File Changes

**`composer.json` — `scripts.dev`**

```diff
- "npx concurrently -c \"#93c5fd,#c4b5fd,#fb7185,#fdba74\" \"php artisan serve\" \"./vendor/bin/sail artisan queue:listen --tries=3 --timeout=0\" \"php artisan pail --timeout=0\" \"npm run dev\" --names=server,queue,logs,vite --kill-others"
+ "npx concurrently -c \"#93c5fd,#c4b5fd,#fb7185,#fdba74,#6ee7b7\" \"php artisan serve\" \"./vendor/bin/sail artisan queue:listen --tries=3 --timeout=0\" \"php artisan pail --timeout=0\" \"npm run dev\" \"./vendor/bin/sail artisan schedule:work\" --names=server,queue,logs,vite,schedule --kill-others"
```

**`composer.json` — `scripts.dev:ssr`**

```diff
- "npx concurrently -c \"#93c5fd,#c4b5fd,#fb7185,#fdba74\" \"php artisan serve\" \"./vendor/bin/sail artisan queue:listen --tries=3 --timeout=0\" \"php artisan pail --timeout=0\" \"php artisan inertia:start-ssr\" --names=server,queue,logs,ssr --kill-others"
+ "npx concurrently -c \"#93c5fd,#c4b5fd,#fb7185,#fdba74,#6ee7b7\" \"php artisan serve\" \"./vendor/bin/sail artisan queue:listen --tries=3 --timeout=0\" \"php artisan pail --timeout=0\" \"php artisan inertia:start-ssr\" \"./vendor/bin/sail artisan schedule:work\" --names=server,queue,logs,ssr,schedule --kill-others"
```

### What Changes

| Aspect | Before | After |
|---|---|---|
| Scheduler process | None | `schedule:work` inside Sail container |
| Job dispatch | Never happens | Fires every minute for due schedules |
| Queue worker idle reason | No jobs dispatched | Jobs dispatched and consumed |
| `scheduler:status` heartbeat | Missing | Written on each tick |
| Feed freshness | Stale indefinitely | Refreshes every 5–10 minutes |

### What Does NOT Change

- `routes/console.php` — Schedule configuration unchanged
- `app/Jobs/Fetch*Job.php` — Job classes unchanged
- `compose.yaml` — No Docker service changes
- All artisan commands and services — Unchanged
- Frontend / controllers / query layer — Unchanged
- Production deployment — Forge daemon and `docker/scheduler/` container unaffected
- Test suite — No test modifications required

### Edge Cases

**Sail containers not running**: If `sail up` hasn't been run, `./vendor/bin/sail artisan schedule:work` exits immediately. `--kill-others` terminates the session. This is the same fail-fast behavior as the FEED-007 queue worker fix — preferable to silently doing nothing.

**Overlapping dispatches**: Each scheduled job uses `withoutOverlapping(10)` (10-minute cache mutex). If a prior job is still running when `schedule:work` ticks, the dispatch is skipped safely.

**`schedule:work` vs `schedule:run`**: `schedule:work` is a long-running daemon that calls `schedule:run` every minute internally. `schedule:run` is intended for cron (one-shot per invocation). `schedule:work` is the correct choice for a foreground development process.

## Acceptance Criteria

- [ ] `composer run dev` starts a `schedule:work` process inside the Sail container
- [ ] `./vendor/bin/sail artisan scheduler:status` reports a valid heartbeat within 2 minutes of starting
- [ ] Fetch jobs (Fire, Police, TTC, GO Transit) appear in the `jobs` table within 5 minutes of starting
- [ ] Fetch jobs are processed by the queue worker and removed from the `jobs` table
- [ ] Source model `feed_updated_at` timestamps reflect the current time after a full scheduler cycle
- [ ] Same fix applied to `dev:ssr` script
- [ ] `composer run test` passes clean (no test changes needed)
