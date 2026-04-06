---
ticket_id: FEED-007
title: "[Bug] Scheduled Fetch Jobs Dispatched to Queue But Never Processed — Stale Feed Data"
status: Closed
priority: Critical
assignee: Unassigned
created_at: 2026-02-23
tags: [bug, backend, scheduler, queue, infrastructure, data-freshness]
related_files:
  - composer.json
  - compose.yaml
---

## Summary

All four feed fetch jobs (Fire, Police, TTC Transit, GO Transit) are scheduled via `Schedule::job()`, which serializes the job and dispatches it to the `database` queue (MySQL `jobs` table). The `composer run dev` script runs `php artisan queue:listen` on the **host OS**, but the host cannot resolve `DB_HOST=mysql` (a Docker-internal hostname). No queue worker runs inside the Docker network where MySQL is reachable. Jobs accumulate indefinitely with zero processing attempts, and no new alert data enters the database.

The frontend correctly renders whatever the database contains — which is data from the last time a queue worker was active (Feb 18). The scheduler log shows "DONE" for each dispatch, but this reflects the ~20-50ms serialization/insert time, not actual command execution.

**Note:** Production is unaffected. Forge manages the queue worker as a daemon (`queue:work --tries=3 --sleep=3 --max-time=3600`, 2 processes). This is strictly a local development environment bug.

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
      → awaits queue worker processing         ← NO WORKER IN DOCKER NETWORK
        → never processed                      ← attempts=0 for all 4077 jobs
