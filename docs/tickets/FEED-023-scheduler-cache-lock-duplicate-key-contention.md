---
ticket_id: FEED-023
title: "[Bug] Resolve scheduler cache_locks duplicate-key contention on PostgreSQL"
status: In Progress
priority: High
assignee: Unassigned
created_at: 2026-03-12
tags: [scheduler, cache, postgres, concurrency, reliability]
related_files:
  - docker/scheduler/laravel-scheduler
  - routes/console.php
  - config/cache.php
  - .env
  - .env.example
  - app/Services/ScheduledFetchJobDispatcher.php
  - tests/Feature/Console/SchedulerResiliencePhase1Test.php
  - docs/backend/production-scheduler.md
  - docs/runbooks/scheduler-troubleshooting.md
  - storage/logs/laravel.log
  - storage/logs/queue_enqueues.log
---

## Summary

PostgreSQL is logging:

```text
duplicate key value violates unique constraint "cache_locks_pkey"
Key (key)=(greater-toronto-area-alerts-cache-framework/schedule-d01f8638f497b81f25e53307746ca0563c185d45) already exists
```

This is the scheduler overlap mutex for `fire:fetch-incidents`, not an app
table write. The lock contention is happening because more than one
`schedule:run` process is active for the same minute while scheduler mutexes are
stored in the database cache lock table.

## Implementation Update (2026-03-12)

The last two FEED-023 commits applied the following ticket changes:

- Added a required **Phase 0 pre-flight gate** in scheduler docs with explicit
  checks for:
  - single scheduler authority
  - Redis health before shared lock-store migration
  - explicit lock-store selection for scheduler and unique-job locks
  - `php artisan optimize:clear` before verification
- Added explicit env-template guidance in `.env.example`:
  - `SCHEDULE_CACHE_STORE=file` for local single-node runtime
  - documented production/shared recommendation:
    `SCHEDULE_CACHE_STORE=redis`
- Added single-authority guardrail comments in
  `docker/scheduler/laravel-scheduler` to prevent running cron scheduler and
  `schedule:work` simultaneously.
- Implemented explicit scheduler mutex-store configuration in
  `config/cache.php`:
  - `'schedule_store' => env('SCHEDULE_CACHE_STORE', env('SCHEDULE_CACHE_DRIVER'))`
- Added regression coverage in
  `tests/Feature/Console/SchedulerResiliencePhase1Test.php` asserting kernel
  scheduler cache resolution honors `cache.schedule_store`.

---

## Bug Analysis

### 1. [FILES INVOLVED]

- `routes/console.php`
  - Registers `fire:fetch-incidents` as a named scheduled callback with
    `->withoutOverlapping(10)`.
- `config/cache.php`
  - Uses `CACHE_STORE` as default cache store (currently `database`).
- `.env`
  - Sets `CACHE_STORE=database`, so scheduler mutexes are written to
    `cache_locks`.
- `app/Services/ScheduledFetchJobDispatcher.php`
  - Logs enqueue/skip outcomes; these logs expose duplicate scheduler attempts.
- `storage/logs/queue_enqueues.log`
  - Shows `schedule:run` enqueuing in the same minute from different PIDs.
- `storage/logs/laravel.log`
  - Shows same-minute `enqueued` + `unique_lock_held` skip pairs, indicating
    duplicate scheduler execution attempts.
- `vendor/laravel/framework/src/Illuminate/Console/Scheduling/CallbackEvent.php`
  - Generates scheduler lock key from `sha1($eventName)`.
- `vendor/laravel/framework/src/Illuminate/Console/Scheduling/CacheEventMutex.php`
  - Acquires event mutex through cache lock provider.
- `vendor/laravel/framework/src/Illuminate/Cache/DatabaseLock.php`
  - Lock acquire path inserts first; concurrent insert collision surfaces as DB
    unique-key error.

### 2. [FUNCTIONS INVOLVED]

