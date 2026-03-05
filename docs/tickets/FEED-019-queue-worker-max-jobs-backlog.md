---
ticket_id: FEED-019
title: "[Bug] Dev queue worker exits after 1000 jobs and leaves fetch backlog unprocessed"
status: Open
priority: Critical
assignee: Unassigned
tags: [bug, backend, queue, scheduler, dev-environment, data-freshness, reliability]
related_files:
  - composer.json
  - routes/console.php
  - app/Jobs/FetchFireIncidentsJob.php
  - app/Jobs/FetchPoliceCallsJob.php
  - app/Jobs/FetchTransitAlertsJob.php
  - app/Jobs/FetchGoTransitAlertsJob.php
---

## Summary

The current development runtime starts the queue worker with
`--max-jobs=1000`. After processing 1000 jobs, the worker exits cleanly and is
not restarted. `schedule:work` continues running and keeps enqueueing fetch
jobs, so the `jobs` table grows indefinitely and feed data stops refreshing
automatically.

The recurring `Queue depth exceeded threshold` log entry is a downstream alarm,
not the root cause.

## Problem Statement

Automatic refresh for transit, police, fire, and GO Transit data depends on two
separate long-running processes:

- `schedule:work` to enqueue due fetch jobs
- `queue:work` to execute those queued jobs

In the current `composer dev` configuration, the scheduler remains alive while
the queue worker silently ages out after its configured job cap. Once that
happens:

- new fetch jobs continue to accumulate in `jobs`
- no queued fetch jobs are processed
- database alert freshness stops advancing on its own
- queue depth alarms fire every five minutes

## Findings

### 1. The scheduler is healthy and still enqueueing work

The scheduler registers the four feed fetch jobs plus the queue depth monitor in
[routes/console.php](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/routes/console.php#L19).

Observed runtime behavior showed repeated entries of:

- `Queue job enqueued` for `FetchFireIncidentsJob`
- `Queue job enqueued` for `FetchPoliceCallsJob`
- `Queue job enqueued` for `FetchTransitAlertsJob`
- `Queue job enqueued` for `FetchGoTransitAlertsJob`
- `Queue depth exceeded threshold`

This confirms scheduling continued even while the backlog grew.

### 2. No queue worker process was running when the backlog was inspected

Process inspection showed:

- `schedule:work` still running
- `php artisan serve`, `vite`, and `pail` still running
- no active `queue:work` process on the host
- no active `php artisan queue:work` process inside the `laravel.test` container

This matches a worker that exited and was never restarted.

### 3. The worker is configured to self-terminate after 1000 jobs

The development scripts in
[composer.json](/mnt/0B8533211952FCF2/greater-toronto-area-alerts/composer.json#L50)
start the queue worker as:

```text
./vendor/bin/sail artisan queue:work --tries=3 --max-jobs=1000 --sleep=3
```

That configuration is appropriate only if another supervisor restarts the
worker. In `composer dev`, no such restart path exists.

### 4. The scheduler continues after the worker exits

`composer dev` uses `concurrently --kill-others-on-fail`, but a worker that
stops because it reached `--max-jobs=1000` exits normally rather than failing.
That means:

- the queue worker can disappear without tearing down the rest of the stack
- the scheduler continues to enqueue work
- backlog accumulation becomes silent aside from queue depth alarms

### 5. The backlog matches the feed freshness stall

Queue inspection showed:

- `1235` pending jobs at first inspection
- `1249` pending jobs after manual diagnostics, confirming continued growth
- backlog heavily dominated by recurring fetch jobs:
  - `App\Jobs\FetchGoTransitAlertsJob`
  - `App\Jobs\FetchTransitAlertsJob`
  - `App\Jobs\FetchFireIncidentsJob`
  - `App\Jobs\FetchPoliceCallsJob`

The oldest pending fetch jobs aligned with the point at which automatic feed
freshness stopped advancing.

### 6. The fetch commands themselves are not the sustained failure

Manual execution inside Sail succeeded for both affected feeds:

- `./vendor/bin/sail artisan transit:fetch-alerts`
- `./vendor/bin/sail artisan police:fetch-calls`

After those manual runs, database timestamps advanced again, confirming the
commands and upstream feeds were still functional.

There was one recorded failed transit job, but it does not explain the wider
cross-feed stall because the backlog affected fire, police, TTC, and GO fetch
jobs together.

## Root Cause

The development environment relies on a long-running queue worker, but the
worker is intentionally configured to stop after 1000 processed jobs and is not
supervised or relaunched. Once it exits, the scheduler keeps dispatching fetch
jobs into the database queue and the system degrades into a steadily increasing
backlog with stale feed data.

## Impact

- Transit alerts stop auto-refreshing.
- Police alerts stop auto-refreshing.
- Fire and GO Transit fetch jobs also accumulate in the same backlog.
- Queue depth monitoring becomes noisy but does not self-heal the failure.
- Local debugging becomes misleading because scheduler logs still look healthy.

## Evidence

- `jobs` table backlog exceeded the configured alert threshold by an order of
  magnitude.
- Pending jobs were dominated by the four recurring fetch job classes.
- `failed_jobs` contained only one recent fetch failure, which is inconsistent
  with the scale of the backlog.
- Manual fetch commands completed successfully and advanced persisted feed
  timestamps immediately.

## Acceptance Criteria

- [ ] `composer dev` keeps a queue worker alive for the full session instead of
      letting it disappear after a fixed job count.
- [ ] If a bounded worker lifetime is retained, the worker is automatically
      restarted without manual intervention.
- [ ] Queue depth remains near steady state during normal development use rather
      than growing continuously.
- [ ] Scheduled fetch jobs are processed shortly after enqueue instead of
      accumulating in `jobs`.
- [ ] Feed freshness for transit, police, fire, and GO Transit advances
      automatically while `composer dev` is running.
- [ ] Operational logs clearly distinguish scheduler dispatch from actual queue
      execution so stale data is easier to diagnose.

## Notes

- This is a follow-on reliability issue after FEED-007 and FEED-008. Those
  tickets addressed worker placement and scheduler presence. This ticket covers
  worker lifecycle and supervision.
- The `Queue depth exceeded threshold` error should be treated as a symptom of
  stalled queue consumption, not as the primary fault.
