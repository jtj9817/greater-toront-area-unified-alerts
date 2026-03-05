---
ticket_id: FEED-020
title: "[Reliability] Deduplicate scheduled fetch jobs before enqueue"
status: Open
priority: High
assignee: Unassigned
created_at: 2026-03-05
tags: [reliability, backend, queue, scheduler, dev-environment, data-freshness]
related_files:
  - routes/console.php
  - app/Jobs/FetchFireIncidentsJob.php
  - app/Jobs/FetchPoliceCallsJob.php
  - app/Jobs/FetchTransitAlertsJob.php
  - app/Jobs/FetchGoTransitAlertsJob.php
  - app/Console/Commands/FetchFireIncidentsCommand.php
  - app/Console/Commands/FetchPoliceCallsCommand.php
  - app/Console/Commands/FetchTransitAlertsCommand.php
  - app/Console/Commands/FetchGoTransitAlertsCommand.php
  - tests/Feature/Console/SchedulerResiliencePhase1Test.php
  - docs/tickets/FEED-019-queue-worker-max-jobs-backlog.md
---

## Summary

The scheduler currently enqueues fetch jobs on every cadence tick even when an
older copy of the same fetch job is already pending or running. Because the
fire, police, TTC, and GO Transit fetch commands each reconcile against the
latest upstream snapshot at execution time, a backlog of duplicate fetch jobs
does not preserve unique historical work. It mostly creates redundant API calls
and delays recovery.

This should be refactored so fetch-job uniqueness is enforced before enqueue,
not only during execution.

## Problem Statement

The current scheduled registrations in `routes/console.php` use
`Schedule::job(new Fetch*Job)` for all four ingestion sources. That means every
scheduled tick inserts a new queue row regardless of whether the same logical
fetch job is already outstanding.

Today the only duplicate protection lives inside each queued job via
`WithoutOverlapping(...)`. That protects execution concurrency, but it does not
protect queue depth:

- a stalled worker lets duplicate fetch jobs accumulate indefinitely
- a recovered worker drains those duplicates one at a time
- each drained duplicate performs another full upstream fetch
- later jobs do not represent older data; they still fetch "now"

As a result, the queue can hold dozens or hundreds of redundant copies of:

- `App\Jobs\FetchFireIncidentsJob`
- `App\Jobs\FetchPoliceCallsJob`
- `App\Jobs\FetchTransitAlertsJob`
- `App\Jobs\FetchGoTransitAlertsJob`

## Why The Backlog Is Redundant

Each of the four fetch jobs is just a queue wrapper around an Artisan command:

- `FetchFireIncidentsJob` -> `fire:fetch-incidents`
- `FetchPoliceCallsJob` -> `police:fetch-calls`
- `FetchTransitAlertsJob` -> `transit:fetch-alerts`
- `FetchGoTransitAlertsJob` -> `go-transit:fetch-alerts`

Those commands do not process a historical payload captured when the job was
queued. They fetch the current upstream state when `handle()` runs and then
reconcile local persistence:

- upsert active records from the latest feed snapshot
- mark records inactive if they are missing from the current snapshot
- emit notifications only for newly created or re-activated alerts

That means one successful fetch per source is enough to converge the database
to the latest known upstream state. Three pending duplicates of the same fetch
job are not a correctness requirement, and hundreds of them are pure backlog.

## Current Root Cause

Queue uniqueness is enforced at the wrong layer.

Current design:

1. Scheduler always dispatches a new fetch job at cadence time.
2. Queue stores every copy as a separate pending row.
3. Job middleware blocks overlapping execution of the same source.
4. Duplicate rows remain in `jobs` until a worker eventually reaches them.

Desired design:

1. Scheduler decides whether a fetch job for that source is already
   pending/running.
2. Scheduler skips enqueue when an equivalent outstanding fetch job exists.
3. Job middleware remains in place as a second safety layer during execution.

## Jobs And Logic That Need To Be Edited

### Scheduler dispatch layer

The primary refactor point is `routes/console.php`.

The four current `Schedule::job(new Fetch*Job)` registrations need to move to a
dispatch path that can make a uniqueness decision before calling `dispatch()`.

That likely means replacing direct `Schedule::job(...)` usage with one of:

- `Schedule::call(...)` plus a shared dispatcher service
- `Schedule::call(...)` plus a dedicated helper/command for unique fetch
  dispatch
- a custom abstraction that wraps `dispatch()` with fetch-job dedupe semantics

