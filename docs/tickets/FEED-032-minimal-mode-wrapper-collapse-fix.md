# FEED-032: Minimal Mode Wrapper Collapse Regression Fix

## Issue Type
Bug Fix

## Priority
P2

## Status
Closed

## Summary
In minimal mode, the status and category rows were visually hidden only at the inner-content level. Their outer wrappers still retained padding and border, leaving empty sticky header space on mobile.

## Source Finding
- [P2] Collapse the full status/category rows when hidden — `resources/js/features/gta-alerts/components/FeedView.tsx:187-190`

## Root Cause
Hidden-state classes (`h-0`, `overflow-hidden`, `py-0`, opacity transition) were applied to inner containers, while row wrappers kept fixed `py-*` and `border-b` classes.

## Data Contract Check (Laravel -> Inertia -> React)
- No data shape changes.
- Fix is presentation-only inside `FeedView.tsx` class handling.

## Changes Applied
1. Updated `#gta-alerts-feed-status-row` wrapper to apply hide/show transition classes directly at wrapper level.
2. Updated `#gta-alerts-feed-category-row` wrapper to apply hide/show transition classes directly at wrapper level.
3. Removed redundant hide/show transition logic from the inner status/category content wrappers.
4. Added test coverage validating wrapper collapse behavior in minimal mode.

## Files Changed
- `resources/js/features/gta-alerts/components/FeedView.tsx`
- `resources/js/features/gta-alerts/components/FeedView.test.tsx`

## Verification
### Targeted Test (changed frontend component)
- `vendor/bin/sail pnpm exec vitest run resources/js/features/gta-alerts/components/FeedView.test.tsx --pool=forks`
- Result: PASS (`1 file`, `16 tests`)

### Full Suite
- `vendor/bin/sail composer test`
- Result: PASS (`753 passed`, `7 skipped`)

### Required Formatting / Lint / Type Checks
- `vendor/bin/sail bin pint` -> PASS
- `vendor/bin/sail composer lint` -> PASS
- `vendor/bin/sail pnpm run lint` -> PASS
- `vendor/bin/sail pnpm run format` -> PASS
- `vendor/bin/sail pnpm run types` -> PASS

## Acceptance Criteria Check
- All findings resolved and verified by tests: ✅
- Full test suite passes (`composer test`): ✅
- No new lint, format, or TypeScript errors: ✅
- No unintended behavior changes outside cited finding scope: ✅

## Closure
Resolved and verified. Ticket is closed.
