# REV-SCHEDULER-RESILIENCE-ANALYSIS

**Title:** Comprehensive Analysis of Scheduled Task Failure Modes and Recovery Mechanisms

**Type:** Technical Review / Risk Assessment

**Priority:** High

**Status:** Analysis Complete - Awaiting Implementation

---

## Summary

This ticket documents a comprehensive analysis of failure modes, edge cases, and recovery mechanisms for the GTA Alerts scheduled task system. The analysis reveals critical gaps in exception handling that can cause scheduled commands to stop executing for up to 24 hours when database or external API failures occur, despite the scheduler process continuing to run normally.

**Key Finding:** Commands using `withoutOverlapping()` have unprotected database operations that can leave mutex locks permanently acquired, causing complete stoppage of that data source until mutex expiration (24-hour default).

---

## Context

### Scheduled Tasks Overview

The GTA Alerts system relies on Laravel's scheduler to run data ingestion tasks at regular intervals:

**Active Schedule (routes/console.php:12-20):**
```php
Schedule::command('fire:fetch-incidents')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('police:fetch-calls')->everyTenMinutes();
Schedule::command('transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('go-transit:fetch-alerts')->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new GenerateDailyDigestJob)->dailyAt('00:10')->withoutOverlapping();
Schedule::command('notifications:prune')->daily()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [\App\Models\IncidentUpdate::class]])->daily()->withoutOverlapping();
```

**Execution Environment:**
- **Development:** `./vendor/bin/sail artisan schedule:work` (long-running process)
- **Production:** Docker scheduler container running `cron -f` with `* * * * * php artisan scheduler:run-and-log`

**Critical Observation:** Commands scheduled directly (not wrapped in jobs) have zero automatic retry logic at the scheduler level. They run once per interval, fail or succeed, and wait until the next scheduled execution.

---

## Current Resilience Mechanisms

### 1. HTTP-Level Retry Logic

All feed services implement retry mechanisms at the HTTP client level:

**TorontoFireFeedService.php:32-35**
```php
$response = Http::timeout(self::TIMEOUT_SECONDS)
    ->retry(2, 200, throw: false)
    ->withHeaders(['Accept' => 'application/xml, text/xml'])
    ->get(self::FEED_URL, [$cacheBuster => '']);
```

**TorontoPoliceFeedService.php:25-36**
```php
$response = Http::timeout(15)
    ->retry(2, 100, throw: false)
    ->acceptJson()
    ->get(self::API_URL, [...]);
```

**GoTransitFeedService.php:36-39**
```php
$response = Http::timeout(self::TIMEOUT_SECONDS)
    ->retry(2, 200, throw: false)
    ->acceptJson()
    ->get(self::FEED_URL);
```

**Summary:**
- Fire: 2 retries, 200ms delay, 15s timeout
- Police: 2 retries, 100ms delay, 15s timeout
- GO Transit: 2 retries, 200ms delay, 15s timeout

**Effectiveness:** Handles transient network errors (connection resets, brief DNS failures).

**Limitation:** After retry exhaustion, throws `RuntimeException` which propagates to command level.

### 2. Job-Level Retry Logic

Fetch jobs (when used) have exponential backoff configured:

**FetchFireIncidentsJob.php:13-15**
```php
public int $tries = 3;
public int $backoff = 30;
```

**FetchPoliceCallsJob.php:21-28**
```php
public $tries = 3;
public $backoff = 30;
```

**FetchGoTransitAlertsJob.php:15**
```php
public int $backoff = 30;
```

**Gap Identified:** Jobs exist but are NOT being used in the scheduler. Commands are scheduled directly:
```php
Schedule::command('fire:fetch-incidents')  // Direct command, not job
```

**Implication:** Job retry configuration is currently **inactive** in production scheduler flow.

### 3. Feed Service Data Validation

Each service validates API responses before processing:

**Fire Service (TorontoFireFeedService.php:46-72):**
- ✅ Empty response body check
- ✅ XML parsing validation
- ✅ Required timestamp field (`update_from_db_time`)
- ✅ Per-event required fields (`event_num`, `event_type`, `dispatch_time`)

**Police Service (TorontoPoliceFeedService.php:44-46):**
- ✅ `features` key existence check
- ⚠️ **Missing:** Empty features array validation (returns `[]` → marks all active calls inactive)
- ⚠️ **Missing:** Mid-pagination failure recovery

**GO Transit (GoTransitFeedService.php:50-58):**
- ✅ JSON structure validation
- ✅ `LastUpdated` field check
- ⚠️ **Missing:** Validation of nested alert structures (silently skips malformed alerts)

### 4. Command-Level Exception Handling

Commands have partial exception coverage:

**FetchFireIncidentsCommand.php:26-33** (Feed fetch phase):
```php
try {
    $data = $service->fetch();
    $feedUpdatedAt = Carbon::parse($data['updated_at'], 'America/Toronto')->utc();
} catch (\Throwable $e) {
    $this->error("Feed fetch failed: {$e->getMessage()}");
    return self::FAILURE;
}
```

**FetchFireIncidentsCommand.php:57-63** (Per-event timestamp parsing):
```php
try {
    $dispatchTime = Carbon::parse($event['dispatch_time'], 'America/Toronto')->utc();
} catch (\Throwable $e) {
    $this->error("Failed to parse dispatch_time for event {$event['event_num']}: {$e->getMessage()}");
    return self::FAILURE;  // ⚠️ Hard fail - stops processing entire batch
}
```

**FetchFireIncidentsCommand.php:86-90** (Scene Intel - soft fail):
```php
try {
    $sceneIntelProcessor->processIncidentUpdate($incident, $previousData);
} catch (\Throwable $e) {
    $this->error("Failed to generate scene intel for event {$incident->event_num}: {$e->getMessage()}");
    // Continues processing - soft fail
}
```

