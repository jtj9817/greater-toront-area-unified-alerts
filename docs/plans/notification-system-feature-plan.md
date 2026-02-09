# Notification System Feature Plan

## Executive Summary

This document outlines the planning for a focused **In-App Notification System** feature for GTA Alerts. The system will enable users to receive personalized, real-time alerts based on their specific needs, locations, and preferences — all delivered within the application. By constraining notifications to in-app only, we maintain simplicity, reduce infrastructure complexity, and avoid external service dependencies.

**Scope Constraint:** This plan covers **in-app push notifications only**. SMS and Email delivery methods are intentionally excluded to keep the system simple and self-contained.

---

## User Personas

### 1. The Daily Commuter - "Transit-Dependent Terry"

**Demographics:**

- Age: 25-45
- Occupation: Office worker, professional
- Location: Lives in suburbs/exurbs, works downtown
- Transit usage: Daily TTC/GO Transit user

**Pain Points:**

- Caught off-guard by unexpected delays
- Missed connections due to service disruptions
- Doesn't know about delays until already at station
- Needs to decide between transit alternatives quickly

**Notification Needs:**

- **Route-specific alerts** for their regular commute routes
- **Time-based delivery** (30 mins before usual departure)
- **Severity filtering** (only delays >10 minutes, major disruptions)
- **Alternative route suggestions** when primary route is disrupted
- **Real-time updates** while in transit

**Preferred Channels:**

- In-app push notifications (primary)
- In-app notification center (morning overview)

**Typical Schedule:**

- Morning commute: 7:30 AM - 8:30 AM
- Evening commute: 5:00 PM - 6:30 PM

---

### 2. The Remote Worker - "Home-Office Hannah"

**Demographics:**

- Age: 28-40
- Occupation: Tech worker, consultant, freelancer
- Location: Residential neighborhoods
- Transit usage: Minimal, occasional for meetings/social

**Pain Points:**

- Unaware of local emergencies while focused on work
- Missed fire/police activity in neighborhood
- Doesn't know about road closures affecting deliveries/visitors
- Needs to know when it's safe to go for walks/runs

**Notification Needs:**

- **Geofenced alerts** (within 1-2km of home)
- **Emergency-only filtering** (fire, police, major incidents)
- **End-of-day digest** of neighborhood activity
- **Severe weather integration** with emergency alerts

**Preferred Channels:**

- In-app push notifications (emergency only)
- Dashboard badge (passive awareness)

**Notification Preferences:**

- Emergency incidents: Immediate
- Non-emergency: Batch into digest
- Severity filter: Major/Critical only (reduces noise during work)

---

### 3. The Parent/Caregiver - "Safety-First Sarah"

**Demographics:**

- Age: 30-50
- Occupation: Parent, caregiver
- Location: Family neighborhoods, near schools
- Transit usage: School runs, family outings

**Pain Points:**

- Concerned about safety around schools/parks
- Needs to know if it's safe for kids to walk home
- Worried about air quality from fires
- Missed school bus disruptions

**Notification Needs:**

