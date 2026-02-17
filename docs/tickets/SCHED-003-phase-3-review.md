# SCHED-003: Scheduler Resilience Phase 3 Review

**Status:** Open
**Priority:** Medium
**Assignee:** Unassigned
**Labels:** refactor, architecture, scheduler

## Overview
Review of commit `ddc0c3d` (Phase 3: Data Integrity & Maintenance Guards). The implementation successfully adds circuit breakers, data sanity checks, and memory safeguards. However, the widespread use of the Service Locator pattern (`app()`) instead of Dependency Injection (DI) in the feed services presents maintainability and performance concerns.

## Findings

### 1. Service Locator Usage in Feed Services
**Severity:** Medium
**Location:** `app/Services/*FeedService.php`

All four feed services resolve `FeedCircuitBreaker` (and `FeedDataSanity`) using the global `app()` helper within their methods. This hides dependencies, makes unit testing harder (requires mocking the container), and violates standard dependency injection principles.

**Affected Files:**
- `app/Services/GoTransitFeedService.php` (L38)
- `app/Services/TorontoFireFeedService.php` (L33)
- `app/Services/TorontoPoliceFeedService.php` (L25)
- `app/Services/TtcAlertsFeedService.php` (L34, L84)

**Recommendation:**
Refactor all Feed Services to use **Constructor Injection**.

```php
// Example for GoTransitFeedService
public function __construct(
    protected FeedCircuitBreaker $circuitBreaker,
    protected FeedDataSanity $sanity,
) {}

// Usage
$this->circuitBreaker->throwIfOpen('go_transit');
```

### 2. Repeated Container Resolution in Loops
**Severity:** Medium
**Location:** `app/Services/TorontoPoliceFeedService.php` (L166)

The `parseFeature` method calls `app(FeedDataSanity::class)` to resolve the sanity checker. Since `parseFeature` is called inside a loop that can process up to 100,000 records (per the new max limit), this introduces repeated, unnecessary container resolution overhead for every single record.

**Recommendation:**
Inject `FeedDataSanity` via the constructor (as per Finding #1) and access it via `$this->sanity`.

## Action Items
- [ ] Refactor `TorontoFireFeedService` to use constructor injection for `FeedCircuitBreaker`.
- [ ] Refactor `GoTransitFeedService` to use constructor injection for `FeedCircuitBreaker` and `FeedDataSanity`.
- [ ] Refactor `TtcAlertsFeedService` to use constructor injection for `FeedCircuitBreaker` and `FeedDataSanity`.
- [ ] Refactor `TorontoPoliceFeedService` to use constructor injection for `FeedCircuitBreaker` and `FeedDataSanity`, removing the `app()` call inside the `parseFeature` loop.
