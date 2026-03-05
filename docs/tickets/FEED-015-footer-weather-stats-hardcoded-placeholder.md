---
ticket_id: FEED-015
title: "[Tech Debt] Wire Footer Weather Stats to Live or Static Data Source"
status: Open
priority: Low
assignee: Unassigned
created_at: 2026-03-04
tags: [tech-debt, frontend, ui, design-revamp]
related_files:
  - resources/js/features/gta-alerts/components/Footer.tsx
---

## Summary

The `Footer` component introduced in Phase 2 of the UI Design Revamp (Prototype Two) displays weather environment stats as hardcoded placeholder strings. These values are **not wired to any live or static data source** and will show stale, incorrect data to all users indefinitely until addressed.

## Problem Statement

`resources/js/features/gta-alerts/components/Footer.tsx` (introduced in `e55cd96`) contains the following hardcoded string:

```tsx
Temp: 24 C | Humidity: 65% | Wind: 15km/h W
```

This was scaffolded from the prototype design as a placeholder. There is no data source, API call, prop, or backend field backing these values. As a result:

- Every user sees identical, static weather information regardless of actual conditions.
- The footer implies live environmental context that is not present.
- The footer design explicitly uses a `thermostat` icon, reinforcing the expectation that this data is real.

## Acceptance Criteria

- [ ] Decide on the data strategy for footer weather stats (see Options below).
- [ ] Replace the hardcoded string with the chosen implementation.
- [ ] If data is unavailable or loading, render a graceful fallback (e.g., hide the weather stat row or show `—`).
- [ ] No TypeScript errors (`pnpm run types` passes).
- [ ] No lint/format errors (`pnpm run lint`, `pnpm run format`).

## Options

### Option A — Remove the weather stat entirely
Remove the left-side weather stat block from the footer if there is no plan to source the data. The footer's right-side links remain. This is the lowest-effort resolution and avoids misleading users.

### Option B — Static environment metadata from backend (recommended)
Pass current weather data from a backend controller prop alongside the existing `GtaAlertsController` page props. Source from a lightweight public weather API (e.g., Environment Canada, Open-Meteo) fetched and cached by a scheduler command (every 30–60 min).

### Option C — Client-side fetch on mount
Fetch weather data directly from the frontend using a public weather API on component mount. Simpler than backend integration but adds a client-side dependency and potential CORS/rate-limit concerns.

## Notes

- Flagged during phase commit audit of `20260304_design_revamp_20260303_audit.md` (Phase 2 technical debt).
- Introduced commit: `e55cd96` — `feat(gta-alerts): implement phase 2 global layout`
- This is a cosmetic/data-accuracy issue and does not block Phase 3 or Phase 4 of the design revamp.