- **School zone alerts** (incidents near children's schools)
- **Playground/park safety** notifications
- **Air quality alerts** from fire incidents
- **School bus/TTC school route** disruptions
- **Amber alerts** integration
- **Child pickup adjustments** due to incidents

**Preferred Channels:**

- In-app push notifications (immediate for safety)
- In-app notification center
- Family sharing (partner also gets alerts)

**Key Locations to Monitor:**

- Home address
- Children's school(s)
- Regular playgrounds/parks
- After-school activity locations

---

### 4. The Senior Citizen - "Simple-and-Clear Sam"

**Demographics:**

- Age: 65+
- Occupation: Retired
- Location: Established neighborhoods, senior communities
- Tech comfort: Basic smartphone usage

**Pain Points:**

- Complex apps are overwhelming
- Small text is hard to read
- Too many notifications are confusing
- Needs clear, actionable information
- Mobility challenges require elevator/escalator status

**Notification Needs:**

- **Simplified interface** (large text, high contrast)
- **Voice announcements** option (accessibility)
- **Essential alerts only** (major incidents, transit elevator outages)
- **Medical facility proximity** alerts
- **Pharmacy/doctor office** area disruptions
- **TTC accessibility** status (elevators/escalators)

**Preferred Channels:**

- In-app push notifications (reliable, immediate)
- Large-format notifications with high contrast
- Vibration + audio cues for accessibility

**Accessibility Requirements:**

- WCAG AAA compliance
- Screen reader optimized
- Adjustable font sizes
- VoiceOver/TalkBack support

---

### 5. The Mobility-Challenged User - "Accessibility Alex"

**Demographics:**

- Age: 25-65
- Condition: Wheelchair user, limited mobility, chronic illness
- Transit usage: Relies on accessible transit options

**Pain Points:**

- TTC elevator outages strand them at stations
- No advance notice of accessibility disruptions
- Difficult to reroute when accessible path is blocked
- Missed accessible shuttle bus information

**Notification Needs:**

- **TTC elevator/escalator status** (real-time)
- **Accessible route planning** with backup options
- **Construction blocking accessible paths**
- **Accessible shuttle service** announcements
- **Station accessibility** temporary changes
- **Wheel-Trans** service alerts

**Preferred Channels:**

- In-app push notifications (immediate for elevator outages)
- Persistent banner notifications (until acknowledged)
- Integration with accessibility apps

**Critical Features:**

- Elevator outage alerts at specific stations
- Alternative accessible route suggestions
- Integration with Wheel-Trans

---

### 6. The Cyclist - "Bike-Lane Brian"

**Demographics:**

- Age: 20-45
- Occupation: Various
- Location: Downtown, cycling-friendly neighborhoods
- Transit usage: Bike primary, transit secondary

**Pain Points:**

- Bike lane closures not announced
- Construction blocking cycling routes
- Road closures affecting bike paths
- Weather-related cycling hazards

**Notification Needs:**

- **Bike lane closure alerts**
- **Construction on cycling routes**
- **Road closures affecting cyclists**
- **Weather alerts** (ice, high winds)
- **Bike share station** disruptions (if applicable)

**Preferred Channels:**

- In-app push notifications
- Smartwatch integration
- Route planning app integration

**Geographic Interest:**

- Regular cycling routes
- Commute corridors
- Recreational trail areas

---

### 7. The Shift Worker - "Night-Shift Nancy"

**Demographics:**

- Age: 22-55
- Occupation: Healthcare worker, security, hospitality
- Location: Various
- Transit usage: Off-peak hours, late night

**Pain Points:**

- Different schedule than most alerts
- Transit options limited during night
- Safety concerns during late-night commutes
- Sleeping during day - noise sensitivity

**Notification Needs:**

- **Night transit** specific alerts (Blue Night routes)
- **Safety alerts** for late-night travel areas
- **24-hour service** disruption info
- **Personal safety route** recommendations

**Preferred Channels:**

- In-app push notifications
- Silent/vibrate mode (controlled via OS settings)

**Note:** Uses OS Do Not Disturb during sleep hours instead of app-level quiet hours

---

### 8. The Student - "Budget-Conscious Blake"

**Demographics:**

- Age: 18-25
- Occupation: University/college student
- Location: Student housing, near campuses
- Transit usage: Heavy (U-Pass)

**Pain Points:**

- Limited budget - can't afford delays
- Multiple campus locations
- Late for exams/classes due to transit
- Unfamiliar with alternative routes

**Notification Needs:**

- **Campus-specific** transit alerts
- **Exam/class schedule integration** (optional)
- **Student-friendly routes** (avoids transfers when possible)
- **Budget transit options** (when regular service disrupted)
- **Late-night campus safety** alerts

**Preferred Channels:**

- In-app push notifications (primary)
- Integration with campus apps

**Key Locations:**

- Residence
- Campus buildings
- Library locations
- Popular student areas

---

### 9. The Business Owner - "Downtown Daniel"

**Demographics:**

- Age: 35-60
- Occupation: Retail/restaurant owner, office manager
- Location: Commercial districts
- Transit usage: Employee/customer-dependent

**Pain Points:**

- Lost customers due to transit disruptions
- Employees late due to unforeseen delays
- Deliveries affected by road closures
- Events/construction affecting foot traffic

**Notification Needs:**

- **Business district** activity alerts
- **Foot traffic impact** predictions
- **Employee commute** area disruptions
- **Delivery route** closures
- **Event-related** street closures
- **Construction timeline** updates

**Preferred Channels:**

- In-app push notifications
- In-app notification center
- Dashboard analytics view

**Business Metrics:**

- Incident proximity to business
- Predicted customer impact
- Employee late-arrival notifications

---

### 10. The First Responder - "Emergency Erin"

**Demographics:**

- Age: 25-55
- Occupation: Firefighter, police, EMS, security
- Location: Various stations
- Transit usage: Emergency vehicles (for reference)

**Pain Points:**

- Needs real-time situational awareness
- Cross-jurisdictional incident coordination
- Road closures affecting response routes
- Resource allocation awareness

**Notification Needs:**

- **Real-time CAD feed** (existing, but enhanced)
- **Cross-service** incident awareness
- **Route optimization** based on closures
- **Mutual aid** notifications
- **Training exercise** coordination

**Preferred Channels:**

- Radio integration
- Secure in-app push notifications
- CAD system integration
- Real-time map view

**Access Level:**

- Verified professional access
- Real-time data (no delay)
- Detailed incident information

---

### 11. The Event-Goer - "Concert Casey"

**Demographics:**

- Age: 20-40
- Occupation: Various
- Location: Attends venues across GTA
- Transit usage: Event-based

**Pain Points:**

- Gets to venue only to find transit disrupted
- Doesn't know about special event transit options
- Missed last train/bus after events
- Unaware of post-event service changes

**Notification Needs:**

- **Venue-specific** transit status
- **Event transit** (special shuttles, extended hours)
- **Last-call alerts** (30 mins before last train)
- **Post-event crowd** management updates
- **Ride-share surge** predictions

**Preferred Channels:**

- In-app push notifications
- Calendar integration
- Event app integration

**Use Case:**

- Planning night out
- Real-time updates during event
- Safe return home

---

### 12. The Tourist/Visitor - "Visiting Victor"

**Demographics:**

- Age: 25-65
- Occupation: Tourist, business traveler
- Location: Hotels, tourist areas
- Transit usage: Unfamiliar system

**Pain Points:**

- Unfamiliar with GTA transit system
- Language barriers
- Doesn't know alternative routes
- Trusts apps for navigation

**Notification Needs:**

- **Tourist area** incidents
- **Airport connection** alerts (UP Express)
- **Simplified language** (avoid jargon)
- **Visual maps** with routes
- **Translation support** (multilingual)
- **Tourist-friendly alternatives**

**Preferred Channels:**

- In-app push notifications
- Visual map overlays
- Multi-language support

**Key Locations:**

- Hotel location
- Tourist attractions
- Airport
- Transit hubs

---

## Feature Requirements by Category

### Core Notification Features

| Feature                 | Priority | Personas       | Description                       |
| ----------------------- | -------- | -------------- | --------------------------------- |
| Geofenced Alerts        | P0       | All            | Location-based notification zones |
| Route Subscriptions     | P0       | Transit users  | Follow specific TTC/GO routes     |
| Severity Filtering      | P0       | All            | Only notify on relevant severity  |
| In-App Delivery         | P0       | All            | Push notifications within app     |
| Digest Mode             | P1       | Remote workers | Batched daily summaries           |
| Real-Time Updates       | P0       | Commuters      | Live incident updates             |
| Alternative Suggestions | P1       | Commuters      | Show alternate routes             |

### Accessibility Features

| Feature                   | Priority | Personas            | Description               |
| ------------------------- | -------- | ------------------- | ------------------------- |
| Large Text Mode           | P1       | Seniors             | Adjustable font sizes     |
| Screen Reader Support     | P0       | Visually impaired   | Full VoiceOver/TalkBack   |
| Voice Notifications       | P2       | Seniors             | Audio announcement option |
| High Contrast Mode        | P1       | Visual impairments  | WCAG AAA colors           |
| Elevator/Escalator Status | P0       | Mobility challenged | TTC accessibility alerts  |
| Simplified UI Mode        | P1       | Seniors             | Reduced complexity        |

### Advanced Features

| Feature                | Priority | Personas              | Description               |
| ---------------------- | -------- | --------------------- | ------------------------- |
| Calendar Integration   | P2       | Students, event-goers | Sync with schedules       |
| Smartwatch Support     | P2       | Cyclists              | Apple Watch, Wear OS      |
| Family Sharing         | P2       | Parents               | Share alerts with partner |
| Professional Dashboard | P2       | First responders      | Real-time map view        |
| Analytics/Insights     | P3       | Business owners       | Foot traffic predictions  |
| ML-Based Predictions   | P3       | Commuters             | Delay probability         |

---

## Technical Architecture

### Notification Service Components

```
┌─────────────────────────────────────────────────────────────────┐
│                 IN-APP NOTIFICATION SERVICE                      │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────────┐    ┌─────────────────┐    ┌─────────────┐  │
│  │ User Preferences│    │ Alert Processing│    │  In-App     │  │
│  │   Manager       │───▶│     Engine      │───▶│  Delivery   │  │
│  │                 │    │                 │    │             │  │
│  │ • Geofences     │    │ • Filter rules  │    │ • Push      │  │
│  │ • Routes        │    │ • Severity check│    │ • Banner    │  │
│  │ • Severity      │    │ • User matching │    │ • In-App    │  │
│  │ • Digest Mode   │    │ • Throttling    │    │ • Real-time │  │
│  └─────────────────┘    └─────────────────┘    └─────────────┘  │
│           │                                               │      │
│           ▼                                               ▼      │
│  ┌─────────────────┐                            ┌─────────────┐ │
│  │  Subscription   │                            │  In-App     │ │
│  │    Service      │                            │  Queue      │ │
│  │                 │                            │             │ │
│  │ • Create/Update │                            │ • Rate limit│ │
│  │ • Unsubscribe   │                            │ • Retry     │ │
│  │ • Batch ops     │                            │ • Digest    │ │
│  └─────────────────┘                            └─────────────┘ │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

### Database Schema Additions

```php
// notification_preferences table
Schema::create('notification_preferences', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    // In-app only - no channel selection needed
    $table->string('alert_type'); // 'transit', 'emergency', 'accessibility'
    $table->string('severity_threshold'); // 'all', 'minor', 'major', 'critical'
    $table->json('geofences'); // Array of location zones
    $table->json('subscribed_routes'); // TTC/GO route IDs
    $table->boolean('digest_mode')->default(false); // In-app digest
    $table->boolean('push_enabled')->default(true);
    $table->timestamps();
});