- `Schedule::call(...)->name(...)->withoutOverlapping(...)` in
  `routes/console.php`
- `Illuminate\Console\Scheduling\CallbackEvent::mutexName()`
- `Illuminate\Console\Scheduling\CacheEventMutex::create()`
- `Illuminate\Cache\DatabaseLock::acquire()`
- `App\Services\ScheduledFetchJobDispatcher::dispatchUnique()`

### 3. [CODE INVOLVED]

- Scheduler registration:

```php
Schedule::call(function (ScheduledFetchJobDispatcher $dispatcher): void {
    $dispatcher->dispatchFireIncidents();
})->name('fire:fetch-incidents')->everyFiveMinutes()->withoutOverlapping(10);
```

- Lock key generation:

```php
return 'framework/schedule-'.sha1($this->description ?? '');
```

- Hash confirmation:
  - `sha1('fire:fetch-incidents') = d01f8638f497b81f25e53307746ca0563c185d45`
  - This matches the failing key suffix in PostgreSQL logs.

- Database lock acquisition behavior:

```php
try {
    $this->connection->table($this->table)->insert([...]);
} catch (QueryException) {
    $updated = $this->connection->table($this->table)->where(...)->update([...]);
}
```

### 4. [REASONING]

From first principles:

1. `cache_locks.key` is a primary key, so duplicate inserts on the same lock
   key will throw at the database layer.
2. `withoutOverlapping()` for named callbacks uses a lock key based on
   `framework/schedule-<sha1(event-name)>`.
3. The reported hash maps exactly to `fire:fetch-incidents`, proving the
   collision is on scheduler event locking.
4. The app currently stores locks in database cache (`CACHE_STORE=database`),
   so lock contention is visible as PostgreSQL unique-key errors.
5. Runtime evidence shows duplicate scheduler execution attempts in the same
   minute:
   - `queue_enqueues.log` at `2026-03-12 21:55:00` shows `schedule:run`
     enqueues from PID `126140` and PID `126139`.
   - `laravel.log` at the same timestamp shows paired
     `Scheduled fetch job enqueued` and `Scheduled fetch job skipped` entries.
6. Therefore, the immediate cause is scheduler process duplication (multiple
   `schedule:run` invocations) and the noise symptom is amplified by
   database-backed scheduler mutex storage.

---

## Resolution Plan

### GOAL:

Eliminate `cache_locks_pkey` duplicate-key errors from normal scheduler lock
contention, while preserving safe overlap prevention and job deduplication.

### CONTEXT:

- Scheduled callbacks in `routes/console.php` intentionally use
  `withoutOverlapping(...)`.
- Duplicate scheduler invocations are occurring in runtime.
- Scheduler mutexes are currently persisted to PostgreSQL lock rows.
- PostgreSQL reports lock-contention inserts as `ERROR`, even when Laravel
  handles contention and skips execution.

### TASK:

1. Enforce a single scheduler runner in each environment.
2. Move scheduler mutex storage off database locks to a dedicated cache store.
3. Keep current overlap and dedupe behavior intact.
4. Add explicit diagnostics/runbook guidance to detect duplicate scheduler
   processes quickly.

### REQUIREMENTS:

- Keep `withoutOverlapping(...)` semantics for scheduled tasks.
- Do not regress scheduled fetch dedupe behavior in
  `ScheduledFetchJobDispatcher`.
- Maintain compatibility for local dev and production.
- Ensure scheduler lock-store configuration is explicit and documented.

### GUIDELINES:

#### Step 0: Redis and scheduler pre-flight gate (required before implementation)

Status: **Completed** (implemented in docs/env + scheduler cron guidance)

- **Files:** `.env`, `.env.example`, deployment/runtime scheduler config (cron or
  process manager), `docs/runbooks/scheduler-troubleshooting.md`
