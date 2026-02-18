# [SCENE-INTEL-01] Enhance Resilience and Performance of Scene Intel Sync

**Type:** Bug / Refactor
**Priority:** High
**Status:** Closed
**Component:** Backend (Console Command)
**Verified on codebase (2026-02-18):** Scene intel processing is wrapped in per-incident try/catch and existing incident prefetch uses a targeted column select.

## Description
A code review of the synthetic intel generation integration (commit `af772bd`) identified a resilience flaw in the command loop and a performance optimization opportunity for state pre-fetching.

## Issues Identified

### 1. Lack of Error Handling for Intel Processing
**Severity:** High
**Location:** `app/Console/Commands/FetchFireIncidentsCommand.php`

The call to `$sceneIntelProcessor->processIncidentUpdate(...)` is placed **outside** the `try/catch` block within the incident processing loop.

**Risk:** If `processIncidentUpdate` throws an exception (e.g., database constraint violation, connection error), the entire command will terminate immediately. This prevents subsequent incidents in the feed from being processed.

**Recommendation:** Move the processing call inside the `try/catch` block to ensure that a failure in intel generation for one incident does not halt the entire sync process.

### 2. Inefficient Full Table Select
**Severity:** Low
**Location:** `app/Console/Commands/FetchFireIncidentsCommand.php`

The command fetches all columns (`select *`) when building the `$existingIncidentsByEventNum` map.

```php
$existingIncidentsByEventNum = FireIncident::query()
    ->whereIn('event_num', $incomingEventNums)
    ->get()
    ->keyBy('event_num');
```

**Risk:** As the `fire_incidents` table grows or if it contains large text columns (e.g., `raw_xml` or description fields), fetching all columns into memory for every sync cycle is inefficient. Only `alarm_level`, `units_dispatched`, and `is_active` are required for the diffing logic.

**Recommendation:** Explicitly select only the necessary columns.
```php
->select(['id', 'event_num', 'alarm_level', 'units_dispatched', 'is_active'])
```

## Action Plan
- [ ] Move `processIncidentUpdate` inside the `try` block in `FetchFireIncidentsCommand`.
- [ ] Optimize the `existingIncidentsByEventNum` query to select specific columns.