### Fetch job classes

These job classes should be reviewed together because they represent the same
queue pattern and should behave consistently:

- `app/Jobs/FetchFireIncidentsJob.php`
- `app/Jobs/FetchPoliceCallsJob.php`
- `app/Jobs/FetchTransitAlertsJob.php`
- `app/Jobs/FetchGoTransitAlertsJob.php`

Expected changes are likely to include one or more of:

- introducing explicit unique keys per source
- implementing Laravel unique-job interfaces if they fit the queue semantics
- keeping `WithoutOverlapping(...)` as execution-time protection
- aligning retry/timeout behavior across all four classes where needed

### Shared queue refactor

This should not be solved as four independent one-off checks.

The queue logic should be refactored into a shared mechanism, for example:

- `ScheduledFetchJobDispatcher`
- `DispatchUniqueFetchJob`
- `FetchJobUniquenessGuard`

That shared mechanism should own:

- the unique key for each fetch source
- the dispatch-time check for pending/running duplicates
- structured logging for "enqueued" vs "skipped because already queued"
- behavior when a previous job failed or a uniqueness lock becomes stale

## Refactor Requirements

### 1. Enforce uniqueness before enqueue

At most one outstanding fetch job per source should exist across:

- pending jobs
- reserved/running jobs
- any in-flight uniqueness lock used to guard dispatch

Retry attempts on the same logical job are not the same thing as separate
scheduled duplicates and should not justify multiple queued copies.

### 2. Preserve execution-time protection

Do not remove the existing `WithoutOverlapping(...)` middleware from the fetch
jobs unless an equivalent or stronger execution-time guard replaces it.

Dispatch-time dedupe and execution-time overlap protection solve different
problems and both are useful.

### 3. Make skip behavior observable

Operational logs should clearly show when the scheduler:

- enqueued a fetch job because no equivalent job was outstanding
- skipped a fetch job because one was already pending/running

This is important for diagnosing "why is no new row appearing in `jobs`?" after
the dedupe logic is introduced.

### 4. Recover cleanly after backlog or failure

After a worker outage or pause:

- one resumed fetch job per source should be enough to catch up
- the scheduler should not continue inflating the queue with duplicates
- subsequent cadence ticks should enqueue normally again after the outstanding
  job completes or the uniqueness guard is released

### 5. Avoid destructive queue cleanup by accident

If implementation includes pruning already-queued duplicates, it must be done
carefully and explicitly. The primary requirement is to prevent future
duplicates. Any one-time cleanup logic for existing backlog should be deliberate
and test-covered, not incidental side effect.

## Acceptance Criteria

- [ ] Scheduler does not enqueue a second copy of the same fetch job while an
      equivalent fetch job for that source is already pending or running.
- [ ] Under normal operation, the queue contains at most one outstanding fetch
      job per source.
- [ ] After a worker outage, resuming the worker processes one latest-state
      fetch per source rather than draining a large redundant backlog.
- [ ] Existing execution-time overlap protection for the four fetch jobs
      remains in place or is replaced with equivalent safeguards.
- [ ] Logs distinguish fetch-job enqueue from fetch-job skip due to duplicate
      outstanding work.
- [ ] Automated tests cover duplicate-skip behavior, post-completion
      re-dispatch, and failure/retry edge cases for the dedupe path.

## Testing Expectations

Add or update automated coverage for:

- scheduler dispatch of each fetch source when no outstanding job exists
- scheduler skip when an equivalent job is already pending
- scheduler skip when an equivalent job is already reserved/running
- scheduler re-dispatch after the prior job completes
- correct behavior when a previous job fails and should be eligible again

Likely test locations:

- `tests/Feature/Console/SchedulerResiliencePhase1Test.php`
- new focused tests for the shared dispatch/dedupe abstraction
- targeted unit coverage for any unique-key or queue-inspection logic

## Notes

- This is a follow-on to FEED-019. FEED-019 fixes the worker lifecycle so the
  queue worker stays alive. FEED-020 reduces redundant backlog generation when
  the worker is paused, slow, or temporarily absent.
- The fetch commands are full-snapshot reconcilers, not historical replay
  processors. That is why duplicate queued fetch jobs are wasteful rather than
  necessary.
- A good end state is a two-layer model:
  - dispatch-time uniqueness to prevent redundant queue rows
  - execution-time overlap protection to prevent concurrent runs of the same
    source
