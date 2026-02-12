# Ticket: REV-20260212-02 - Phase 2 Notification Logic & Regex Fixes

**Status:** Open  
**Priority:** High  
**Assignee:** Unassigned  
**Created:** 2026-02-12  
**Components:** Backend, Notification Engine, TTC Feed Service  
**Related Commit:** `e06234e`

## Summary

A code review of the Phase 2 implementation (Subscription URNs & Accessibility) identified critical gaps in the alert extraction logic and status normalization. These issues will result in missed notifications for standard bus routes, false negatives for "Not in Service" elevator alerts, and a lack of "Restored" notifications for accessibility users.

## 1. [HIGH] "Not in Service" Incorrectly Parsed as "In Service"

**Location:** `app/Services/TtcAlertsFeedService.php` (L526)

### Issue Description
The fallback status extraction logic is flawed. It checks for "out of service" first, and then checks for "in service". The problem is that the phrase "not in service" does *not* contain the substring "out of service", but it *does* contain "in service".

As a result, an alert titled "Elevator not in service" will fail the first check and pass the second check, incorrectly marking the elevator as **IN_SERVICE**.

### Technical Detail
```php
// Current Logic
if (str_contains($fallback, 'out of service')) {
    return 'OUT_OF_SERVICE';
}

// "not in service" matches this!
if (str_contains($fallback, 'in service')) {
    return 'IN_SERVICE';
}
```

### Remediation
Update the logic to explicitly check for negative phrases before positive ones.

```php
if (str_contains($fallback, 'out of service') || str_contains($fallback, 'not in service')) {
    return 'OUT_OF_SERVICE';
}

if (str_contains($fallback, 'in service') || str_contains($fallback, 'restored')) {
    return 'IN_SERVICE';
}
```

---

## 2. [HIGH] Regex Misses Standard Bus Routes

**Location:** `app/Services/Notifications/AlertContentExtractor.php` (L69)

### Issue Description
The current regex `/\b(50[1-8]|3\d{2})\b/` is too restrictive. It was designed to capture Streetcars (501-508) and Night Buses (300-399). It completely fails to capture standard bus routes (e.g., "29 Dufferin", "35 Jane", "100 Flemingdon Park"), which are the majority of TTC routes.

If the alert metadata is empty (which happens with some manual alerts), the system relies entirely on this text extraction. Users subscribed to `route:29` will never receive notifications if the route is only mentioned in the body text.

### Remediation
Expand the regex to include standard 1-3 digit route numbers, ideally validating against the known routes in `config/transit_data.php` to avoid false positives (like "10 minutes delay").

**Proposed Regex:** `/\b(50[1-8]|3\d{2}|[1-9]\d{0,2})\b/`

*Note: Ensure this doesn't aggressively match time durations (e.g., "15 min"). Contextual look-around or checking against the `config('transit_data.routes')` whitelist is recommended.*

---

## 3. [MEDIUM] Accessibility "Restored" Notifications Suppressed

**Location:** `app/Console/Commands/FetchTransitAlertsCommand.php` (L87)

### Issue Description
The `shouldDispatchNotification` method currently returns `false` if the `transitAlert->effect` is not "OUT_OF_SERVICE". This logic assumes users only want to know when things break.

However, for accessibility users, knowing when an elevator is **fixed** (Restored) is just as critical as knowing when it breaks. The current logic actively suppresses these "Back in Service" updates.

### Remediation
The logic should allow dispatching if there is a **status transition** (e.g., from `OUT_OF_SERVICE` to `IN_SERVICE`).

```php
if ($transitAlert->source_feed === 'ttc_accessibility') {
    // Dispatch if status changed, even if new status is IN_SERVICE
    if ($previousEffect !== null && $previousEffect !== $transitAlert->effect) {
        return true;
    }

    if (! $this->isOutOfServiceEffect($transitAlert->effect)) {
        return false;
    }
    // ...
}
```

---

## 4. [MEDIUM] Keyword Matching Performance Bottleneck

**Location:** `app/Services/Notifications/AlertContentExtractor.php` (L107)

### Issue Description
The `extractStations` method iterates through every configured station (75+) and, for each station, iterates through every alias. Inside that loop, it runs `preg_match` on the full alert text.

This results in `O(N_Alerts * N_Stations * N_Aliases)` complexity. While manageable for now, this will burn CPU cycles unnecessarily as the number of alerts scales or if we add more POIs.

### Remediation
**Option A (Quick):** Combine all station keywords into a single compiled regex pattern per station (e.g., `/\b(Union|Union Station)\b/i`) instead of looping `containsAnyKeyword`.

**Option B (Robust):** Use a single pass tokenization or an Aho-Corasick style approach if the station list grows significantly. For now, Option A is sufficient.

```php
// Optimization Idea
$pattern = '/\b' . implode('|', array_map('preg_quote', $keywords)) . '\b/i';
if (preg_match($pattern, $text)) {
    // Match found
}
```

## Acceptance Criteria
- [ ] Unit tests added for "not in service" string parsing.
- [ ] Unit tests added for standard bus route extraction (e.g., "Route 29").
- [ ] Unit tests added verifying `AlertCreated` event is fired when an elevator returns to service.
- [ ] Performance benchmark/check for the station extractor (optional but recommended).
