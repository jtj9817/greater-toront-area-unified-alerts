---
ticket_id: FEED-007
title: "[Bug] Scheduled Fetch Jobs Dispatched to Queue But Never Processed — Stale Feed Data"
status: Open
priority: Critical
assignee: Unassigned
created_at: 2026-02-23
tags: [bug, backend, scheduler, queue, infrastructure, data-freshness]
related_files:
  - routes/console.php
  - app/Jobs/FetchFireIncidentsJob.php
  - app/Jobs/FetchPoliceCallsJob.php
  - app/Jobs/FetchTransitAlertsJob.php
  - app/Jobs/FetchGoTransitAlertsJob.php
---

## Summary

All four feed fetch jobs (Fire, Police, TTC Transit, GO Transit) are scheduled via `Schedule::job()`, which serializes the job and dispatches it to the `database` queue (MySQL `jobs` table). No queue worker runs inside the Docker network where MySQL is reachable. Jobs accumulate indefinitely with zero processing attempts, and no new alert data enters the database.

The frontend correctly renders whatever the database contains — which is data from the last time a queue worker was active (Feb 18). The scheduler log shows "DONE" for each dispatch, but this reflects the ~20-50ms serialization/insert time, not actual command execution.

## Reproduction

**Observed state (Feb 23, 5 days after last fresh data):**

```
$ ./vendor/bin/sail artisan tinker --execute="
  echo \DB::table('jobs')->count();
  echo \DB::table('jobs')->min('created_at');
  echo \DB::table('jobs')->max('created_at');
  echo \DB::table('jobs')->where('attempts', '>', 0)->count();
"

4077          # total pending jobs
1771888200    # oldest: 2026-02-19 00:00:00 UTC
1771974000    # newest: 2026-02-23 23:20:00 UTC
0             # jobs ever attempted: ZERO
```

**Scheduler log (appears healthy but is misleading):**

```
2026-02-23 23:00:00 Running [fire:fetch-incidents] ............ 45.74ms DONE
2026-02-23 23:00:00 Running [police:fetch-calls] .............. 19.72ms DONE
2026-02-23 23:00:00 Running [transit:fetch-alerts] ............ 21.24ms DONE
2026-02-23 23:00:00 Running [go-transit:fetch-alerts] ......... 23.00ms DONE
```

The ~20-50ms completion times confirm these are queue dispatches, not actual HTTP fetches (which take 1-5 seconds each).

**Direct command execution (bypasses queue, works immediately):**

```
$ ./vendor/bin/sail artisan fire:fetch-incidents
Fetching Toronto Fire active incidents...
Done. 13 active incidents synced, 7 marked inactive. Feed time: 2026-02-23 22:55:01
```

## Root Cause Analysis

### Architecture

```
routes/console.php
  Schedule::job(new FetchFireIncidentsJob)    ← dispatches ShouldQueue job
    → serialized into MySQL `jobs` table       ← QUEUE_CONNECTION=database
      → awaits queue worker processing         ← NO WORKER EXISTS IN DOCKER
        → never processed                      ← attempts=0 for all 4077 jobs
```

### Component Breakdown

**1. Schedule configuration (`routes/console.php:19-22`)**

```php
Schedule::job(new FetchFireIncidentsJob)->name('fire:fetch-incidents')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::job(new FetchPoliceCallsJob)->name('police:fetch-calls')->everyTenMinutes()->withoutOverlapping(10);
Schedule::job(new FetchTransitAlertsJob)->name('transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::job(new FetchGoTransitAlertsJob)->name('go-transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
```

`Schedule::job()` creates a `JobSchedulingEvent` that, when due, calls `dispatch()` on the job class. Because all four job classes implement `ShouldQueue`, this inserts a serialized payload into the configured queue backend — NOT direct execution.

**2. Job classes (`app/Jobs/Fetch*Job.php`)**

All four implement `ShouldQueue` and delegate to artisan commands in `handle()`:

```php
class FetchFireIncidentsJob implements ShouldQueue
{
    use Queueable;
    public int $tries = 3;
    public int $backoff = 30;
    public int $timeout = 120;

    public function handle(): void
    {
        $exitCode = Artisan::call('fire:fetch-incidents');
        if ($exitCode !== 0) {
            throw new RuntimeException("fire:fetch-incidents failed with exit code {$exitCode}");
        }
    }
}
```

The `handle()` method is never invoked because no queue worker deserializes and executes the job.

**3. Queue configuration (`.env`)**

```
QUEUE_CONNECTION=database
DB_HOST=mysql
DB_PORT=3306
```

