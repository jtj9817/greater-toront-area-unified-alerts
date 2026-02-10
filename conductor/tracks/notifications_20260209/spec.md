# Track Specification: In-App Notification System (Phase 1)

**Overview**
This track implements the foundational infrastructure for real-time, in-app notifications. Users will be able to subscribe to specific transit routes and emergency agencies, filter these alerts by severity, and view a persistent history of notifications received.

---

**Functional Requirements**
1.  **User Subscriptions:**
    *   Users can subscribe to specific transit routes (TTC, GO Transit).
    *   Users can subscribe to emergency alert sources (Toronto Fire, Toronto Police).
2.  **Severity Filtering:**
    *   Users can explicitly toggle which severity levels (e.g., Critical, Major, Minor) trigger a notification for each subscription.
3.  **Real-Time Delivery:**
    *   Notifications must be delivered via in-app "Toasts" or "Banners" using WebSockets (Laravel Echo) while the user is active in the application.
4.  **Notification Center (Inbox):**
    *   A persistent UI component (Inbox) that lists all notifications received by the user.
    *   Ability to mark individual notifications as "Read" or "Dismissed".
    *   Database persistence for notification history (`notification_logs`).
5.  **Preference Management:**
    *   A settings interface for users to manage their active subscriptions and severity filters.

---

**Non-Functional Requirements**
*   **Latency:** Real-time notifications should appear within < 5 seconds of the alert being synchronized to the database.
*   **Scalability:** The notification processing engine must handle concurrent delivery to multiple active users.
*   **Security:** Users can only view and manage their own notification history and preferences.

---

**Acceptance Criteria**
*   [ ] A user can subscribe to "TTC Line 1" and "Toronto Fire" in their settings.
*   [ ] When a new "Toronto Fire" alert is created, an active user receives a toast notification immediately.
*   [ ] The notification appears in the "Notification Center" history list.
*   [ ] Disabling "Minor" alerts in settings successfully prevents toasts for minor-severity incidents.
*   [ ] Refreshing the page does not clear the notification history in the Inbox.

---

**Out of Scope (Phase 1)**
*   Geofenced (location-based) notifications.
*   SMS, Email, or Browser-native (Service Worker) Push notifications.
*   Notification "Digest" or batched summary modes.
*   Accessibility-specific modes (e.g., Voice announcements).
