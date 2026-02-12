# Specification: In-App Notification System (Phase 2)

## 1. Overview
This track implements Phase 2 of the **In-App Notification System**, focusing on **Static Geofencing** (using Toronto Open Data), **TTC Accessibility Integration**, **Granular Subscriptions**, and **Automated Data Pruning**. These features enhance the notification engine's precision for location-based alerts, provide critical accessibility information for transit users, and ensure long-term database performance.

## 2. Functional Requirements

### 2.1 Static Geofencing & Address Search
- **Data Source:**
    - **Toronto Open Data:** Utilization of "Address Points (Municipal)" and "Places of Interest" datasets.
    - Data is ingested into local tables (`toronto_addresses`, `toronto_pois`) for offline, zero-cost geocoding.
- **Database Architecture:**
    - **Removed:** The unused `geofences` JSON column in `notification_preferences` is dropped.
    - **Added:** A `SavedPlace` model (User hasMany SavedPlaces) stores location data.
- **User Interface:**
    - Users can search for an address or POI (e.g., "CN Tower", "100 Queen St W") via an autocomplete input.
    - Selected locations are saved as `SavedPlace` records with a user-defined radius (default: 500m).
- **Backend Logic:**
    - `NotificationMatcher` checks incoming alerts against `SavedPlace` coordinates using the Haversine formula.

### 2.2 TTC Accessibility Integration
- **Data Source:**
    - Existing `TtcAlertsFeedService` refactored to consume the `accessibility` bucket from `https://alerts.ttc.ca/api/alerts/live-alerts`.
- **Notification Payload:**
    - **Type:** `AccessibilityAlert` (or `Alert` with type `accessibility`).
    - **Content:** Station Name, Device (Elevator/Escalator), Current Status, Estimated Return to Service.
- **Triggering:**
    - Notifications are generated when a device status changes to "Out of Service".

### 2.3 Subscription Granularity
- **Database Architecture:**
    - **Renamed:** `subscribed_routes` column becomes `subscriptions` to reflect diverse entity types.
    - **Data Source:** Static configuration file (`config/transit_data.php`) containing canonical lists of TTC Stations, Lines, and Routes to avoid database overhead for static data.
- **Abstraction (URN Schema):**
    - Subscriptions are stored as a JSON array of URN strings.
    - **Schema Definition:**
        | Entity | Format | Example |
        | :--- | :--- | :--- |
        | **Route** | `route:{id}` | `route:504`, `route:300` |
        | **Line** | `line:{id}` | `line:1`, `line:2` |
        | **Station** | `station:{slug}` | `station:union`, `station:bloor-yonge` |
        | **Agency** | `agency:{slug}` | `agency:ttc` |
- **Backend Logic:**
    - **AlertContentExtractor:**
        - Parses incoming alert `title` and `description`.
        - **Regex:** Identifies route numbers (e.g., `/\b(50[1-8])\b/` -> `route:501`).
        - **Keyword Matching:** Scans text against the Station names defined in `transit_data.php` -> `station:{slug}`.
    - **Matching:** `NotificationMatcher` intersects the user's `subscriptions` array with the alert's extracted URNs.
- **User Interface:**
    - **"My Subscriptions" Component:**
        - **Tabs:** [Routes] [Stations] [Lines]
        - **Interaction:** Searchable multi-select dropdowns populated by the `/api/subscriptions/options` endpoint.
        - **Display:** Selected subscriptions appear as removable chips.

### 2.4 Automated Data Pruning
- **Retention Policy:**
    - **Strict Time-Based:** All notification logs (read or unread) are permanently deleted from the database if they are older than **30 days**.
- **Execution:**
    - A scheduled job (likely via Laravel Scheduler) runs daily to perform this cleanup.

### 2.5 Inbox Quality of Life
- **Mark All as Read:** A single action in the notification center to mark all unread notifications as read.
- **Clear All:** A single action to remove all visible notifications from the list (soft delete or hide).

## 3. Non-Functional Requirements

## 3. Non-Functional Requirements
- **Performance:** Geofence matching should be efficient (e.g., using spatial indexing if available, or optimized distance calculations).
- **Reliability:** The TTC feed integration must handle API failures or malformed data gracefully without crashing the notification engine.
- **Data Integrity:** Pruning jobs must ensure they do not accidentally delete recent or pinned (if applicable in future) notifications.

## 4. Out of Scope
- Map-based pin dropping for geofencing (Phase 3 or later).
- SMS or Email notifications.
- Alternative accessible route generation (beyond what is in the source text).
- Complex capacity-based retention limits.

## 5. Acceptance Criteria
- [ ] Users can search for an address (powered by Google Maps/Mapbox API) to define a geofence.
- [ ] Accessibility alerts (Elevator/Escalator) are generated with correct Station, Device, and Time details.
- [ ] Database notification logs older than 30 days are automatically removed by a scheduled task.
- [ ] Users can "Mark All as Read" and "Clear All" in their notification center.
