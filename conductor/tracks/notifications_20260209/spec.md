# Track Specification: In-App Notification System (Phase 1 MVP)

**Overview**  
This track implements the Phase 1 MVP described in `docs/plans/notification-system-feature-plan.md`: in-app notifications with user preferences, severity filtering, simple geofenced matching, and a persistent notification center that includes daily digest entries.

---

**Functional Requirements**
1. **Preference Management**
   - Users can manage subscriptions to alert types/sources and transit routes.
   - Users can set severity threshold preferences.
   - Users can configure simple geofence zones.
   - Users can enable/disable real-time push and daily digest mode.
2. **Matching Engine**
   - New alerts are matched against user preferences by source/route, severity, and geofence rules.
   - Non-matching alerts must not trigger user notifications.
3. **Real-Time In-App Delivery**
   - Matching alerts are delivered through in-app toasts/banners via WebSockets (Laravel Echo).
4. **Notification Center (Inbox)**
   - Persistent in-app history for delivered notifications (`notification_logs`).
   - Users can mark notifications as read or dismissed.
5. **Daily Digest**
   - Users with digest mode enabled receive a daily digest entry in-app.
   - Digest results are visible in the notification center.
6. **In-App-Only Constraint**
   - Delivery is strictly in-app for this track (no SMS/email/external channels).

---

**Non-Functional Requirements**
- **Latency:** Real-time notifications should appear within <5 seconds of alert synchronization for matching users.
- **Scalability:** Matching and delivery must support concurrent notifications across active users.
- **Security:** Users can only view and manage their own preferences and notification history.
- **Reliability:** Digest generation should run daily without duplicate entries for the same user/time window.

---

**Acceptance Criteria**
- [ ] A user can save preferences that include route/source subscriptions, severity threshold, geofence zones, and digest mode.
- [ ] When a matching alert is created inside a user's configured geofence, the user receives an in-app toast in near real time.
- [ ] When an alert does not match geofence or severity preferences, no toast is delivered.
- [ ] Delivered notifications appear in the Notification Center and persist after page refresh.
- [ ] A digest-enabled user receives a daily digest entry in the Notification Center.
- [ ] A user cannot read or modify another user's preferences or notification logs.

---

**Out of Scope (Phase 1 MVP)**
- SMS, Email, or browser-native service worker push notifications.
- Advanced accessibility features (voice announcements, high-contrast mode, simplified UI mode).
- Smartwatch integration, calendar integration, family sharing, analytics dashboards.
- ML-based predictions, multilingual tourist mode, and third-party API exposure.
