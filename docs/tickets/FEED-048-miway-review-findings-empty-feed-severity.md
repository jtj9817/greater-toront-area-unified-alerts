# FEED-048: Review Fixes — MiWay Empty-Feed Deactivation + Severity Mapping

## Summary

Resolves review findings for the MiWay GTFS-RT integration by ensuring stale alerts deactivate even when the feed returns an empty-but-valid alert list, and by mapping GTFS-RT `effect` values to meaningful notification severities (instead of always falling back to `minor`).

These fixes are part of **Phase 3: Fetch Command (Sync + Notifications)**.

## Component

- Backend console sync — `miway:fetch-alerts`
- Backend notifications — `NotificationAlertFactory`

---

## Findings

### P0 — Pint formatting/lint issues (indentation + unused import)

**Reviewed files:**

- `app/Services/Notifications/NotificationAlertFactory.php:110`
- `app/Console/Commands/FetchMiwayAlertsCommand.php:1`

**Finding:** The reported indentation drift and unused `Carbon` import are **not present** in the current workspace state.

**Resolution:** No code change required for these two P0 items; validation relies on `composer test` (Pint `--test`) and `composer lint` gates.

---

### P1 — Empty-but-valid feed does not deactivate stale alerts

**File:** `app/Console/Commands/FetchMiwayAlertsCommand.php:63`

**Issue:** Stale deactivation previously ran only when `$syncedIds` was non-empty, leaving older `miway_alerts.is_active = true` rows incorrectly active if the feed returned a successful empty alert list (allowed by `feeds.allow_empty_feeds=true`).

**Fix:** Always run the deactivation update after syncing, and when the feed contains zero alerts, deactivate **all** currently-active MiWay alerts.

**Test coverage:** `tests/Feature/Commands/FetchMiwayAlertsCommandTest.php:186`

---

### P2 — MiWay severity incorrectly derived from GTFS-RT `effect`

**File:** `app/Services/Notifications/NotificationAlertFactory.php:110`

**Issue:** `fromMiwayAlert()` previously passed GTFS-RT `effect` values (e.g. `NO_SERVICE`, `SIGNIFICANT_DELAYS`, `DETOUR`) into `mapTransitSeverity()`, which only recognizes strings containing `critical`, `major`, or `severe`. This caused MiWay alerts to deterministically fall back to `minor`.

**Fix:** Add an explicit `effect → severity` mapping for MiWay alerts:

- `NO_SERVICE` → `critical`
- `REDUCED_SERVICE`, `SIGNIFICANT_DELAYS`, `DETOUR` → `major`
- all other/unknown values → `minor`

**Test coverage:** `tests/Unit/Services/Notifications/NotificationAlertFactoryTest.php:10`

---

## Verification

- `vendor/bin/sail artisan test --compact tests/Feature/Commands/FetchMiwayAlertsCommandTest.php`
- `vendor/bin/sail artisan test --compact tests/Unit/Services/Notifications/NotificationAlertFactoryTest.php`
- `vendor/bin/sail composer test`
- `vendor/bin/sail bin pint --format agent`
- `vendor/bin/sail composer lint`
- `vendor/bin/sail pnpm run lint`
- `vendor/bin/sail pnpm run format`
- `vendor/bin/sail pnpm run types`

## Acceptance Criteria

- [x] MiWay alerts deactivate correctly when the feed returns zero alerts.
- [x] MiWay GTFS-RT `effect` values map to non-trivial severities (not always `minor`).
- [x] All targeted tests pass.
- [x] Full test suite passes (`composer test`).
- [x] No new Pint, ESLint, Prettier, or TypeScript errors.

## Status

**CLOSED**