**Gap:** Scene Intel in deactivation loop (line 111) has NO try-catch protection.

### 5. Scheduler Health Monitoring

**Production Scheduler Container:** `docker/scheduler/`

**SchedulerRunAndLogCommand.php:23-59:**
- Wraps `schedule:run` execution
- Logs all output to Laravel logs
- Writes heartbeat to cache:
  - `scheduler:last_tick_at` (unix timestamp)
  - `scheduler:last_tick_exit_code`
  - `scheduler:last_tick_duration_ms`
- TTL: 24 hours

**SchedulerStatusCommand.php:16-51:**
- Validates heartbeat freshness
- Default threshold: 5 minutes
- Logs failures for post-mortem analysis
- Used by Docker `HEALTHCHECK`

**Docker Healthcheck (docker/scheduler/healthcheck.sh):**
```bash
php artisan scheduler:status --max-age="$SCHEDULER_MAX_AGE_MINUTES"
```

**Effectiveness:** Detects scheduler process crashes or hangs.

**Limitation:** Does NOT detect individual command failures or mutex deadlocks.

### 6. Queue Infrastructure

**Queue Configuration (config/queue.php:16, .env.example:54):**
```php
'default' => env('QUEUE_CONNECTION', 'database'),
```

**Failed Jobs Tracking:**
- Driver: `database-uuids` (config/queue.php:124)
- Table: `failed_jobs`
- **Gap:** No pruning policy (table grows indefinitely)

**Retry Configuration:**
- `DB_QUEUE_RETRY_AFTER`: 90 seconds (config/queue.php:43)

---

## Critical Issues Identified

### Issue 1: Unprotected Database Operations in Commands with `withoutOverlapping()`

**Severity:** 🔴 Critical

**Affected Commands:**
- `fire:fetch-incidents` (every 5 minutes)
- `transit:fetch-alerts` (every 5 minutes)
- `go-transit:fetch-alerts` (every 5 minutes)

**Root Cause:** Database operations outside try-catch blocks can throw `QueryException`, causing command crash before mutex release.

**Vulnerable Code Zones in FetchFireIncidentsCommand.php:**

**Zone 1 - Line 44-48:** Existing incidents query
```php
$existingIncidentsByEventNum = FireIncident::query()
    ->select(['id', 'event_num', 'alarm_level', 'units_dispatched', 'is_active'])
    ->whereIn('event_num', $incomingEventNums)
    ->get()  // ← QueryException can throw here
    ->keyBy('event_num');
```

**Zone 2 - Line 65-78:** Insert/Update operation
```php
$incident = FireIncident::updateOrCreate(  // ← QueryException can throw here
    ['event_num' => $event['event_num']],
    [
        'event_type' => $event['event_type'],
        // ... 8 more fields
    ]
);
```

**Zone 3 - Line 81-83:** Event broadcasting
```php
event(new AlertCreated(  // ← Broadcasting exception can throw here
    $notificationAlertFactory->fromFireIncident($incident),
));
```

**Zone 4 - Line 103-105:** Deactivation update
```php
FireIncident::query()
    ->whereIn('id', $deactivatedIncidents->pluck('id'))
    ->update(['is_active' => false]);  // ← QueryException can throw here
```

**Zone 5 - Line 111:** Scene Intel processing in deactivation loop
```php
$sceneIntelProcessor->processIncidentUpdate($deactivatedIncident, $previousData);
// ← No try-catch wrapper
```

**Failure Timeline:**

