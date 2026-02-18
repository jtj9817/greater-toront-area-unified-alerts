# Review: Scheduler Resilience Phase 1 (Commit 0d35cf2)

**Status:** Closed
**Priority:** Medium
**Assignee:** Joshua Jadulco
**Labels:** code-review, scheduler, resilience, technical-debt
**Verified on codebase (2026-02-18):** `monitor:queue-depth` uses `Queue::size()`, fire deactivation intel processing is try/catch protected, and the test uses the default DB connection dynamically.

## Overview
A comprehensive review of the Phase 1 scheduler resilience changes was performed. The changes largely align with the implementation plan and successfully introduce top-level exception handling and basic monitoring. However, a few robustness issues and inconsistencies were identified that should be addressed to fully meet the "resilience" goal.

## Findings

### 1. Queue Depth Monitor is Driver-Dependent
**Severity:** MEDIUM
**File:** `routes/console.php`

The current implementation of the queue depth check explicitly queries the database:
```php
$depth = DB::table('jobs')->count();
```
This logic creates a hard dependency on the `database` queue driver. If the application switches to Redis or SQS in production (a common scaling step), this monitor will either fail (if the table is missing) or silently report 0 (if the table exists but is unused).

**Recommendation:**
Use the driver-agnostic `Queue` facade. While `Queue::size()` typically checks a specific queue, it is safer and works across drivers. If monitoring all queues is required, iterate through the known queue list.

```php
// Suggested change
$depth = Illuminate\Support\Facades\Queue::size(); // Defaults to 'default'
// Or for multiple queues:
// $depth = Queue::size('default') + Queue::size('notifications');
```

### 2. Inconsistent Exception Handling in Fire Incident Deactivation
**Severity:** MEDIUM
**File:** `app/Console/Commands/FetchFireIncidentsCommand.php`

The command correctly wraps the Scene Intel processing for *active* incidents in a try-catch block to prevent a single failure from halting the entire batch. However, the loop processing *deactivated* incidents lacks this protection:

```php
foreach ($deactivatedIncidents as $deactivatedIncident) {
    // ...
    // No try-catch here. If this throws, the command aborts.
    $sceneIntelProcessor->processIncidentUpdate($deactivatedIncident, $previousData);
}
```

If the `processIncidentUpdate` throws during deactivation, the command will exit with `FAILURE`. Since the database update (`is_active = false`) happens in bulk before this loop, the data state remains consistent, but subsequent deactivated incidents will not be processed for Scene Intel, leading to missing intelligence updates.

**Recommendation:**
Wrap the Scene Intel call in the deactivation loop with the same try-catch pattern used in the active incident loop.

### 3. Test Database Connection Hardcoded to 'sqlite'
**Severity:** LOW
**File:** `tests/Feature/Console/SchedulerResiliencePhase1Test.php`

The test `fire fetch command returns failure and logs when database is unavailable` manually overrides the configuration for `sqlite`:

```php
$originalSqliteDatabase = config('database.connections.sqlite.database');
config(['database.connections.sqlite.database' => '/__invalid__/db.sqlite']);
```

If the test environment is configured to use a different default connection (e.g., `mysql` or a specifically named `testing` connection), this test might not actually break the active database connection, leading to a false positive or irrelevant test.

**Recommendation:**
Dynamically determine the default connection name:

```php
$default = config('database.default');
$originalDb = config("database.connections.{$default}.database");
config(["database.connections.{$default}.database" => '/__invalid__/db']);
```

## Action Items
- [ ] Refactor `monitor:queue-depth` to use `Queue::size()`.
- [ ] Add try-catch block to `FetchFireIncidentsCommand` deactivation loop.
- [ ] Update test to use dynamic database connection name.
