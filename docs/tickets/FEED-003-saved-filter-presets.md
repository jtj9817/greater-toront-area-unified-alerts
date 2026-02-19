# [FEED-003] Saved Filter Presets

**Date:** 2026-02-18
**Status:** Open
**Priority:** Low
**Components:** Backend, Frontend, UX
**Depends On:** [FEED-001](./FEED-001-server-side-filters-infinite-scroll.md)

## Problem

Users who regularly monitor specific subsets of alerts (e.g., transit delays on their commute corridor, fire incidents in their neighbourhood) must manually re-apply filters every visit. There is no way to save or recall filter combinations.

## Current State

- Filters are either URL query params (status) or ephemeral client-side state (category, search, time)
- After FEED-001, all filters will be server-side query params, making them shareable via URL
- No saved preset mechanism exists

## Proposed Solution

Allow users to save named filter combinations as presets, accessible as quick-action chips above the filter bar.

### Storage Strategy

| User Type | Storage | Sync |
|-----------|---------|------|
| **Authenticated** | Database table (`filter_presets`: user_id, name, params JSON) | Cross-device |
| **Anonymous** | localStorage | Browser-only |

### Example Presets

- "My Commute" — `source=transit,go_transit`
- "Nearby Fire" — `source=fire&since=1h`
- "Scarborough Police" — `source=police&q=D41,D42,D43`

### UI Concept

- Horizontal chip bar below the filter controls
- "Save current filters" button when non-default filters are active
- Preset chips apply their saved params to the URL on click
- Edit/delete via a small dropdown on each chip
- Maximum of ~10 presets per user to keep the UI manageable

### Key Considerations

- **URL is the source of truth:** Presets simply store a set of query params and apply them to the URL when activated
- **Preset validation:** Saved params should be validated against the current filter schema on load (filters may evolve over time)
- **Migration path:** If an anonymous user signs up, localStorage presets could be offered for import

## Out of Scope

- Shared/public presets between users
- Preset-triggered notifications (covered by the existing NotificationPreference system)