```

### Why the Host Queue Worker Fails

The `composer run dev` script was designed for a **non-Docker, SQLite-based workflow** (matching `.env.example`), but the actual development environment uses **Sail with MySQL**:

| | `.env.example` (non-Docker) | Actual `.env` (Sail/Docker) |
|---|---|---|
| DB | `DB_CONNECTION=sqlite` | `DB_CONNECTION=mysql`, `DB_HOST=mysql` |
| Redis | `REDIS_HOST=127.0.0.1` | `REDIS_HOST=redis` |
| Queue | `database` (SQLite — local) | `database` (MySQL — Docker-internal) |
| Host `queue:listen` | **Works** (SQLite is local) | **Broken** (can't resolve `mysql`) |

The `composer run dev` queue worker runs on the host where `DB_HOST=mysql` is unreachable. Sail containers (where MySQL is accessible) have no queue worker process.

### Component Breakdown

**1. Schedule configuration (`routes/console.php:19-22`)**

```php
Schedule::job(new FetchFireIncidentsJob)->name('fire:fetch-incidents')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::job(new FetchPoliceCallsJob)->name('police:fetch-calls')->everyTenMinutes()->withoutOverlapping(10);
Schedule::job(new FetchTransitAlertsJob)->name('transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::job(new FetchGoTransitAlertsJob)->name('go-transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping(10);
```

`Schedule::job()` creates a `CallbackEvent` that, when due, calls `dispatch()` on the job class. Because all four job classes implement `ShouldQueue`, this inserts a serialized payload into the configured queue backend — NOT direct execution. The schedule configuration is correct and should NOT be changed (see "Rejected Approaches" below).

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

**4. Docker services (`compose.yaml` / `sail ps`)**

```
Services running:
  - laravel.test   (web server, port 8080→80)
  - mysql          (database, port 3307→3306)
  - mysql-testing  (test database, testing profile)
  - redis          (cache, port 6383→6379)

Services NOT running:
  - queue worker   ← MISSING
  - scheduler      ← runs externally (not a compose service)
```

**5. Host queue worker (`composer run dev`)**

```json
"dev": [
    "npx concurrently ... \"php artisan queue:listen --tries=1 --timeout=0\" ..."
]
```

This runs `php artisan queue:listen` on the host OS. The host cannot resolve `DB_HOST=mysql` (Docker-internal hostname), so the worker silently fails to process jobs.

**6. Production queue worker (Forge — unaffected)**

```
Command:   php artisan queue:work --queue=default --tries=3 --sleep=3 --max-time=3600
Processes: 2
```

Forge's daemon runs on the same server as MySQL. The production scheduler container dispatches jobs, and Forge's workers process them. This path works correctly.

### Pre-existing Issue: `--tries=1` Override

The dev script uses `--tries=1`, which overrides the job classes' `$tries = 3`. Even if the queue worker COULD connect, failed jobs would be discarded after a single attempt instead of retrying. Production's Forge daemon correctly uses `--tries=3`.

### Impact Path

```
Scheduler (Docker) dispatches ShouldQueue jobs to MySQL jobs table
  → Host queue worker (composer run dev) can't resolve DB_HOST=mysql
    → Jobs accumulate (4077 since Feb 19, all attempts=0)
      → Fetch commands never execute
        → Database retains only Feb 18 data
          → UnifiedAlertsQuery returns stale results
            → Frontend displays "Updated: 4d ago" with old alerts
```

### Why Feb 18 Was the Last Fresh Data

The queue worker was last active on or before Feb 18. All jobs dispatched from Feb 19 onward have `attempts=0`, confirming no worker has connected to the `jobs` table since then.

## Rejected Approaches

### Changing `Schedule::job()` to `Schedule::command()`

Initially considered but rejected. The job-based schedule was an intentional architectural decision for resilience:

- **Scheduler isolation**: If an artisan command throws, `Schedule::command()` propagates the exception into the scheduler process. `Schedule::job()` only dispatches (< 50ms) — the scheduler is never blocked or crashed by fetch failures.
- **Automatic retries**: Job classes define `$tries = 3` with `$backoff = 30`. A transient API failure retries automatically. With `Schedule::command()`, the next retry is at the next schedule tick (5-10 minutes later).
- **Production impact**: `routes/console.php` is shared between environments. Changing to `Schedule::command()` would bypass Forge's 2-process queue daemon in production, losing parallel processing and the retry infrastructure.
- **Test validation**: `SchedulerResiliencePhase2Test` explicitly validates that fetch events are job-based, not command-based.

### Using `QUEUE_CONNECTION=sync` in development

Rejected. Sync queue runs jobs inline in the caller, eliminating retry behavior and blocking the scheduler during execution — the exact failure mode the job-based architecture prevents.

### Environment-based hybrid (`Schedule::job()` in prod, `Schedule::command()` in dev)

Rejected. Creates two scheduling code paths, prevents testing the production schedule locally, and adds conditional complexity.

## Fix Specification

### Approach

Fix the `composer run dev` queue worker to run **inside the Sail container** (where `DB_HOST=mysql` resolves) instead of on the host. Also align `--tries=3` with the job classes and production Forge configuration.

This preserves:
- The job-based schedule architecture and its resilience properties
- The production deployment path (Forge daemon, scheduler container)
- The existing test suite (no test changes required)

### File Changes

**`composer.json` — `scripts.dev`**

```diff
- "php artisan queue:listen --tries=1 --timeout=0"
+ "./vendor/bin/sail artisan queue:listen --tries=3 --timeout=0"
```

**`composer.json` — `scripts.dev:ssr`**

```diff
- "php artisan queue:listen --tries=1"
+ "./vendor/bin/sail artisan queue:listen --tries=3"
```

**Stale job cleanup (one-time operational step):**

```bash
./vendor/bin/sail artisan queue:clear
```

### What Changes

| Aspect | Before | After |
|--------|--------|-------|
| Queue worker runs on | Host OS | Inside Sail container (via `docker exec`) |
| MySQL connectivity | Broken (`mysql` unresolvable) | Works (`mysql` resolves on Docker network) |
| `--tries` | 1 (overrides job's 3) | 3 (aligned with job classes and Forge) |
| Job processing | Never happens | Jobs processed within seconds of dispatch |

### What Does NOT Change

- `routes/console.php` — Schedule stays job-based
- `app/Jobs/Fetch*Job.php` — Job classes unchanged
- `compose.yaml` — No Docker service changes
- `app/Console/Commands/Fetch*Command.php` — Artisan commands unchanged
- Frontend / controllers / query layer — All functioning correctly
- Production deployment — Forge daemon and scheduler container unaffected
- Test suite — No test modifications required

### Edge Cases

**Sail containers not running**: If `sail up` hasn't been run, `./vendor/bin/sail artisan queue:listen` fails immediately. The `--kill-others` flag on `concurrently` kills all processes — fail-fast behavior, preferable to silently accumulating dead jobs.

**Host `php artisan serve` redundancy**: The dev script also runs `php artisan serve` on the host (port 8000), which is redundant with Sail (port 8080). This is a pre-existing issue and out of scope for this fix.

## Acceptance Criteria

- [x] `composer run dev` queue worker runs inside Sail container
- [x] `--tries=3` aligned with job classes and production Forge config
- [x] Same fix applied to `dev:ssr` script
- [x] Stale jobs flushed from MySQL `jobs` table
- [x] `feed_updated_at` on all source models reflects current time after next scheduler cycle
- [x] Frontend displays fresh alerts with "Updated: Just now" instead of "Updated: Xd ago"
- [x] `composer run test` passes clean (no test changes needed)

## Closure

**Fixed** by commit `e9913c88` ("fix(dev): run queue worker inside Sail container instead of host") and `a6edcfdb` ("fix(dev): add schedule:work inside Sail container to dev script"). The queue worker now runs inside the Docker bridge network where `DB_HOST=mysql` is reachable. Also see `FEED-008` for the `schedule:work` addition.
