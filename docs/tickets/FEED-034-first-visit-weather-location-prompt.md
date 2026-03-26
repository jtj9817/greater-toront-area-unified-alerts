# FEED-034: First-Visit Weather Location Prompt Gating

**Type:** Bug  
**Priority:** P1  
**Status:** Open  
**Component:** GTA Alerts Frontend (Weather Onboarding / Location Picker)

---

## Summary

The weather flow currently does not auto-prompt for location permission on first visit. Location access is only requested when the user manually clicks the geolocation button, which does not satisfy the expected first-visit onboarding behavior.

---

## Findings (Priority Order)

### P1 - No first-visit-only prompt gate exists for weather location onboarding

**Reviewer reference:** `resources/js/features/gta-alerts/App.tsx:465-468`, `resources/js/features/gta-alerts/hooks/useWeather.ts:16-22`, `resources/js/features/gta-alerts/components/LocationPicker.tsx:142-154`  
**Issue:** There is no first-visit detection state and no automatic onboarding prompt path. `useWeather` only restores a previously selected location from `localStorage`, and geolocation is invoked only from manual button click.

**Impact:** First-time users are never proactively prompted for location despite product requirement.

---

### P2 - No persisted prompt decision state (accepted/declined/deferred) separate from selected location

**Reviewer reference:** `resources/js/features/gta-alerts/hooks/useWeather.ts:9-56`  
**Issue:** Only selected location is persisted (`gta_weather_location_v1`). There is no durable state to track whether onboarding has already been shown/handled.

**Impact:** A compliant first-visit flow cannot be implemented reliably without introducing repetitive prompts risk.

---

### P3 - Missing test coverage for first-visit onboarding behavior contract

**Reviewer reference:** `resources/js/features/gta-alerts/components/LocationPicker.test.tsx:214-228`, `resources/js/features/gta-alerts/hooks/useWeather.test.ts:70-94`  
**Issue:** Existing tests verify manual geolocation behavior and stored-location hydration, but do not assert:
- prompt shown only for first-time visitors,
- no repeat prompt after prior decision,
- fallback behavior when permission is denied.

**Impact:** Regressions in onboarding gating logic would likely ship undetected.

---

## Root Cause

The current weather design treats location capture as an entirely user-initiated control (`LocationPicker` input/button), without a dedicated onboarding state machine for first visit consent flow.

---

## Remediation Plan (Priority Order)

### P1 - Add explicit first-visit onboarding state model in weather hook

**Primary files:**
- `resources/js/features/gta-alerts/hooks/useWeather.ts`
- `resources/js/features/gta-alerts/hooks/useWeather.test.ts`

**Plan:**
- Add a versioned localStorage key for onboarding consent state (separate from selected location).
- Extend `useWeather` return contract with minimal onboarding fields:
- `shouldPromptForLocation: boolean`
- `markLocationPromptHandled(result: 'accepted' | 'declined' | 'deferred'): void`
- Compute `shouldPromptForLocation` as true only when:
- no stored location exists, and
- onboarding state is not yet handled.
- Keep existing weather fetch behavior unchanged.

**Why this order:** This introduces the source of truth required by all UI behavior and test expectations.

---

### P2 - Trigger prompt UI once on first visit and wire to onboarding decisions

**Primary files:**
- `resources/js/features/gta-alerts/App.tsx`
- `resources/js/features/gta-alerts/components/LocationPicker.tsx`
- (new or existing UI surface in GTA Alerts header/footer area as minimally invasive)

**Plan:**
- Add a first-visit prompt surface that appears only when `shouldPromptForLocation` is true.
- Offer explicit actions:
- `Use my location` (initiates geolocation flow),
- `Not now` (dismisses and marks handled as deferred/declined).
- On successful geolocation select:
- persist location via existing `setWeatherLocation`,
- mark prompt handled as accepted.
- On geolocation denial/error:
- keep manual search path available,
- mark prompt handled in a way that prevents repeated intrusive auto-prompts.

**Constraints:**
- Keep desktop/mobile parity for prompt visibility and dismissal behavior.
- Do not alter feed/search/saved-alert behavior.

---

### P3 - Add regression coverage for onboarding gate and one-time behavior

**Primary files:**
- `resources/js/features/gta-alerts/hooks/useWeather.test.ts`
- `resources/js/features/gta-alerts/App.test.tsx` and/or `resources/js/features/gta-alerts/components/LocationPicker.test.tsx`

**Plan:**
- Add tests for first-visit prompt visibility when no location/onboarding state exists.
- Add tests for non-visibility when onboarding already handled.
- Add tests for accepted, declined, and deferred outcomes.
- Verify geolocation is not auto-requested after first-visit decision has been recorded.

---

## Boundary Check (Laravel -> Inertia -> React)

- Planned fix is frontend-local state/UI behavior only.
- No Laravel resource serialization changes are expected.
- No Inertia prop contract changes are expected.
- No TypeScript domain shape changes are expected for backend resources.

If implementation introduces server-tracked consent state later, update both PHP serialization and TypeScript page/resource types together in the same change.

---

## Verification Plan

### Narrow test scope first

- `vendor/bin/sail pnpm exec vitest run resources/js/features/gta-alerts/hooks/useWeather.test.ts`
- `vendor/bin/sail pnpm exec vitest run resources/js/features/gta-alerts/components/LocationPicker.test.tsx resources/js/features/gta-alerts/App.test.tsx`

### Full required checks after targeted green

- `vendor/bin/sail composer test`
- `vendor/bin/sail bin pint`
- `vendor/bin/sail composer lint`
- `vendor/bin/sail pnpm run lint`
- `vendor/bin/sail pnpm run format`
- `vendor/bin/sail pnpm run types`

---

## Acceptance Criteria

- [ ] First-time visitor sees a location onboarding prompt exactly once.
- [ ] Returning visitor is not auto-prompted after prior handled decision.
- [ ] Successful accept path stores location and weather fetch proceeds as before.
- [ ] Decline/defer path avoids repeated intrusive prompts and preserves manual location selection.
- [ ] Existing weather display, search, feed, and saved-alert behavior remain unchanged.
- [ ] All targeted tests and full suite checks pass.

---

## Notes

- This ticket is a remediation plan and remains **Open** until implementation and verification are completed.
