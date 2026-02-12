# Specification: In-App Notification System (Phase 2)

## 1. Overview
This track implements Phase 2 of the **In-App Notification System**, focusing on **Static Geofencing**, **TTC Accessibility Integration**, and **Automated Data Pruning**. These features enhance the notification engine's precision for location-based alerts, provide critical accessibility information for transit users, and ensure long-term database performance.

## 2. Functional Requirements

### 2.1 Static Geofencing
- **User Interface:**
    - Users can define "Saved Places" by searching for an address or intersection.
    - **Geocoding:** The system will utilize **Google Maps / Mapbox API** to convert this user-entered address into latitude/longitude coordinates.
    - Users specify a radius (e.g., 500m, 1km) around this point.
    - Users can name their saved place (e.g., "Home", "Work").
- **Backend Logic:**
    - Incoming alerts are checked against these user-defined geofences.
    - If an alert's location falls within a user's geofence, a notification is triggered (subject to other preferences).

### 2.2 TTC Accessibility Integration
- **Data Source:**
    - Consume the TTC Elevator/Escalator availability feed (likely JSON or XML from TTC Open Data).
- **Notification Payload:**
    - Must include:
        - **Station Name & Line:** Clearly identifying the affected station.
        - **Device Type & Location:** Specific details (e.g., "Elevator at Southbound Platform to Street Level").
        - **Estimated Return to Service:** Displayed if available in the source data.
- **Triggering:**
    - Notifications are sent when a device goes out of service or returns to service.
    - Users can subscribe to specific stations or lines for these alerts.

### 2.3 Automated Data Pruning
- **Retention Policy:**
    - **Strict Time-Based:** All notification logs (read or unread) are permanently deleted from the database if they are older than **30 days**.
- **Execution:**
    - A scheduled job (likely via Laravel Scheduler) runs daily to perform this cleanup.

### 2.4 Inbox Quality of Life
- **Mark All as Read:** A single action in the notification center to mark all unread notifications as read.
- **Clear All:** A single action to remove all visible notifications from the list (soft delete or hide).

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
