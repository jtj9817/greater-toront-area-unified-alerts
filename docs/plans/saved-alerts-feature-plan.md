# Epic [GTA-100]: Implement "Saved Alerts" Feature

**Created**: 2026-03-16
**Completed**: Not Started
**Status**: 🔴 Not Started
**Purpose**: Allow users to save alerts for quick reference, utilizing local storage for guests (up to 10 alerts) and database storage for authenticated users.

---

## Problem Statement
Currently, users have no way to bookmark or save specific alerts (Fire, Police, Transit) that they wish to monitor or reference later. 

1. **Lack of Persistence**: If a user navigates away from an active alert, they must manually search for it again.
2. **Differing User States**: Unauthenticated users need a lightweight way to save alerts during their current session or across return visits on the same device, while authenticated users expect a persistent, cross-device experience.
3. **Storage Constraints**: Client-side storage needs to be actively managed to prevent unbounded growth.

Implementing a "Saved Alerts" feature bridging the frontend Web Storage API and a backend relational database will provide a seamless experience tailored to the user's authentication status.

---

## Design Decisions (Engineering Preferences)

| Decision | Choice |
| :--- | :--- |
| **Guest Storage Mechanism** | Browser Web Storage API (`localStorage`) |
| **Auth Storage Mechanism** | PostgreSQL / MySQL Database via Eloquent (Junction Table) |
| **Frontend State Abstraction** | Custom React Hook (`useSavedAlerts`) to dynamically route save actions |
| **Max Local Storage Cap** | 10 alerts (Strict limit) |
| **Eviction Policy (Local)** | Prompt user to manually evict the oldest 3 alerts when the cap is hit |
| **Backend Junction Table** | `user_alerts` (`id`, `user_id`, `alert_id`, timestamps) |

---

## Solution Architecture

### Overview
```text
User Clicks "Save Alert" 
     │
     ▼
[Auth Check: usePage().props.auth.user?]
     │
     ├─(Guest)────────────────────────────┐
     │                                    │
     ▼                                    ▼
[Check LocalStorage]                 [POST /api/user/alerts]
     │                                    │
     ├─ < 10 items?                       ├─ Exists in `user_alerts`?
     │    ├─ Yes: Append & Save           │    ├─ Yes: Return 409 Conflict
     │    └─ No: Prompt to clear 3        │    └─ No: Insert & Return 201
     ▼                                    ▼
[Update UI Toast]                    [Update UI Toast]
```

---

## Implementation Tasks (JIRA Stories)

### Phase 1: Backend Infrastructure & API [GTA-101] 🔴

#### Task 1.1: Database Schema & Relationships 🔴
**File**: `database/migrations/xxxx_xx_xx_xxxxxx_create_user_alerts_table.php`
**File**: `app/Models/User.php`

```php
// Migration
Schema::create('user_alerts', function (Blueprint $table) {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();
    $table->string('alert_id'); // e.g., 'police:77', 'fire:F123'
    $table->timestamps();

    $table->unique(['user_id', 'alert_id']);
});

// app/Models/User.php
public function savedAlerts() {
    // Basic hasMany if we're just storing the IDs, or belongsToMany if joined to a physical alerts table. 
    // Given the unified alerts architecture, storing just string IDs and fetching via providers is preferred.
    return $this->hasMany(UserAlert::class);
}
```
**Key Logic/Responsibilities**:
* Create a lightweight junction model `UserAlert`.
* Ensure a database-level `UNIQUE` constraint on `user_id` + `alert_id` to prevent duplicates and race conditions.

#### Task 1.2: User Alerts REST API 🔴
**File**: `routes/api.php`
**File**: `app/Http/Controllers/UserAlertController.php`

```php
// app/Http/Controllers/UserAlertController.php
public function store(Request $request) {
    $validated = $request->validate(['alert_id' => 'required|string']);
    
    $exists = $request->user()->savedAlerts()->where('alert_id', $validated['alert_id'])->exists();
    if ($exists) {
        return response()->json(['message' => 'Alert already saved'], 409);
    }

    $request->user()->savedAlerts()->create($validated);
    return response()->json(['message' => 'Saved successfully'], 201);
}

public function index(Request $request) {
    return response()->json($request->user()->savedAlerts);
}
```
**Key Logic/Responsibilities**:
* **POST `/api/user/alerts`**: Validates `alert_id`, checks for existing record, inserts, and returns appropriate status code.
* **GET `/api/user/alerts`**: Returns the list of `alert_id`s saved by the authenticated user.

---

### Phase 2: Frontend State & Storage Hooks [GTA-102] 🔴

#### Task 2.1: Implement `useSavedAlerts` Hook 🔴
**File**: `resources/js/features/gta-alerts/hooks/useSavedAlerts.ts`

```typescript
// Core abstraction bridging local vs API storage
export function useSavedAlerts() {
    const user = usePage().props.auth.user;

    const saveAlert = async (alertId: string) => {
        if (user) {
            // ... Axios POST to /api/user/alerts
        } else {
            // ... LocalStorage logic
        }
    };
    
    // ...
}
```
**Key Logic/Responsibilities**:
* Checks `auth.user` state.
* If unauthenticated: Reads `gta_saved_alerts` array. If `length >= 10`, throws a specific exception or returns a specific payload requesting eviction.
* Provides a `clearOldestThree()` method that slices the local storage array (`alerts.slice(3)`).