// notification_logs table
Schema::create('notification_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained();
    $table->foreignId('alert_id')->nullable();
    $table->string('delivery_method')->default('push'); // 'push', 'in-app'
    $table->string('status'); // 'sent', 'delivered', 'failed', 'read', 'dismissed'
    $table->timestamp('sent_at');
    $table->timestamp('read_at')->nullable();
    $table->timestamp('dismissed_at')->nullable();
    $table->json('metadata');
    $table->timestamps();
});
```

---

## Implementation Phases

### Phase 1: MVP (Weeks 1-4)

**Goal:** Basic in-app notification infrastructure

**Features:**

- User preference storage
- In-app push notification system
- Simple geofenced alerts
- Severity-based filtering
- In-app notification center with daily digest

**Personas Served:**

- Daily Commuters (basic route alerts)
- Remote Workers (digest mode)

### Phase 2: Enhanced Targeting (Weeks 5-8)

**Goal:** Precision in-app delivery

**Features:**

- Route-specific subscriptions
- Real-time in-app updates
- Alternative route suggestions
- Persistent banner notifications

**Personas Served:**

- Daily Commuters (full feature set)
- Parents (school zone alerts)

### Phase 3: Accessibility & Inclusion (Weeks 9-12)

**Goal:** Universal access

**Features:**

- TTC elevator/escalator status
- Large text/high contrast modes
- Screen reader optimization
- Simplified UI mode
- Voice notifications (optional)

**Personas Served:**

- Seniors
- Mobility-challenged users
- Visually impaired

### Phase 4: Advanced Features (Weeks 13-16)

**Goal:** Power user features

**Features:**

- Smartwatch integration
- Calendar sync
- Family sharing
- ML-based predictions
- Professional dashboard

**Personas Served:**

- Cyclists (smartwatch)
- Students (calendar)
- Parents (family sharing)
- First responders (dashboard)

### Phase 5: Business & Tourism (Weeks 17-20)

**Goal:** Commercial viability

**Features:**

- Business analytics
- Multi-language support
- Tourist mode
- Event integration
- API for third parties

**Personas Served:**

- Business owners
- Tourists
- Event-goers

---

## Success Metrics

### User Engagement

- **Notification Open Rate:** Target >40% (industry avg ~25%)
- **Opt-in Rate:** Target >60% of active users
- **Retention:** 7-day retention of notified users vs non-notified
- **Session Length:** Increase in app opens per day

### Feature Adoption

- **Route Subscriptions:** % of users subscribing to routes
- **Geofence Setup:** % with custom location zones
- **Notification Center Usage:** % checking notification center regularly
- **Digest Mode:** % using digest vs real-time

### Technical Performance

- **Delivery Latency:** <5 seconds from incident to notification
- **Delivery Success Rate:** >99.5%
- **System Reliability:** <0.1% downtime
- **Battery Impact:** Minimal background drain

### User Satisfaction

- **NPS Score:** Target >50
- **App Store Rating:** Maintain >4.5 stars
- **Support Tickets:** <1% related to notifications
- **Uninstall Rate:** Decrease from notification users

---

## Risks & Mitigations

| Risk                 | Impact | Mitigation                                               |
| -------------------- | ------ | -------------------------------------------------------- |
| Notification fatigue | High   | Smart throttling, relevance scoring, easy unsubscribe    |
| Battery drain        | Medium | Efficient geofencing, batch processing                   |
| Privacy concerns     | High   | Clear data usage policy, local processing where possible |
| Scale challenges     | Medium | Queue-based architecture, rate limiting                  |
| Accessibility gaps   | High   | WCAG compliance testing, user feedback loops             |
| False positives      | Medium | Severity verification, user feedback on accuracy         |

---

## Open Questions

1. **Privacy:** How precise should geofencing be? (Exact address vs neighborhood)
2. **Storage:** How long should notification history be retained?
3. **Partnerships:** Should we integrate with TTC/GO official apps?
4. **Moderation:** User-generated alerts - verification process?
5. **International:** Support for visitors (multiple languages)?
6. **Battery:** How to balance real-time geofencing with battery life?

---

## Related Documentation

- [Frontend Types](../frontend/types.md) - Domain model alignment
- [Unified Alerts System](../backend/unified-alerts-system.md) - Backend data source
- [Architecture Walkthrough](../backend/architecture-walkthrough.md) - Service patterns
- [Production Scheduler](../backend/production-scheduler.md) - Background job handling

---

_Last Updated: February 2026_
_Next Review: Post-Phase 1 Implementation_