| **Time** | **Event** | **System State** |
|----------|-----------|------------------|
| T+0:00 | Fire command starts | Mutex acquired in cache |
| T+0:15 | Processing event 5 of 10 | Database connection lost |
| T+0:15.001 | `updateOrCreate()` throws `QueryException` | Command crashes, exits with uncaught exception |
| T+0:15.002 | | **Mutex remains locked** (not released) |
| T+5:00 | Scheduler attempts fire command | **SKIPPED** - mutex check fails (`withoutOverlapping()` |
| T+10:00 | Scheduler attempts fire command | **SKIPPED** - mutex still locked |
| T+15:00 | Scheduler attempts fire command | **SKIPPED** - mutex still locked |
| ... | | **Pattern continues** |
| T+24:00:00 | Mutex TTL expires | Fire command resumes normal execution |

**Impact:**
- Fire incident alerts **completely stopped** for up to 24 hours
- No error visible in scheduler monitoring (heartbeat still fresh from other commands)
- Silent failure - requires manual log inspection to detect

**Reproduction Steps:**
1. Simulate database disconnect during command execution
2. Observe mutex lock remains in cache: `php artisan cache:get illuminate:schedule:mutex:fire:fetch-incidents`
3. Confirm next scheduled run skips execution
4. Verify lock persists for 24 hours

### Issue 2: Direct Command Scheduling Bypasses Job Retry Logic

**Severity:** 🟡 High

**Current State:**
```php
// routes/console.php:12
Schedule::command('fire:fetch-incidents')->everyFiveMinutes()->withoutOverlapping();
```

**Available but Unused:**
```php
// app/Jobs/FetchFireIncidentsJob.php
public int $tries = 3;
public int $backoff = 30;
```

**Implication:** When a command fails (e.g., API timeout after retries), it waits 5 minutes for next scheduled run instead of retrying with exponential backoff.

**Example Failure Scenario:**

| **Time** | **Event** | **Scheduled Approach** | **Job Approach (Not Used)** |
|----------|-----------|------------------------|----------------------------|
| 10:00:00 | Fire API call starts | Command executes | Job dispatched |
| 10:00:14 | HTTP retry 1 fails | Retry 1 @ 10:00:14.2 | Retry 1 @ 10:00:14.2 |
| 10:00:14.5 | HTTP retry 2 fails | Retry 2 @ 10:00:14.7 | Retry 2 @ 10:00:14.7 |
| 10:00:15 | HTTP timeout final | **Command fails, waits** | Job attempt 1 fails |
| 10:00:45 | | **No action** | Job attempt 2 (30s backoff) |
| 10:01:15 | | **No action** | Job attempt 3 (30s backoff) |
| 10:01:45 | | **No action** | Job exhausted, moves to failed_jobs |
| 10:05:00 | | **Next scheduled attempt** | - |

**Data Gap:** 5 minutes vs 1 minute 45 seconds

### Issue 3: Police Calls Pagination Mid-Stream Failure

**Severity:** 🟡 High

**Code:** TorontoPoliceFeedService.php:24-55

```php
$allFeatures = [];
$resultOffset = 0;

do {
    $response = Http::timeout(15)->retry(2, 100, throw: false)->get(self::API_URL, [
        'resultOffset' => $resultOffset,
        'resultRecordCount' => $resultRecordCount,
    ]);

    if ($response->failed()) {
        throw new RuntimeException('Failed to fetch police calls: '.$response->status());
    }

    $data = $response->json();

    foreach ($data['features'] as $feature) {
        $allFeatures[] = $this->parseFeature($feature['attributes']);
    }

    $resultOffset += $resultRecordCount;
} while ($data['exceededTransferLimit'] ?? false);

return $allFeatures;
```

**Failure Scenario:**

**Setup:** API has 3,500 total records (4 pages @ 1,000 records each)

| **Page** | **Offset** | **Result** | **State** |
|----------|------------|------------|-----------|
| 1 | 0 | ✅ Success | 1,000 features in `$allFeatures` |
| 2 | 1,000 | ✅ Success | 2,000 features in `$allFeatures` |
| 3 | 2,000 | 🔴 HTTP 503 (after retries) | `RuntimeException` thrown |

**Result:**
- Exception propagates to command
- Command returns `FAILURE`
- **2,000 features are LOST** (never persisted)
- On next run: Fetches all 3,500 again (if API recovered)

**Gap:** No partial result persistence. All-or-nothing approach loses work on transient failures.

### Issue 4: Empty Police Features Array Deactivates All Calls

**Severity:** 🟡 High

**Code:** TorontoPoliceFeedService.php:44-46

```php
if (! isset($data['features'])) {
    throw new RuntimeException("Unexpected API response format: 'features' key missing.");
}
```

**Gap:** No validation for empty array case.

**Scenario:**

API returns valid JSON but zero features:
```json
{
  "features": [],
  "exceededTransferLimit": false
}
```

**Flow in FetchPoliceCallsCommand.php:48-77:**

```php
$calls = $service->fetch();  // Returns []
$this->info('Found '.count($calls).' calls in the feed. Updating database...');  // "Found 0 calls"

$objectIdsInFeed = [];  // Empty array

foreach ($calls as $callData) {  // Never executes
    $objectIdsInFeed[] = $callData['object_id'];
}

// Deactivate calls no longer in the feed
$deactivatedCount = PoliceCall::where('is_active', true)
    ->whereNotIn('object_id', $objectIdsInFeed)  // WHERE object_id NOT IN () → deactivates ALL
    ->update(['is_active' => false]);
```

**Impact:**
- All active police calls marked inactive
- Frontend shows zero police alerts
- On next successful fetch: Calls re-activated (marked as new alerts, triggering notifications)

**Root Cause:** Cannot distinguish between "API returned no results" vs "API is down" vs "legitimately no active calls".

### Issue 5: Hard Fail on Single Malformed Record Stops Batch Processing

**Severity:** 🟠 Medium

**Affected Commands:**
- `FetchFireIncidentsCommand` (line 57-63)
- `FetchGoTransitAlertsCommand` (line 39-44)

**Code Pattern:**

```php
foreach ($data['events'] as $event) {
    try {
        $dispatchTime = Carbon::parse($event['dispatch_time'], 'America/Toronto')->utc();
    } catch (\Throwable $e) {
        $this->error("Failed to parse dispatch_time for event {$event['event_num']}: {$e->getMessage()}");
        return self::FAILURE;  // ← Stops processing remaining events
    }

    // ... persist event
}
```

**Scenario:**

API returns 10 events:
1. Event 1: ✅ Processed successfully
2. Event 2: ✅ Processed successfully
3. Event 3: ✅ Processed successfully
4. Event 4: 🔴 `dispatch_time = "invalid-date"` → Command exits with `FAILURE`
5. Events 5-10: ⚠️ **NEVER PROCESSED**

**Impact:**
- Partial data loss on API data quality issues
- All-or-nothing processing reduces resilience

**Better Approach:** Log error, skip malformed record, continue processing.

### Issue 6: Missing Protection for `police:fetch-calls` Overlap

**Severity:** 🟠 Medium

**Code:** routes/console.php:13
```php
Schedule::command('police:fetch-calls')->everyTenMinutes();
// Missing: ->withoutOverlapping()
```

**Risk:** If police API is slow (>10 minutes), multiple instances run concurrently.

**Potential Issues:**
- Duplicate processing
- Race conditions in deactivation logic (two instances decide different sets of calls are "missing")
- Database lock contention

**Why Not Already Added:**
- Police pagination can fetch thousands of records (slower than Fire/GO)
- `withoutOverlapping()` without timeout could cause permanent stoppage if command hangs
- **Solution:** Add `->withoutOverlapping(600)` (10-minute timeout)

### Issue 7: Database Connection Loss Causes Cascading Failure

**Severity:** 🔴 Critical

**Scenario:** MySQL connection lost during command execution

**What Happens:**

```php
// Any of these operations throw QueryException:
FireIncident::query()->get();
FireIncident::updateOrCreate([...]);
FireIncident::query()->update([...]);
```

**Laravel's Behavior:**
- `QueryException` thrown
- NOT caught by command
- Command crashes
- `failed_jobs` table write attempted → **ALSO FAILS** (database still down)
- **No record of failure** (exception lost)

**Evidence:**
- `config/queue.php:124-127`: Failed jobs use database driver
- If database unreachable, both job execution AND failure logging fail

**Impact:**
- Silent data loss
- No visibility into what failed or when
- Operators unaware of issue until scheduler monitoring detects stale heartbeat (5-minute delay minimum)

### Issue 8: `DeliverAlertNotificationJob` Has No Retry Configuration

**Severity:** 🟠 Medium

**Code:** app/Jobs/DeliverAlertNotificationJob.php

```php
class DeliverAlertNotificationJob implements ShouldQueue
{
    use Queueable;

    // No $tries property
    // No $backoff property
    // No $timeout property

    public function handle(): void { ... }
}
```

**Implication:**
- Defaults to Laravel's global retry policy
- If broadcasting fails (line 74-81), job fails permanently
- Users miss in-app notifications

**Comparison:**

| **Job** | **Tries** | **Backoff** | **Purpose** |
|---------|-----------|-------------|-------------|
| FetchFireIncidentsJob | 3 | 30s | Data ingestion |
| FetchPoliceCallsJob | 3 | 30s | Data ingestion |
| FetchGoTransitAlertsJob | (implied 3) | 30s | Data ingestion |
| **DeliverAlertNotificationJob** | **1 (default)** | **0s (default)** | **User notifications** |

**Recommendation:** Add retry configuration:
```php
public int $tries = 5;
public int $backoff = 10;
```

---

## Edge Cases Not Considered

### Edge Case 1: Partial Data Corruption (Subtle Invalid Data)

**Scenario:** API returns 200 OK with subtly corrupt data:
- Latitude/longitude swapped
- Timestamps in wrong timezone (ET vs UTC mix)
- Negative alarm levels
- Event numbers with leading zeros (string vs int comparison issues)

**Current State:** No data integrity validation beyond field presence.

**Impact:**
- Frontend displays incorrect map locations
- Alerts sorted incorrectly by time
- Scene Intel trained on corrupt data

**Example:**

Fire API returns:
```xml
<event>
    <event_num>00123</event_num>
    <latitude>-79.3832</latitude>  <!-- Longitude value -->
    <longitude>43.6532</longitude>  <!-- Latitude value -->
    <alarm_level>-1</alarm_level>  <!-- Negative -->
</event>
```

All values parsed successfully, persisted to database, displayed incorrectly to users.

### Edge Case 2: Scheduler Container OOM Between Ticks

**Scenario:**
- Scheduler container running normally
- Memory leak in one of the scheduled commands
- Container OOM killed between `scheduler:run-and-log` executions

**Timeline:**

| **Time** | **Event** | **Monitoring** |
|----------|-----------|----------------|
| 10:00:00 | Scheduler tick completes | Heartbeat written: 10:00:00 |
| 10:00:30 | Container OOM killed | No detection |
| 10:01:00 | Expected scheduler tick | **SKIPPED** - container dead |
| 10:02:00 | Expected scheduler tick | **SKIPPED** - container dead |
| 10:05:00 | Healthcheck runs | ✅ Still passes! (heartbeat 5 min old, threshold is 5 min) |
| 10:06:00 | Healthcheck runs | 🔴 Fails (heartbeat 6 min old) |

**Gap:** 6-minute detection window for scheduler crashes.

**Impact:** Up to 6 minutes of missed data ingestion before alert.

### Edge Case 3: Queue Worker Dead but Scheduler Running

**Scenario:**
- Commands dispatch jobs (if refactored to use jobs)
- Queue worker crashes/stops
- Scheduler continues running, dispatching jobs into queue
- Jobs never processed

**Current Monitoring:** ❌ None

**Detection:** Manual inspection of `jobs` table for growing backlog.

**Impact:**
- `jobs` table grows unbounded
- Alerts delayed indefinitely
- Eventually database storage exhaustion

**Recommendation:** Add queue depth monitoring:
```php
Schedule::call(function () {
    $queueDepth = DB::table('jobs')->count();
    if ($queueDepth > 100) {
        Log::error('Queue backlog detected', ['depth' => $queueDepth]);
    }
})->everyMinute();
```

### Edge Case 4: Race Condition in Deactivation Logic

**Scenario:** Two scheduler ticks overlap despite `withoutOverlapping()` (mutex implementation failure, cache driver bug, clock skew)

**Fire Command Execution:**

**Tick 1 (T+0:00):**
- Fetches alerts: [A, B, C]
- Starts processing...

**Tick 2 (T+0:10, overlaps due to bug):**
- Fetches alerts: [B, C, D]
- Alert A not in list
- Marks A as inactive

**Tick 1 (T+0:20, completes):**
- Completes processing [A, B, C]
- Alert D not in list
- Marks D as inactive

**Result:**
- Alert A: Marked inactive (should be active)
- Alert D: Marked inactive (should be active)
- Frontend missing 2 active alerts

**Current Protection:**
- `withoutOverlapping()` mutex
- **Gap:** No mutex acquisition verification, no distributed lock guarantee

### Edge Case 5: Clock Skew Between Application and External API

**Scenario:**
- Fire API server clock is 15 minutes ahead
- API returns `dispatch_time = "2026-02-16 10:45:00"` (15 min in future from app's perspective)
- App's current time: `2026-02-16 10:30:00`

**Code:** FetchFireIncidentsCommand.php:58
```php
$dispatchTime = Carbon::parse($event['dispatch_time'], 'America/Toronto')->utc();
```

**Result:**
- ✅ Parses successfully (valid timestamp)
- ✅ Persisted to database
- Frontend displays alert with timestamp 15 minutes in future
- Sorting broken (future alerts appear first)
- Time-based filtering broken

**No Validation For:**
- Timestamp drift beyond reasonable bounds
- Timestamps in far past (1970-01-01)
- Timestamps in future

### Edge Case 6: Memory Exhaustion on Unbounded Police Pagination

**Code:** TorontoPoliceFeedService.php:20-55

```php
$allFeatures = [];
$resultOffset = 0;

do {
    // Fetch 1,000 records
    foreach ($data['features'] as $feature) {
        $allFeatures[] = $this->parseFeature($feature['attributes']);
    }
    $resultOffset += $resultRecordCount;
} while ($exceededTransferLimit);

return $allFeatures;  // All records in memory
```

**Scenario:** Police API returns 50,000 active calls (major incident, citywide emergency)

**Memory Calculation:**
- Average feature size: ~500 bytes (10 fields × ~50 bytes)
- 50,000 × 500 bytes = 25 MB raw data
- PHP overhead (arrays, objects): ~3x = **75 MB**

**Docker Memory Limit:** Typically 256-512 MB for PHP containers

**Risk:** OOM if API returns unexpectedly large dataset.

**Current Protection:** ❌ None (no pagination limit, no streaming)

### Edge Case 7: Failed Jobs Table Unbounded Growth

**Config:** config/queue.php:123-127

```php
'failed' => [
    'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
    'database' => env('DB_CONNECTION', 'sqlite'),
    'table' => 'failed_jobs',
],
```

**Maintenance Policy:** ❌ None (no pruning documented)

**Scenario:**
- External API has 7-day outage
- Every 5 minutes, fire/transit/GO commands fail
- Each failure creates `failed_jobs` entry: (24 hours × 12 per-hour × 3 commands) = **864 rows/day**
- 7-day outage: **6,048 failed job records**

**Impact:**
- Database storage growth
- `failed_jobs` table slow to query
- Log noise

**Comparison with Other Maintenance:**
- `notification_logs`: Pruned after 30 days (docs/backend/maintenance.md:9)
- `incident_updates`: Pruned after 90 days (docs/backend/maintenance.md:40)
- `failed_jobs`: **No pruning policy**

### Edge Case 8: Scene Intel LLM API Rate Limiting Cascade

**Code:** FetchFireIncidentsCommand.php:86-90

```php
try {
    $sceneIntelProcessor->processIncidentUpdate($incident, $previousData);
} catch (\Throwable $e) {
    $this->error("Failed to generate scene intel for event {$incident->event_num}: {$e->getMessage()}");
    // Continues processing
}
```

**Scenario:**
- Major incident (6-alarm fire)
- Fire API returns 30 active incidents
- Scene Intel LLM API has rate limit: 10 requests/minute
- Command processes all 30 incidents, calling Scene Intel for each

**Timeline:**

| **Event #** | **Result** | **State** |
|-------------|------------|-----------|
| 1-10 | ✅ Success | LLM responses generated |
| 11 | 🔴 Rate limit error | Logged, continues |
| 12-30 | 🔴 Rate limit error | Logged, continues |

**Impact:**
- 20 incidents missing Scene Intel data
- Error logs flooded (20 errors in single run)
- No alerting on Scene Intel failure rate
- No circuit breaker to temporarily disable Scene Intel

**Current Behavior:** Acceptable (soft failure, continues)

**Gap:** No monitoring/alerting when Scene Intel success rate drops below threshold.

### Edge Case 9: Broadcasting Failure in AlertCreated Event

**Code:** FetchFireIncidentsCommand.php:81-83

```php
event(new AlertCreated(
    $notificationAlertFactory->fromFireIncident($incident),
));
```

**Not Protected:** No try-catch wrapper.

**Scenario:** Redis connection (used for broadcasting) fails during event dispatch.

**Laravel Event Broadcasting Flow:**
1. `event()` called
2. Event dispatched to configured broadcaster (Redis, Pusher, etc.)
3. If broadcaster fails → `BroadcastException` thrown

**Result:**
- Exception propagates to command
- Command crashes (unhandled exception)
- If using `withoutOverlapping()` → mutex left locked
- Fire command stops running for up to 24 hours

**Impact Similar to Database Exception:** Silent stoppage of entire data source.

---

## Recommendations

### Priority 1: Critical (Prevents 24-Hour Outages)

#### R1.1: Add Top-Level Exception Handling to All Commands

**Wrap entire `handle()` method in try-catch to ensure mutex release:**

```php
public function handle(...): int
{
    try {
        // ... existing code ...
        return self::SUCCESS;
    } catch (\Throwable $e) {
        $this->error("Command failed: {$e->getMessage()}");
        Log::error("{$this->getName()} execution failed", [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'context' => [...],
        ]);
        return self::FAILURE;
    }
}
```

**Affected Files:**
- `app/Console/Commands/FetchFireIncidentsCommand.php`
- `app/Console/Commands/FetchPoliceCallsCommand.php`
- `app/Console/Commands/FetchGoTransitAlertsCommand.php`
- `app/Console/Commands/FetchTransitAlertsCommand.php`

**Benefit:**
- Guarantees mutex release
- Commands retry on next scheduled run
- All exceptions logged for debugging

#### R1.2: Add Queue Depth Monitoring

**Detect queue worker failures:**

```php
// routes/console.php
Schedule::call(function () {
    $queueDepth = DB::table('jobs')->count();
    $oldestJob = DB::table('jobs')->orderBy('created_at')->first();

    if ($queueDepth > 100) {
        Log::error('Queue backlog detected', [
            'depth' => $queueDepth,
            'oldest_job_age' => $oldestJob ? now()->diffInMinutes($oldestJob->created_at) : null,
        ]);
    }
})->everyFiveMinutes();
```

**Benefit:**
- Detects dead queue workers within 5 minutes
- Prevents unbounded job table growth

#### R1.3: Add `withoutOverlapping()` Protection to Police Command

**Fix overlap race condition:**

```php
// routes/console.php:13
Schedule::command('police:fetch-calls')
    ->everyTenMinutes()
    ->withoutOverlapping(600);  // 10-minute timeout matches interval
```

**Benefit:**
- Prevents duplicate processing
- Avoids race conditions in deactivation logic
- Timeout prevents permanent mutex lock

### Priority 2: High (Improves Resilience)

#### R2.1: Implement Graceful Degradation for Batch Processing

**Skip malformed records instead of hard failing:**

```php
foreach ($data['events'] as $event) {
    try {
        $dispatchTime = Carbon::parse($event['dispatch_time'], 'America/Toronto')->utc();
    } catch (\Throwable $e) {
        $this->warn("Skipping event {$event['event_num']} - invalid dispatch_time: {$e->getMessage()}");
        continue;  // Skip to next event instead of return FAILURE
    }

    // ... process event
}
```

**Benefit:**
- Partial data better than no data
- Resilient to API data quality issues

#### R2.2: Add Retry Configuration to DeliverAlertNotificationJob

```php
class DeliverAlertNotificationJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;
    public int $backoff = 10;
    public int $timeout = 30;

    // ... rest of implementation
}
```

**Benefit:**
- Handles transient broadcasting failures
- Ensures users receive notifications

#### R2.3: Add Empty Features Array Validation to Police Service

```php
// TorontoPoliceFeedService.php:44
if (! isset($data['features'])) {
    throw new RuntimeException("Unexpected API response format: 'features' key missing.");
}

// Add:
if (empty($data['features']) && $resultOffset === 0) {
    // First page is empty - API might be down or returning bad data
    throw new RuntimeException("Police API returned zero features on first page - possible API issue.");
}
```

**Benefit:**
- Prevents mass deactivation on API failures
- Distinguishes "no results" from "API problem"

#### R2.4: Switch to Job-Based Scheduling

**Use existing jobs with retry logic:**

```php
// routes/console.php
Schedule::job(new FetchFireIncidentsJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new FetchPoliceCallsJob)->everyTenMinutes()->withoutOverlapping();
Schedule::job(new FetchGoTransitAlertsJob)->everyFiveMinutes()->withoutOverlapping();
Schedule::job(new FetchTransitAlertsJob)->everyFiveMinutes()->withoutOverlapping();
```

**Benefit:**
- Automatic retry with exponential backoff (30s intervals)
- Reduces data gaps from 5 minutes to ~2 minutes
- Failed job tracking in `failed_jobs` table

#### R2.5: Add Failed Jobs Pruning Policy

```php
// routes/console.php
Schedule::command('queue:prune-failed', ['--hours' => 168])  // 7 days
    ->daily()
    ->withoutOverlapping();
```

**Add to docs/backend/maintenance.md:**

```markdown
## Failed Jobs Retention

### Policy

- `failed_jobs` entries older than 7 days are permanently deleted.

### Command

- Artisan command: `queue:prune-failed --hours=168`
- Scheduled in `routes/console.php`
- Frequency: daily
```

**Benefit:**
- Prevents unbounded table growth
- Maintains system performance

### Priority 3: Medium (Data Integrity & Monitoring)

#### R3.1: Add Data Integrity Validation

**Validate timestamp sanity:**

```php
$dispatchTime = Carbon::parse($event['dispatch_time'], 'America/Toronto')->utc();

// Add:
if ($dispatchTime->isFuture() && $dispatchTime->diffInMinutes(now()) > 15) {
    $this->warn("Event {$event['event_num']} has future timestamp (clock skew?): {$dispatchTime}");
}

if ($dispatchTime->lessThan(now()->subYears(1))) {
    $this->warn("Event {$event['event_num']} has very old timestamp: {$dispatchTime}");
}
```

**Validate coordinate ranges:**

```php
// For police calls and any geocoded data
if ($latitude < 43.0 || $latitude > 44.0 || $longitude < -80.0 || $longitude > -79.0) {
    $this->warn("Call {$objectId} has coordinates outside GTA bounds: ({$latitude}, {$longitude})");
}
```

**Benefit:**
- Detects data corruption early
- Logs warnings for investigation
- Prevents incorrect frontend display

#### R3.2: Add Memory Limits to Pagination

```php
// TorontoPoliceFeedService.php
private const MAX_FEATURES = 100000;  // Safety limit

do {
    // ... fetch page ...

    foreach ($data['features'] as $feature) {
        if (count($allFeatures) >= self::MAX_FEATURES) {
            Log::error('Police API pagination exceeded safety limit', [
                'limit' => self::MAX_FEATURES,
                'offset' => $resultOffset,
            ]);
            throw new RuntimeException("Police API returned more than safety limit");
        }
        $allFeatures[] = $this->parseFeature($feature['attributes']);
    }

} while ($exceededTransferLimit);
```

**Benefit:**
- Prevents OOM on unexpectedly large datasets
- Fails fast with clear error message

#### R3.3: Add Scene Intel Failure Rate Monitoring

```php
// Track Scene Intel success/failure per command run
$sceneIntelStats = ['success' => 0, 'failed' => 0];

foreach ($data['events'] as $event) {
    // ... process event ...

    try {
        $sceneIntelProcessor->processIncidentUpdate($incident, $previousData);
        $sceneIntelStats['success']++;
    } catch (\Throwable $e) {
        $this->error("Failed to generate scene intel for event {$incident->event_num}: {$e->getMessage()}");
        $sceneIntelStats['failed']++;
    }
}

// After loop:
$totalAttempts = $sceneIntelStats['success'] + $sceneIntelStats['failed'];
if ($totalAttempts > 0) {
    $failureRate = ($sceneIntelStats['failed'] / $totalAttempts) * 100;

    if ($failureRate > 50) {
        Log::warning('Scene Intel high failure rate', $sceneIntelStats + ['failure_rate' => $failureRate]);
    }
}
```

**Benefit:**
- Detects LLM API outages
- Enables proactive response to degraded service

#### R3.4: Add Circuit Breaker for Persistent Failures

**Temporarily disable failing sources:**

```php
// Add to feed services
private const CIRCUIT_BREAKER_KEY = 'circuit_breaker:fire_feed';
private const CIRCUIT_BREAKER_THRESHOLD = 5;
private const CIRCUIT_BREAKER_TTL = 300;  // 5 minutes

public function fetch(): array
{
    $failures = Cache::get(self::CIRCUIT_BREAKER_KEY, 0);

    if ($failures >= self::CIRCUIT_BREAKER_THRESHOLD) {
        throw new RuntimeException('Circuit breaker open - fire feed disabled due to repeated failures');
    }

    try {
        $response = Http::timeout(self::TIMEOUT_SECONDS)->retry(2, 200)->get(self::FEED_URL);

        if ($response->failed()) {
            Cache::increment(self::CIRCUIT_BREAKER_KEY, 1);
            Cache::expire(self::CIRCUIT_BREAKER_KEY, self::CIRCUIT_BREAKER_TTL);
            throw new RuntimeException('Toronto Fire feed request failed: '.$response->status());
        }

        // Success - reset counter
        Cache::forget(self::CIRCUIT_BREAKER_KEY);

        return $this->parseResponse($response);

    } catch (\Throwable $e) {
        Cache::increment(self::CIRCUIT_BREAKER_KEY, 1);
        Cache::expire(self::CIRCUIT_BREAKER_KEY, self::CIRCUIT_BREAKER_TTL);
        throw $e;
    }
}
```

**Benefit:**
- Reduces load on failing external APIs
- Auto-recovers after TTL expires
- Prevents cascading failures

### Priority 4: Low (Nice to Have)

#### R4.1: Add NTP/Clock Drift Monitoring

```php
Schedule::call(function () {
    try {
        $ntpTime = /* fetch from time.google.com or similar */;
        $localTime = now();
        $driftSeconds = abs($ntpTime->diffInSeconds($localTime));

        if ($driftSeconds > 300) {  // 5 minutes
            Log::error('System clock drift detected', [
                'drift_seconds' => $driftSeconds,
                'local_time' => $localTime,
                'ntp_time' => $ntpTime,
            ]);
        }
    } catch (\Throwable $e) {
        // Ignore NTP check failures
    }
})->daily();
```

**Benefit:**
- Detects clock skew affecting timestamp comparisons
- Prevents subtle bugs in time-based logic

#### R4.2: Add API Response Time Metrics

```php
$startTime = microtime(true);
$response = Http::timeout(15)->get(self::API_URL);
$duration = (microtime(true) - $startTime) * 1000;  // milliseconds

Log::info('Police API response time', [
    'duration_ms' => $duration,
    'status' => $response->status(),
]);

if ($duration > 10000) {  // 10 seconds
    Log::warning('Police API slow response', ['duration_ms' => $duration]);
}
```

**Benefit:**
- Detects API performance degradation
- Enables proactive scaling decisions

---

## Testing Strategy

### Unit Tests Required

**Test:** Command exception handling guarantees mutex release

```php
test('fire fetch command releases mutex on database exception', function () {
    // Arrange: Mock database to throw exception
    DB::shouldReceive('table')->andThrow(new QueryException(...));

    // Act: Run command with withoutOverlapping
    $exitCode = Artisan::call('fire:fetch-incidents');

    // Assert: Command failed
    expect($exitCode)->toBe(Command::FAILURE);

    // Assert: Mutex not locked
    $mutexKey = 'illuminate:schedule:mutex:fire:fetch-incidents';
    expect(Cache::has($mutexKey))->toBeFalse();
});
```

**Test:** Empty police features array does not mass-deactivate

```php
test('police fetch handles empty features array safely', function () {
    // Arrange: Create active police calls
    PoliceCall::factory()->count(10)->create(['is_active' => true]);

    // Mock service to return empty array
    $this->mock(TorontoPoliceFeedService::class)
        ->shouldReceive('fetch')
        ->andReturn([]);

    // Act: Run command
    $exitCode = Artisan::call('police:fetch-calls');

    // Assert: Command failed (detected empty response as error)
    expect($exitCode)->toBe(Command::FAILURE);

    // Assert: No calls deactivated
    expect(PoliceCall::where('is_active', true)->count())->toBe(10);
});
```

**Test:** Malformed record skipped, batch continues

```php
test('fire fetch skips malformed event and processes rest', function () {
    $mockData = [
        'updated_at' => '2026-02-16 10:00:00',
        'events' => [
            ['event_num' => 'E1', 'dispatch_time' => '2026-02-16 10:00:00', ...],
            ['event_num' => 'E2', 'dispatch_time' => 'INVALID', ...],
            ['event_num' => 'E3', 'dispatch_time' => '2026-02-16 10:05:00', ...],
        ],
    ];

    $this->mock(TorontoFireFeedService::class)
        ->shouldReceive('fetch')
        ->andReturn($mockData);

    Artisan::call('fire:fetch-incidents');

    // Assert: E1 and E3 saved, E2 skipped
    expect(FireIncident::where('event_num', 'E1')->exists())->toBeTrue();
    expect(FireIncident::where('event_num', 'E2')->exists())->toBeFalse();
    expect(FireIncident::where('event_num', 'E3')->exists())->toBeTrue();
});
```

### Integration Tests Required

**Test:** Job retry on transient HTTP failure

```php
test('fetch job retries on transient API failure', function () {
    Queue::fake();

    // First attempt fails
    Http::fake([
        'toronto.ca/data/fire/*' => Http::sequence()
            ->push('', 500)  // Attempt 1: Server error
            ->push('', 500)  // Attempt 2: Server error (after backoff)
            ->push('<xml>...</xml>', 200),  // Attempt 3: Success
    ]);

    $job = new FetchFireIncidentsJob;
    $job->handle();

    // Assert: Data persisted after retry
    expect(FireIncident::count())->toBeGreaterThan(0);
});
```

**Test:** Circuit breaker opens after threshold

```php
test('circuit breaker opens after repeated failures', function () {
    Http::fake(['toronto.ca/data/fire/*' => Http::response('', 500)]);

    $service = new TorontoFireFeedService;

    // Exhaust circuit breaker threshold (5 failures)
    for ($i = 0; $i < 5; $i++) {
        try {
            $service->fetch();
        } catch (\Throwable $e) {
            // Expected
        }
    }

    // Next call should fail immediately (circuit open)
    expect(fn () => $service->fetch())
        ->toThrow(RuntimeException::class, 'Circuit breaker open');
});
```

### Manual Tests Required

**Test:** Mutex timeout on command hang

```bash
# Terminal 1: Start command with artificially long processing
php artisan fire:fetch-incidents

# Terminal 2: Verify mutex exists
php artisan tinker
>>> Cache::has('illuminate:schedule:mutex:fire:fetch-incidents')
=> true

# Wait for withoutOverlapping timeout (if configured) or 24 hours

# Terminal 2: Verify mutex expired
>>> Cache::has('illuminate:schedule:mutex:fire:fetch-incidents')
=> false
```

**Test:** Queue worker failure detection

```bash
# Stop queue worker
php artisan queue:work &
kill -9 $!

# Dispatch jobs
php artisan fire:fetch-incidents

# Wait 5 minutes for queue monitoring

# Check logs for queue backlog alert
tail -f storage/logs/laravel.log | grep "Queue backlog detected"
```

---

## Monitoring & Alerting Requirements

### Metrics to Track

1. **Scheduler Health**
   - Last tick timestamp (already implemented via heartbeat)
   - Tick duration (already tracked)
   - Tick exit code (already tracked)

2. **Queue Health** (NEW)
   - Queue depth (`SELECT COUNT(*) FROM jobs`)
   - Oldest job age (`SELECT MIN(created_at) FROM jobs`)
   - Failed jobs count per day
   - Failed jobs by exception type

3. **Command Success Rates** (NEW)
   - Fire fetch: success count, failure count, last success timestamp
   - Police fetch: success count, failure count, last success timestamp
   - GO fetch: success count, failure count, last success timestamp
   - Transit fetch: success count, failure count, last success timestamp

4. **External API Performance** (NEW)
   - Response times (p50, p95, p99)
   - Error rates by status code
   - Timeout rates

5. **Data Quality** (NEW)
   - Records skipped due to validation failures (per source)
   - Scene Intel success/failure rates
   - Empty response detections

### Alerting Thresholds

| **Metric** | **Warning** | **Critical** |
|------------|-------------|--------------|
| Scheduler heartbeat age | 5 minutes | 10 minutes |
| Queue depth | 100 jobs | 500 jobs |
| Oldest job age | 5 minutes | 15 minutes |
| Command failure rate | 20% over 1 hour | 50% over 1 hour |
| API response time | p95 > 10s | p95 > 20s |
| Scene Intel failure rate | 30% | 60% |
| Validation skip rate | 5% of records | 15% of records |

---

## Implementation Checklist

### Phase 1: Critical Fixes (Prevents Outages)

- [ ] Add top-level exception handling to `FetchFireIncidentsCommand`
- [ ] Add top-level exception handling to `FetchPoliceCallsCommand`
- [ ] Add top-level exception handling to `FetchGoTransitAlertsCommand`
- [ ] Add top-level exception handling to `FetchTransitAlertsCommand`
- [ ] Add `withoutOverlapping(600)` to `police:fetch-calls` schedule
- [ ] Add queue depth monitoring scheduled task
- [ ] Test mutex release on exception (unit test)
- [ ] Test command behavior on database connection loss (integration test)

### Phase 2: Resilience Improvements

- [ ] Implement graceful degradation in fire command (skip malformed events)
- [ ] Implement graceful degradation in GO command (skip malformed events)
- [ ] Add retry configuration to `DeliverAlertNotificationJob`
- [ ] Add empty features array validation to police service
- [ ] Switch to job-based scheduling (all 4 fetch commands)
- [ ] Add failed jobs pruning policy
- [ ] Test batch processing resilience (unit test)
- [ ] Test job retry behavior (integration test)

### Phase 3: Data Integrity & Monitoring

- [ ] Add timestamp sanity validation to all commands
- [ ] Add coordinate bounds validation to police/fire commands
- [ ] Add memory limit protection to police pagination
- [ ] Add Scene Intel failure rate tracking
- [ ] Implement circuit breaker pattern in feed services
- [ ] Add API response time logging
- [ ] Test circuit breaker behavior (integration test)
- [ ] Document new monitoring metrics in ops runbook

### Phase 4: Documentation & Runbooks

- [ ] Update `docs/backend/production-scheduler.md` with new failure modes
- [ ] Create `docs/runbooks/scheduler-troubleshooting.md`
- [ ] Create `docs/runbooks/queue-troubleshooting.md`
- [ ] Update `docs/backend/maintenance.md` with failed jobs pruning
- [ ] Document monitoring thresholds and alerting setup

---

## References

### Related Documentation
- `docs/backend/production-scheduler.md` - Scheduler container architecture
- `docs/backend/maintenance.md` - Existing maintenance policies
- `CLAUDE.md` - Project conventions and architecture

### Code References
- `routes/console.php:12-20` - Schedule definitions
- `app/Console/Commands/Fetch*.php` - Fetch command implementations
- `app/Services/*FeedService.php` - External API integration
- `app/Jobs/Fetch*.php` - Job wrappers (currently unused in schedule)
- `config/queue.php` - Queue and failed jobs configuration

### External References
- Laravel Scheduler: https://laravel.com/docs/11.x/scheduling
- Laravel Queues: https://laravel.com/docs/11.x/queues
- Circuit Breaker Pattern: https://martinfowler.com/bliki/CircuitBreaker.html