Jobs are stored in MySQL's `jobs` table. The `mysql` hostname only resolves inside the Docker bridge network (`greater-toronto-area-alerts_sail`).

**4. Docker services (`sail config`)**

```
Services running:
  - laravel.test   (web server, port 8080→80)
  - mysql          (database, port 3307→3306)
  - mysql-testing  (test database)
  - redis          (cache, port 6383→6379)

Services NOT running:
  - queue worker   ← MISSING
  - scheduler      ← runs externally (not a docker-compose service)
```

No queue worker service exists in the Docker composition. The `docker/scheduler/` directory contains Dockerfile and entrypoint for a scheduler container, but no corresponding worker infrastructure.

**5. Host queue worker (`composer run dev`)**

```json
"dev": [
    "npx concurrently ... \"php artisan queue:listen --tries=1 --timeout=0\" ..."
]
```

This runs `php artisan queue:listen` on the host OS. The host cannot resolve `DB_HOST=mysql` (Docker-internal hostname), so the worker either fails silently or connects to nothing. Even if port-mapped (`localhost:3307`), the `.env` specifies `mysql:3306`, not `localhost:3307`.

### Impact Path

```
Scheduler (Docker) dispatches ShouldQueue jobs to MySQL jobs table
  → No queue worker running in Docker network
    → Jobs accumulate (4077 since Feb 19, all attempts=0)
      → Fetch commands never execute
        → Database retains only Feb 18 data
          → UnifiedAlertsQuery returns stale results
            → Frontend displays "Updated: 4d ago" with old alerts
```

### Why Feb 18 Was the Last Fresh Data

The queue worker was last active on or before Feb 18. All jobs dispatched from Feb 19 onward have `attempts=0`, confirming no worker has connected to the `jobs` table since then.

## Fix Specification

### Approach

Replace `Schedule::job()` with `Schedule::command()` for all four fetch entries. `Schedule::command()` runs the artisan command **synchronously within the scheduler process**, bypassing the queue entirely. This eliminates the queue worker dependency for scheduled data fetching.

This is appropriate because:
- The fetch commands complete in 1-5 seconds each
- The scheduler already runs inside Docker where MySQL and external APIs are reachable
- `withoutOverlapping()` at the schedule level already prevents concurrent execution
- The job-level middleware (`WithoutOverlapping`, `$tries`, `$backoff`) is redundant with the schedule-level `withoutOverlapping(10)` setting
- `Schedule::command()` provides direct success/failure output in the scheduler log instead of misleading "dispatched in 20ms DONE"

### File Changes

**`routes/console.php`**

Replace lines 19-22:

```php
// Before:
Schedule::job(new FetchFireIncidentsJob)->name('fire:fetch-incidents')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::job(new FetchPoliceCallsJob)->name('police:fetch-calls')->everyTenMinutes()->withoutOverlapping(10);
Schedule::job(new FetchTransitAlertsJob)->name('transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::job(new FetchGoTransitAlertsJob)->name('go-transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);

// After:
Schedule::command('fire:fetch-incidents')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('police:fetch-calls')->everyTenMinutes()->withoutOverlapping(10);
Schedule::command('transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('go-transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
```

Remove unused imports:

```php
// Remove:
use App\Jobs\FetchFireIncidentsJob;
use App\Jobs\FetchGoTransitAlertsJob;
use App\Jobs\FetchPoliceCallsJob;
use App\Jobs\FetchTransitAlertsJob;
```

**Stale job cleanup (one-time operational step):**

```bash
./vendor/bin/sail artisan queue:clear
```

### Files NOT Changed

- `app/Jobs/Fetch*Job.php` — Retained as-is. They remain available for programmatic dispatch if a queue worker is added later, and are valid `ShouldQueue` jobs independent of the schedule path.
- `app/Console/Commands/Fetch*Command.php` — No changes. The artisan commands are the actual execution units and work correctly.
- Frontend / controllers / query layer — All functioning correctly. The issue is exclusively in how the scheduler triggers fetches.

## Acceptance Criteria

- [ ] `routes/console.php` uses `Schedule::command()` for all four fetch entries
- [ ] Unused `FetchXxxJob` imports removed from `routes/console.php`
- [ ] Stale jobs flushed from MySQL `jobs` table
- [ ] Scheduler log shows actual command execution output (not sub-50ms dispatch times)
- [ ] `feed_updated_at` on all source models reflects current time after next scheduler cycle
- [ ] Frontend displays fresh alerts with "Updated: Just now" instead of "Updated: Xd ago"
- [ ] `composer run test` passes clean
