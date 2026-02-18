# [REV-001] Code Review: Phase 1 Geofencing Implementation

> Historical review record.
> This document references `geofences` as the legacy settings payload shape. Persisted geofence data is implemented via `saved_places` in the current schema.

**Status:** Closed
**Priority:** High
**Assignee:** Joshua Jadulco
**Reporter:** Code Review Architect
**Related Commit:** `0cbe777`
**Verified on codebase (2026-02-18):** Action items are implemented in `ImportTorontoGeospatialDataCommand`, `LocalGeocodingService`, and `NotificationPreferenceController`.

## Overview
A code review was conducted on the Phase 1 Geofencing implementation (Commit `0cbe777`). The changes introduce `SavedPlace` models, geospatial data import commands, and updates to the `NotificationMatcher`. While the functional logic is sound and the feature set matches the requirements, significant performance and scalability issues were identified that need to be addressed before production deployment.

## Critical Findings

### 1. Memory Exhaustion Risk in Data Import
**File:** `app/Console/Commands/ImportTorontoGeospatialDataCommand.php`
**Severity:** High
**Location:** Line 200

**Description:**
The `rowsFromJson` method loads the entire JSON file content into memory using `file_get_contents` before decoding it with `json_decode`. Toronto Open Data GeoJSON files can be large (tens or hundreds of MBs). This approach will likely cause an `Allowed memory size exhausted` fatal error on standard server configurations (e.g., a 200MB GeoJSON file will require significantly more RAM to decode into a PHP array).

**Technical Detail:**
```php
// Current implementation
$decoded = json_decode((string) file_get_contents($path), true);
```

**Recommendation:**
- **Immediate Fix:** Add a file size check to reject files larger than a safe threshold (e.g., 50MB) and instruct the user to use CSV.
- **Robust Fix:** Implement a streaming JSON parser (like `halaxa/json-machine` or `salsify/json-streaming-parser`) to read the file in chunks, similar to how `fgetcsv` is used for the CSV import.

### 2. Database Index Invalidation in Geocoding Search
**File:** `app/Services/Geocoding/LocalGeocodingService.php`
**Severity:** High
**Location:** Lines 81, 105

**Description:**
The search queries for addresses and POIs wrap database columns in `LOWER()` functions and use leading wildcards (`%term%`). This prevents the database engine (MySQL/PostgreSQL/SQLite) from utilizing standard B-Tree indexes, forcing a full table scan for every search request. As the dataset grows (Toronto has ~500k+ addresses), this endpoint will become unresponsive and increase CPU load on the database.

**Technical Detail:**
```php
// Current implementation prevents index usage
->whereRaw('LOWER(street_name) LIKE ?', ["%{$token}%"])
```

**Recommendation:**
- **Remove LOWER():** Most standard database collations (e.g., `utf8mb4_unicode_ci` in MySQL/MariaDB) are case-insensitive by default. `WHERE street_name LIKE '...'` is sufficient and index-friendly.
- **Review Wildcards:** Re-evaluate the necessity of leading wildcards.
    - If "starts with" logic is acceptable (`$term%`), B-Tree indexes can be used.
    - If "contains" logic (`%term%`) is required, switching to a Full Text Search solution (Laravel Scout, MySQL `FULLTEXT`, or Postgres `TSVECTOR`) is strongly recommended over `LIKE`.

### 3. N+1 Insert Performance in Preference Sync
**File:** `app/Http/Controllers/Settings/NotificationPreferenceController.php`
**Severity:** Medium
**Location:** Line 82

**Description:**
The `syncLegacyGeofences` method iterates through the provided geofences array and executes a separate `INSERT` query (`SavedPlace::create`) for each item inside the loop. While the number of geofences per user is likely small, this pattern is inefficient, adds unnecessary database round-trips, and sets a bad precedent for data-heavy operations.

**Technical Detail:**
```php
foreach ($geofences as $geofence) {
    SavedPlace::query()->create([ ... ]); // Triggers 1 INSERT per loop
}
```

**Recommendation:**
- Refactor to use a single bulk insert query using `SavedPlace::insert($data)`.
- **Note:** When using `insert()`, Eloquent timestamps (`created_at`, `updated_at`) are not automatically managed, so they must be populated manually in the array preparation.

## Action Items
- [x] Refactor `ImportTorontoGeospatialDataCommand` to handle large JSON files safely or enforce size limits.
- [x] Optimize `LocalGeocodingService` queries to remove `LOWER()` and consider FTS for performance.
- [x] Refactor `NotificationPreferenceController::syncLegacyGeofences` to use bulk inserts.