- **Change:**
  - Verify exactly one scheduler authority is active per environment:
    - either `schedule:work`
    - or cron `schedule:run` / `scheduler:run-and-log`
    - never both at the same time.
  - Verify Redis service health before lock-store migration.
  - Set scheduler lock store explicitly:
    - `SCHEDULE_CACHE_STORE=redis` (production / shared runtime)
    - `SCHEDULE_CACHE_STORE=file` (acceptable local single-node fallback)
  - For lock behavior consistency across scheduler + unique jobs, set
    `QUEUE_UNIQUE_LOCK_STORE=redis` where Redis is available.
  - Clear and reload framework caches (`php artisan optimize:clear`) before
    verification runs.
- **Why:** Prevents partial rollout where lock-store changes are made while
  duplicate scheduler processes are still active.

#### Step 1: Add explicit scheduler lock-store configuration

Status: **Completed** (`config/cache.php`)

- **File:** `config/cache.php`
- **Change:** Add a new top-level config key:
  - `'schedule_store' => env('SCHEDULE_CACHE_STORE', env('SCHEDULE_CACHE_DRIVER'))`
- **Why:** Laravel Kernel resolves scheduler mutex store from
  `cache.schedule_store` (or `SCHEDULE_CACHE_*` env vars). Making it explicit
  removes ambiguity and improves config discoverability.

#### Step 2: Define safe defaults in environment templates

Status: **Completed** (`.env.example`)

- **File:** `.env.example`
- **Change:**
  - Add `SCHEDULE_CACHE_STORE=file` for local/single-node defaults.
  - Add production note recommending `SCHEDULE_CACHE_STORE=redis` for shared
    scheduler environments.
- **Why:** Prevent scheduler mutex writes to `cache_locks` by default.

#### Step 3: Update scheduler operations documentation

Status: **Completed** (`docs/backend/production-scheduler.md`,
`docs/runbooks/scheduler-troubleshooting.md`)

- **Files:** `docs/backend/production-scheduler.md`,
  `docs/runbooks/scheduler-troubleshooting.md`
- **Change:**
  - Add a “single scheduler authority” section: run exactly one of:
    - daemon `schedule:work`, or
    - cron `schedule:run` / `scheduler:run-and-log`
    - never both simultaneously.
  - Add a verification checklist:
    - detect duplicate scheduler commands/processes
    - detect duplicate cron entries
    - verify active lock store (`SCHEDULE_CACHE_STORE`).
- **Why:** The present failure pattern is operational concurrency, not a model
  schema issue.

#### Step 4: Add regression tests for scheduler lock store config

Status: **Completed** (`tests/Feature/Console/SchedulerResiliencePhase1Test.php`)

- **File:** `tests/Feature/Console/SchedulerResiliencePhase1Test.php`
- **Change:**
  - Add tests that assert scheduler runs cleanly with non-database cache store.
  - Add tests to ensure scheduled events remain `withoutOverlapping(...)`.
- **Why:** Prevent future regressions where scheduler mutex storage drifts back
  to database lock tables unexpectedly.

#### Step 5: Verify in runtime

Status: **Pending** (requires environment-level verification)

- **Files/commands affected:** runtime ops, no app logic change
- **Checks:**
  - Confirm single scheduler process pattern in logs.
  - Confirm absence of `cache_locks_pkey` duplicate-key errors for scheduler
    lock keys.
  - Confirm scheduled jobs still enqueue at expected cadence.

---

## Acceptance Criteria

- No new PostgreSQL `cache_locks_pkey` errors for scheduler mutex keys during
  normal scheduling.
- A pre-flight checklist is present and enforced before lock-store migration:
  - Redis health confirmed.
  - Single scheduler authority confirmed.
  - `SCHEDULE_CACHE_STORE` explicitly configured.
- Only one scheduler authority is active per environment.
- Scheduled fetch cadence remains unchanged.
- `withoutOverlapping(...)` safeguards remain active.
- Runbooks clearly document how to verify scheduler uniqueness and lock-store
  configuration.
