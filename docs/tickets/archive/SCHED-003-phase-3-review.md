# SCHED-003: Scheduler Resilience Phase 3 Review

**Status:** Closed
**Priority:** Medium
**Assignee:** Unassigned
**Labels:** refactor, architecture, scheduler

## Resolution
**Completed via Commit:** `c6ec601`

All Phase 3 feed services now use constructor injection for their dependencies, and the `TorontoPoliceFeedService::parseFeature()` hot-path no longer resolves dependencies from the container per-record.

## Overview
Review of commit `ddc0c3d` (Phase 3: Data Integrity & Maintenance Guards). That implementation added circuit breakers, data sanity checks, and memory safeguards, but it also introduced widespread Service Locator usage (`app()`) inside feed services. This ticket captures the refactor to explicit constructor injection completed in `c6ec601`.

## Findings

### 1. Service Locator Usage in Feed Services
**Severity:** Medium
**Location:** `app/Services/*FeedService.php`

Feed services were resolving dependencies using the global `app()` helper within their methods. This hid dependencies, made unit testing harder (requires mocking the container), and violated standard dependency injection principles.

**Fixed In:**
- `app/Services/GoTransitFeedService.php` (inject `FeedCircuitBreaker` at L15)
- `app/Services/TorontoFireFeedService.php` (inject `FeedCircuitBreaker` at L16)
- `app/Services/TorontoPoliceFeedService.php` (inject `FeedCircuitBreaker` + `FeedDataSanity` at L17)
- `app/Services/TtcAlertsFeedService.php` (inject `FeedCircuitBreaker` + `FeedDataSanity` at L28)

**Recommendation:**
Refactor all Feed Services to use **Constructor Injection**.

```php
// Example for a service that needs both dependencies (e.g., Police/TTC)
public function __construct(
    protected FeedCircuitBreaker $circuitBreaker,
    protected FeedDataSanity $sanity,
) {}

// Usage
$this->circuitBreaker->throwIfOpen('ttc_alerts');
```

### 2. Repeated Container Resolution in Loops
**Severity:** Medium
**Location:** `app/Services/TorontoPoliceFeedService.php` (L168)

The `parseFeature` method was calling `app(FeedDataSanity::class)` to resolve the sanity checker. Since `parseFeature` is called inside a loop that can process up to 100,000 records (per the new max limit), this introduced repeated, unnecessary container resolution overhead for every single record.

**Recommendation:**
Inject `FeedDataSanity` via the constructor (as per Finding #1) and access it via `$this->sanity`.

## Action Items
- [x] Refactor `TorontoFireFeedService` to use constructor injection for `FeedCircuitBreaker`.
- [x] Refactor `GoTransitFeedService` to use constructor injection for `FeedCircuitBreaker`.
- [x] Refactor `TtcAlertsFeedService` to use constructor injection for `FeedCircuitBreaker` and `FeedDataSanity`.
- [x] Refactor `TorontoPoliceFeedService` to use constructor injection for `FeedCircuitBreaker` and `FeedDataSanity`, removing the `app()` call inside the `parseFeature` loop.
