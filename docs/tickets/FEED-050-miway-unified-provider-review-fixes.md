# FEED-050: MiWay Unified Provider Review Fixes

## Meta
- **Issue Type:** Bug
- **Priority:** P1
- **Status:** Closed
- **Labels:** `alerts`, `miway`, `unified-alerts`, `mysql`
- **Related Commit Under Review:** `32aa162`

## Summary
Resolved the two review findings in strict priority order:
1. **P1:** MiWay source was emitted by the provider but rejected by canonical source validation.
2. **P2:** MySQL/MariaDB MiWay text search used `MATCH ... AGAINST` without a guaranteed FULLTEXT index.

## Findings (Priority Order)

### P1 — Ensure MiWay source is recognized by AlertSource validation
**Finding:** `MiwayAlertSelectProvider` emits `source = 'miway'`, but canonical source validation excluded `miway`, causing `AlertId::fromParts(...)` and source filtering paths to fail for MiWay records.

**Resolution applied:**
- Added `AlertSource::Miway = 'miway'` to the shared source contract.
- Added/updated focused tests proving `miway` is valid in:
  - source validation
  - criteria normalization
  - alert ID construction
  - unified row mapping

**Files changed:**
- `app/Enums/AlertSource.php`
- `tests/Unit/Enums/AlertSourceTest.php`
- `tests/Unit/Services/Alerts/UnifiedAlertsCriteriaTest.php`
- `tests/Unit/Services/Alerts/AlertIdTest.php`
- `tests/Unit/Services/Alerts/Mappers/UnifiedAlertMapperTest.php`

### P2 — Avoid MATCH query path without a MiWay FULLTEXT index
**Finding:** MySQL/MariaDB MiWay query path includes `MATCH(header_text, description_text) AGAINST (...)` with no migration guaranteeing the required FULLTEXT index.

**Resolution applied:**
- Added a dedicated migration that creates/drops a MySQL/MariaDB FULLTEXT index:
  - index name: `miway_alerts_fulltext`
  - columns: `header_text`, `description_text`
- Added schema-level test coverage (driver-gated) to assert the index exists on MySQL/MariaDB.

**Files changed:**
- `database/migrations/2026_03_31_082123_add_fulltext_index_to_miway_alerts_table.php`
- `tests/Unit/Models/MiwayAlertTest.php`

## Data Contract Check (Laravel -> Inertia -> React)
A boundary shape changed: backend canonical source values now include `miway`.

**PHP contract side updated:**
- `AlertSource` enum includes `miway`.
- API source parameter docs updated to include `miway`.

**TypeScript contract side updated:**
- `UnifiedAlertResourceSchema.source` enum now includes `miway`.

**Files changed:**
- `app/Http/Controllers/Api/FeedController.php`
- `resources/js/features/gta-alerts/domain/alerts/resource.ts`

## Verification
### Targeted tests (changed areas first)
- `vendor/bin/sail artisan test --compact tests/Unit/Enums/AlertSourceTest.php tests/Unit/Services/Alerts/UnifiedAlertsCriteriaTest.php tests/Unit/Services/Alerts/AlertIdTest.php tests/Unit/Services/Alerts/Mappers/UnifiedAlertMapperTest.php tests/Unit/Models/MiwayAlertTest.php`
- Result: **53 passed, 1 skipped**

- `vendor/bin/sail pnpm test -- resources/js/features/gta-alerts/domain/alerts/fromResource.contract.test.ts`
- Result: **pass**

### Full suite
- `vendor/bin/sail composer test`
- Result: **826 passed, 8 skipped**

### Formatting / lint / types
- `vendor/bin/sail bin pint --dirty --format agent`
- `vendor/bin/sail composer lint`
- `vendor/bin/sail pnpm run lint`
- `vendor/bin/sail pnpm run format`
- `vendor/bin/sail pnpm run types`
- Result: **all pass**

## Acceptance Criteria Check
- [x] All findings are resolved and verified by tests.
- [x] Full test suite passes (`composer test`).
- [x] No new lint, format, or TypeScript errors.
- [x] No unintended behavior changes outside the cited findings.

## Closure
Ticket is closed.

These fixes are part of **Phase 5: Unified Alerts Provider**.
