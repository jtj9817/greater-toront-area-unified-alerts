# FEED-041: Remove Mobile Bottom Nav, Surface Weather Footer on All Viewports

**Type:** Enhancement
**Priority:** P2
**Status:** Open
**Component:** GTA Alerts Frontend (Mobile Layout / Navigation / Weather)

---

## Summary

The mobile layout currently renders a bottom navigation bar (`BottomNav`) in place of the footer, which means weather status is invisible on small screens. Weather is worked around via a compact bar bolted onto the header (FEED-038). This ticket removes the bottom navigation bar on mobile and makes the weather footer visible on all viewports, giving mobile users the same live weather status that desktop users already have. Navigation remains fully accessible via the existing sidebar drawer (hamburger menu).

---

## Problem

- `BottomNav` (`md:hidden`) occupies the footer slot on mobile with five redundant nav tabs (Feed, Inbox, Saved, Zones, Settings). The same navigation is already available through the mobile sidebar drawer, making the bottom bar a duplicate affordance.
- `Footer` (`hidden md:flex`) hides the weather status bar entirely on mobile.
- As a result, weather data was added a second time to the header in a compact bar (`gta-alerts-mobile-weather-bar`) as a workaround (FEED-038). Two surfaces now show the same weather information.
- The refresh FAB positioning (`bottom-24` on mobile) and MinimalModeToggle (`bottom-40` on mobile) are sized to clear the BottomNav height and will need adjustment once the nav is removed.

---

## Proposed Solution

### 1. Make the footer visible on all viewports (`Footer.tsx`)

Change the root `<footer>` from `hidden md:flex` to `flex`. Hide only the footer links section on mobile so the strip remains compact and focused on weather:

```diff
- className="hidden h-12 flex-none items-center justify-between border-t-4 border-primary bg-background-dark px-8 text-[11px] font-black tracking-widest text-white uppercase md:flex"
+ className="flex h-12 flex-none items-center justify-between border-t-4 border-primary bg-background-dark px-8 text-[11px] font-black tracking-widest text-white uppercase"
```

Footer links div: add `hidden md:flex` so links only appear at the `md` breakpoint:

```diff
- <div id="gta-alerts-footer-links" className="flex gap-6">
+ <div id="gta-alerts-footer-links" className="hidden gap-6 md:flex">
```

### 2. Remove `BottomNav` and the mobile weather header bar (`App.tsx`)

- Remove the `import { BottomNav }` import.
- Remove the `<BottomNav currentView={currentView} onNavigate={handleNavigate} />` JSX.
- Remove the `gta-alerts-mobile-weather-bar` block from the header (FEED-038 workaround, now redundant).
- Remove `isLoading: isWeatherLoading` from the `useWeather()` destructure (only used by the now-removed weather bar).
- Adjust FAB fixed positioning to clear the `h-12` (48 px) footer on mobile:
  - Refresh FAB: `bottom-24` → `bottom-16` (mobile), keep `md:bottom-8` for desktop.
  - MinimalModeToggle wrapper: `bottom-40` → `bottom-32` (mobile), keep `md:bottom-24` for desktop.

### 3. Delete `BottomNav.tsx`

The component has no remaining consumers. Delete `resources/js/features/gta-alerts/components/BottomNav.tsx`.

---

## Acceptance Criteria

- [ ] On mobile (< 768 px), the bottom navigation bar (Feed / Inbox / Saved / Zones / Settings tabs) is no longer rendered.
- [ ] On mobile, the weather footer is visible at the bottom of the screen, showing temperature, conditions, and any active weather alert badge.
- [ ] On mobile, the footer links (Incident Archives, Privacy Policy, System Status) are hidden — only the weather section is shown.
- [ ] On desktop (≥ 768 px), the footer is visually unchanged: weather on the left, links on the right.
- [ ] The mobile compact weather bar in the header (`gta-alerts-mobile-weather-bar`) is removed.
- [ ] Navigation on mobile is fully accessible through the sidebar drawer (hamburger menu).
- [ ] Refresh FAB and MinimalModeToggle do not overlap with the footer on any viewport.
- [ ] All existing tests pass after updates (see test changes below).
- [ ] Pint, ESLint, Prettier, and TypeScript quality gates all pass.

---

## Files Affected

| File | Change |
|---|---|
| `resources/js/features/gta-alerts/components/Footer.tsx` | Remove `hidden` / `md:flex` on root; add `hidden md:flex` on links section |
| `resources/js/features/gta-alerts/App.tsx` | Remove `BottomNav` import + JSX; remove mobile weather bar block; adjust FAB positions |
| `resources/js/features/gta-alerts/components/BottomNav.tsx` | **Delete** |
| `tests/e2e/design-revamp-phase-4.spec.ts` | Update `responsive-mobile-parity` test (see below) |
| `resources/js/features/gta-alerts/App.test.tsx` | Remove / update 4 tests for `gta-alerts-mobile-weather-bar` |

---

## Test Changes Required

### `tests/e2e/design-revamp-phase-4.spec.ts` — `responsive-mobile-parity`

Remove bottom-nav assertions:
```diff
- expect(document.getElementById('gta-alerts-bottom-nav')).toBeInTheDocument();
- expect(document.getElementById('gta-alerts-bottom-nav-btn-feed')).toBeInTheDocument();
- expect(document.getElementById('gta-alerts-bottom-nav-btn-inbox')).toBeInTheDocument();
```

Add footer assertions:
```diff
+ expect(document.getElementById('gta-alerts-footer')).toBeInTheDocument();
+ expect(document.getElementById('gta-alerts-footer-weather')).toBeInTheDocument();
```

Update FAB positioning assertion:
```diff
- expect(refreshButton).toHaveClass('bottom-24');
+ expect(refreshButton).toHaveClass('bottom-16');
```

Update scenario metadata (remove "Bottom nav coexists with feed shell on mobile"):
```diff
  {
    id: 'responsive-mobile-parity',
    checks: [
      'Drawer opens/closes with menu actions and Escape key',
-     'Bottom nav coexists with feed shell on mobile',
+     'Weather footer renders on mobile in place of bottom nav',
      'Refresh FAB reserves clearance above footer touch area',
    ],
  },
```

### `resources/js/features/gta-alerts/App.test.tsx`

Remove the 4 tests that assert on `gta-alerts-mobile-weather-bar`, or replace them with equivalent assertions on the footer weather display (`gta-alerts-footer-weather`).

---

## Out of Scope

- Restoring the bottom nav at any breakpoint.
- Redesigning the mobile sidebar drawer navigation.
- Changes to the `useWeather` hook or weather API.
- Adding location picker to the footer.

---

## Related Tickets

| Ticket | Description |
|---|---|
| [FEED-038](./FEED-038-weather-bar-hidden-on-mobile.md) | Weather bar hidden on mobile — workaround this ticket supersedes |
| [FEED-016](./FEED-016-design-revamp-phase-4-verification-findings.md) | Design revamp phase 4 — original mobile shell contract |
| [FEED-015](./FEED-015-footer-weather-stats-hardcoded-placeholder.md) | Footer weather stats originally hardcoded |
