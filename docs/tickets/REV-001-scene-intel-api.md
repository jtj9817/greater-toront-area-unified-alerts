# [REV-001] Code Review: Scene Intel API Implementation

**Date:** 2026-02-13
**Reviewer:** Gemini CLI
**Target Commit:** `ec54286`
**Status:** Open
**Priority:** Medium
**Components:** Backend, API

## Overview
A review of the Scene Intel API implementation (commit `ec54286`) identified opportunities to align with project patterns (Resources) and improve query performance (Existence Checks).

## Findings

### 1. Manual Serialization in Controller
**Severity:** Medium
**Location:** `app/Http/Controllers/SceneIntelController.php`

The controller currently handles JSON serialization via a private `serializeUpdate` method. This diverges from the application's established pattern of using `JsonResource` classes (e.g., `FireIncidentResource`), reducing reusability and consistency.

**Recommendation:**
Create an `IncidentUpdateResource` and utilize it in the controller.

```php
// app/Http/Controllers/SceneIntelController.php

public function timeline(string $eventNum): JsonResponse
{
    $this->assertIncidentExists($eventNum);
    
    $timeline = $this->repository->getTimeline($eventNum);
    
    return response()->json([
        'data' => IncidentUpdateResource::collection($timeline),
        'meta' => [
            'event_num' => $eventNum,
            'count' => $timeline->count(),
        ],
    ]);
}
```

### 2. Inefficient Existence Check
**Severity:** Low
**Location:** `app/Http/Controllers/SceneIntelController.php`

The `assertIncidentExists` method uses `firstOrFail()`, which hydrates the entire `FireIncident` model only to discard it. This adds unnecessary overhead compared to a simple existence check.

**Recommendation:**
Use `exists()` to perform a lighter query.

```php
private function assertIncidentExists(string $eventNum): void
{
    if (! FireIncident::where('event_num', $eventNum)->exists()) {
        abort(404);
    }
}
```
