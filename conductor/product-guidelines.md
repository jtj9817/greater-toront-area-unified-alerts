# Product Guidelines: GTA Alerts

## 1. Design Philosophy: The "Calm Urgency" Paradigm
GTA Alerts operates at the intersection of critical safety data and everyday utility. The design must convey the urgency of emergency situations while maintaining a stabilizing, professional atmosphere that prevents user panic.

### 1.1 Visual Identity
**Aesthetic: High-Contrast Emergency Utility**
- **Color Systems:** 
    - **Functional Signaling:** Use standardized emergency colors (Fire: #E11D48, Police: #2563EB, EMS: #059669, Transit: #D97706).
    - **Neutral Foundation:** Utilize deep charcoals and off-whites to ensure high-contrast backgrounds that make functional colors "pop" without causing eye strain.
- **Typography:** 
    - **Primary:** High-readability sans-serif (e.g., Inter, Geist) with tight tracking for headers to maximize information density.
    - **Secondary:** Monospaced fonts for technical data points (e.g., Event IDs, Alarm Levels) to denote technical precision.
- **Iconography:** Use a consistent set of thick-stroke, minimalist icons (Radix Icons/Lucide) that remain legible at small sizes on mobile screens.

### 1.2 UI/UX Patterns
- **The "Alert Card" Component:** Every incident is encapsulated in a card that uses a vertical color-coded border to denote its category. 
    - **Primary Slot:** Large-font title (Event Type).
    - **Secondary Slot:** Meta-data row (Time ago, Source badge).
    - **Tertiary Slot:** Status indicators (Alarm level, Unit count).
- **Inertia-Powered Transitions:** Leverage Inertia.js to provide a "felt" sense of real-time responsiveness. Page transitions should feel like a single-page application, minimizing the cognitive load of "loading" states.
- **Accessible Interactions:** Use Radix UI primitives for all complex interactions (Modals, Dropdowns, Tooltips) to ensure the platform is fully navigable via keyboard and screen readers, which is critical for an information-heavy utility.

## 2. Voice and Content Strategy
### 2.1 Tone: Informative & Reassuring
- **Prose Guidelines:** Use the active voice. Avoid alarmist language (e.g., use "Incident Reported" instead of "Emergency Alert!" unless specifically categorized as life-threatening).
- **Contextual Anchoring:** When displaying a transit delay, the system should ideally present the "Impact" (e.g., "Expect +20 mins travel time") rather than just the technical cause.
- **Error States:** Use friendly, helpful language even when the system fails (e.g., "We're having trouble reaching the Toronto Fire feed. We'll try again in a moment.") to maintain trust.

## 3. Systems Perspective & Integrity
### 3.1 Data-to-UI Pipeline
- **Latency as UX:** The system treats data synchronization lag as a primary UI concern. If the backend sync is delayed, the UI must transparently indicate the "Data Freshness" status to the user.
- **Normalization:** Disparate feeds (XML, JSON, Scraped) are normalized into a single `Alert` schema. This ensures that a fire incident from Toronto looks and feels consistent with a transit alert from Mississauga.
- **Resilience:** The system must degrade gracefully. If a specific source (e.g., TTC) goes down, the rest of the dashboard remains operational, with the failed source marked as "Offline" rather than breaking the feed.

### 3.2 Information Hierarchy
- **Urgency Filtering:** The backend must support severity tagging, allowing the frontend to "hoist" high-severity incidents (e.g., 3-Alarm Fire) to the top of the feed regardless of chronological order.
- **Geographic Contextualization:** The architecture should support future "Near Me" functionality, where the system uses the client's location to highlight the most relevant regional alerts.

## 4. Platform Strategy
### 4.1 Feature Parity & Mobile First
- **Desktop:** Optimized for "Command Center" viewing—multi-column layouts, expanded maps, and detailed technical sidebars for power users and researchers.
- **Mobile:** Optimized for "In-Transit" scanning—single-column feed, simplified headers, and large touch targets for filtering on the go.
- **Shared Core:** Both platforms utilize the same underlying Inertia controllers and TypeScript services, ensuring that logic changes (like search algorithms or filtering rules) are applied globally and instantly.