---

### Phase 3: UI Integration [GTA-103] 🔴

#### Task 3.1: Wire Save Button in Alert Details 🔴
**File**: `resources/js/features/gta-alerts/components/AlertDetailsView.tsx`

```tsx
// Inside AlertDetailsView
const { saveAlert, clearOldestThree } = useSavedAlerts();

const handleSave = async () => {
    try {
        await saveAlert(alert.id);
        toast.success("Alert saved!");
    } catch (error) {
        if (error.code === 'LOCAL_LIMIT_REACHED') {
            toast.error("Storage full. You can only save 10 alerts offline.", {
                action: {
                    label: "Clear Oldest 3",
                    onClick: () => clearOldestThree()
                }
            });
        } else if (error.response?.status === 409) {
            toast.info("Alert is already saved.");
        }
    }
};

<button 
    id={`gta-alerts-alert-details-${alert.id}-save-btn`}
    onClick={handleSave}
>
    Save Alert
</button>
```
**Key Logic/Responsibilities**:
* Intercept button click and route to `useSavedAlerts`.
* Handle specific errors gracefully using the existing Toast/Notification system.
* Display the custom action button for eviction when the 10-limit is reached for guests.

---

### Phase 4: Automated Testing [GTA-104] 🔴

#### Task 4.1: Backend Functionality Tests 🔴
**File**: `tests/Feature/UserAlertTest.php`
* Test: Guest accessing API receives `401 Unauthorized`.
* Test: Auth user can save an alert successfully (`201 Created`).
* Test: Auth user saving duplicate alert receives `409 Conflict` (or similar indicator).
* Test: Auth user can retrieve their saved alerts via GET request.

#### Task 4.2: Frontend Unit Tests 🔴
**File**: `resources/js/features/gta-alerts/hooks/useSavedAlerts.test.ts`
* Test: `saveAlert` stores item in mocked `localStorage`.
* Test: Array limit capped at 10 correctly triggers the limit-reached error/callback.
* Test: `clearOldestThree` removes the first 3 items from the mocked `localStorage` array.

---

## File Summary

| File | Action | Status |
| :--- | :--- | :--- |
| `database/migrations/*_create_user_alerts_table.php` | Create | 🔴 |
| `app/Models/UserAlert.php` | Create | 🔴 |
| `app/Models/User.php` | Modify | 🔴 |
| `app/Http/Controllers/UserAlertController.php` | Create | 🔴 |
| `routes/api.php` | Modify | 🔴 |
| `resources/js/features/gta-alerts/hooks/useSavedAlerts.ts` | Create | 🔴 |
| `resources/js/features/gta-alerts/components/AlertDetailsView.tsx` | Modify | 🔴 |
| `tests/Feature/UserAlertTest.php` | Create | 🔴 |
| `resources/js/features/gta-alerts/hooks/useSavedAlerts.test.ts` | Create | 🔴 |

---

## Execution Order
1. **[Backend Schema]**: Create migration and Eloquent models. Migrate the database.
2. **[Backend API]**: Build the `UserAlertController` endpoints. Verify via Pest tests.
3. **[Frontend Hook]**: Implement `useSavedAlerts.ts` with both `localStorage` logic and API integration. Verify via Vitest.
4. **[Frontend UI]**: Connect the hook to `AlertDetailsView.tsx` button (`id="gta-alerts-alert-details-{id}-save-btn"`).
5. **[E2E Verification]**: Manually test guest limit limits, eviction, and logged-in persistence.

---

## Edge Cases to Handle
1. **[Scenario: User saves 10 items locally, then logs in]**: 
   * *Mitigation*: Ideally, in the future, we synchronize local items to the DB on login. For this iteration, they act as two parallel, isolated systems.
2. **[Scenario: Duplicate Save for Authenticated User]**: 
   * *Mitigation*: DB unique constraint blocks race conditions. Controller handles it gracefully returning 409, and UI interprets it as "Already saved" without crashing.
3. **[Scenario: LocalStorage disabled / Quota exceeded]**: 
   * *Mitigation*: Wrap `localStorage.setItem` in a `try/catch`. Throw a generic "Storage disabled" error to the Toast UI.
4. **[Scenario: Clicking Save multiple times quickly]**:
   * *Mitigation*: Add `isPending` state in the hook to disable the button during the API request.

---

## Rollback Plan
1. Revert Git commits associated with the frontend UI branch.
2. Remove the `useSavedAlerts` hook.
3. Remove the REST API endpoints from `routes/api.php`.
4. Drop the `user_alerts` table using a rollback migration (`php artisan migrate:rollback --step=1`).

---

## Success Criteria
- [ ] Clicking `id="gta-alerts-alert-details-{id}-save-btn"` saves the alert.
- [ ] Unauthenticated users are hard-capped at 10 alerts.
- [ ] Reaching 10 alerts prompts the user to clear the oldest 3.
- [ ] Authenticated users save alerts to a persistent database via POST API.
- [ ] Authenticated users fetching the "Saved Alerts" page triggers the GET API correctly.
- [ ] All unit and feature tests pass with green coverage.
