# JIRA Ticket: GTA-NOTIF-BEND-REVIEW

> Historical review record.
> Current implementation uses `saved_places` + `subscriptions`; references to `geofences` here describe the legacy request payload compatibility contract active at the time of review.

**Summary:** Notification Preferences Backend Code Review Findings
**Status:** RESOLVED
**Priority:** HIGH
**Component:** Backend - Notifications
**Reporter:** Code Review Architect
**Assignee:** Joshua Jadulco

---

## Description
A thorough review of the `9af1fba` commit (Notification Preferences Backend) has identified several issues ranging from low-priority idiomatic improvements to high-priority validation gaps.

## Findings

### [HIGH] Geofence coordinates should be required if an entry is provided
**Location:** `app/Models/NotificationPreference.php:76`

The current validation uses `nullable` for `lat`, `lng`, and `radius_km`. This allows a geofence object to be saved without coordinates, which will cause failures in the matching engine later. If a geofence is defined, it must be complete to be functional.

**Suggested Remediation:**
```php
            'geofences.*' => ['array:name,lat,lng,radius_km'],
            'geofences.*.name' => ['nullable', 'string', 'max:120'],
-           'geofences.*.lat' => ['nullable', 'numeric', 'between:-90,90'],
-           'geofences.*.lng' => ['nullable', 'numeric', 'between:-180,180'],
-           'geofences.*.radius_km' => ['nullable', 'numeric', 'gt:0', 'max:100'],
+           'geofences.*.lat' => ['required', 'numeric', 'between:-90,90'],
+           'geofences.*.lng' => ['required', 'numeric', 'between:-180,180'],
+           'geofences.*.radius_km' => ['required', 'numeric', 'gt:0', 'max:100'],
```

**Resolution:** Implemented. `lat`, `lng`, and `radius_km` are now required for each geofence entry.

---

### [LOW] Use the `id` property directly for better readability
**Location:** `app/Http/Controllers/Settings/NotificationPreferenceController.php:15`

While `getAuthIdentifier()` is technically correct, `$request->user()->id` is more idiomatic in Laravel when the primary key is known to be an integer.

**Suggested Remediation:**
```php
-           userId: (int) $request->user()->getAuthIdentifier(),
+           userId: $request->user()->id,
```

**Resolution:** Implemented. Controller now uses `$request->user()->id`.

---

### [LOW] Redundant cast and method call
**Location:** `app/Http/Controllers/Settings/NotificationPreferenceController.php:25`

Same as above; use `$request->user()->id` for consistency and clarity.

**Suggested Remediation:**
```php
-           userId: (int) $request->user()->getAuthIdentifier(),
+           userId: $request->user()->id,
```

**Resolution:** Implemented. Controller now uses `$request->user()->id`.

---

### [LOW] Redundant index on `user_id`
**Location:** `database/migrations/2026_02_10_000002_create_notification_logs_table.php:23`

The compound index on line 26 (`['user_id', 'status', 'sent_at']`) already covers the `user_id` prefix, making this individual index unnecessary and slightly impacting write performance.

**Suggested Remediation:**
```php
-           $table->index('user_id');
            $table->index('status');
```

**Resolution:** Implemented. Removed standalone `user_id` index and retained the compound index.
