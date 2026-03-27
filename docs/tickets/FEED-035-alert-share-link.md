# FEED-035: Replace "Broadcast Alert" with Simple Alert Sharing

**Type:** Enhancement  
**Priority:** P3  
**Status:** Closed  
**Component:** GTA Alerts Frontend (Alert Details / URL State)

---

## Summary

The current `Broadcast Alert` button in the alert details view is presentational only and does not trigger any behavior. Replace it with a simple sharing flow that supports a stable alert deep link and uses native device sharing when available, with clipboard copy as the fallback.

---

## Problem

- The current label implies a larger publishing or distribution feature than the application actually supports.
- The current button has no click handler, so users receive no result when interacting with it.
- The current alert details view is driven by local React state, not a stable per-alert URL, so there is no reliable link to copy or reopen.

---

## Current State

- `AlertDetailsView` renders a `Broadcast Alert` button with no `onClick` behavior.
- `App.tsx` opens alert details from local `activeAlertId` state.
- The page URL does not currently preserve the selected alert ID.

---

## Proposed Solution

Rename the action to `Share Alert` and keep the implementation intentionally small.

### Expected Behavior

1. Opening an alert detail view updates the URL with a shareable query parameter such as `?alert=<id>`.
2. Loading the GTA Alerts page with a valid `alert` query parameter opens that alert detail view automatically.
3. Clicking `Share Alert` uses `navigator.share()` when supported by the browser/device.
4. If native share is unavailable or fails, the app copies the alert URL to the clipboard.
5. The user receives immediate feedback via an existing toast or lightweight success message such as `Alert link copied`.

### UX Notes

- Use `Share Alert` instead of `Broadcast Alert`.
- Do not add a custom share modal.
- Do not add provider-specific share buttons.
- Do not require authentication.

---

## Out of Scope

- Direct posting to X, Facebook, Instagram, Threads, or other social platforms
- Social media metadata optimization beyond existing page behavior
- Share analytics or event tracking
- QR codes, email integrations, or SMS-specific flows
- Backend persistence for shared links

---

## Implementation Notes

### Frontend Areas Likely Affected

- `resources/js/features/gta-alerts/App.tsx`
- `resources/js/features/gta-alerts/components/AlertDetailsView.tsx`
- `resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx`
- `resources/js/features/gta-alerts/App.test.tsx`

### Technical Approach

- Make the selected alert part of URL state, alongside the existing filter/search query model.
- Preserve existing feed filters and search query when constructing the shared URL.
- Resolve invalid or stale `alert` query params gracefully by leaving the user on the feed without throwing.
- Keep the sharing logic frontend-only unless a later requirement introduces canonical server-side detail routes.

### Edge Cases

- Alert ID in URL does not exist in the currently loaded result set.
- Clipboard access is unavailable or denied.
- Native share is supported but the share attempt is cancelled.
- Shared URL includes filters that no longer surface the target alert.

---

## Acceptance Criteria

- [ ] The details action label is updated from `Broadcast Alert` to `Share Alert`.
- [ ] A selected alert can be reopened from a copied URL containing an alert query parameter.
- [ ] Clicking `Share Alert` triggers native share on supported devices.
- [ ] Unsupported browsers fall back to copying the alert URL to the clipboard.
- [ ] The user receives visible feedback after a successful share or copy action.
- [ ] Invalid `alert` query parameters fail safely without breaking feed navigation.
- [ ] No social-media-specific integrations are introduced.

---

## Notes

- This ticket intentionally favors a minimal utility feature over a broader distribution workflow.
- If the team wants the absolute smallest implementation, the native share step can be omitted and the button can become `Copy Alert Link` instead.

---

## Boundary Check (Laravel -> Inertia -> React)

- No Laravel payload shape changes were required.
- No Inertia page prop contract changes were required.
- No TypeScript transport type updates were required.
- The implementation is frontend-only: URL query synchronization and share/copy interaction logic in React.

---

## Changes Applied

1. Replaced the inert `Broadcast Alert` details action with a wired `Share Alert` action.
2. Added URL-based detail deep-linking via `?alert=<id>`:
- Opening an alert now writes `alert` into the current URL.
- Closing detail view removes `alert` from the URL.
- Initial page load with a valid `alert` query opens that alert detail view.
- Invalid `alert` query values fail safe and are removed from the URL.
3. Added share flow:
- Uses `navigator.share()` when available.
- Falls back to clipboard copy (`navigator.clipboard.writeText`) when native share is unavailable or fails.
- Includes a legacy copy fallback using `document.execCommand('copy')` if Clipboard API is unavailable.
4. Added visible user feedback toast messages for share outcomes (`Alert link shared.`, `Alert link copied.`, `Unable to share this alert.`).
5. Updated/expanded test coverage for:
- share button callback behavior in `AlertDetailsView`
- URL deep-link open/close behavior in `App`
- invalid `alert` query handling
- native share path
- clipboard fallback path

---

## Files Changed

- `resources/js/features/gta-alerts/App.tsx`
- `resources/js/features/gta-alerts/App.test.tsx`
- `resources/js/features/gta-alerts/components/AlertDetailsView.tsx`
- `resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx`
- `docs/tickets/FEED-035-alert-share-link.md`

---

## Verification

### Targeted tests (changed frontend files)

- `vendor/bin/sail pnpm exec vitest run resources/js/features/gta-alerts/components/AlertDetailsView.test.tsx resources/js/features/gta-alerts/App.test.tsx` -> **PASS**

### Full suite

- `vendor/bin/sail composer test` -> **PASS** (`753 passed`, `7 skipped`)

### Required quality gates

- `vendor/bin/sail bin pint` -> **PASS**
- `vendor/bin/sail composer lint` -> **PASS**
- `vendor/bin/sail pnpm run lint` -> **PASS**
- `vendor/bin/sail pnpm run format` -> **PASS**
- `vendor/bin/sail pnpm run types` -> **PASS**

---

## Acceptance Criteria

- [x] The details action label is updated from `Broadcast Alert` to `Share Alert`.
- [x] A selected alert can be reopened from a copied URL containing an alert query parameter.
- [x] Clicking `Share Alert` triggers native share on supported devices.
- [x] Unsupported browsers fall back to copying the alert URL to the clipboard.
- [x] The user receives visible feedback after a successful share or copy action.
- [x] Invalid `alert` query parameters fail safely without breaking feed navigation.
- [x] No social-media-specific integrations are introduced.
