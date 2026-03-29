# FEED-045: Bugfix — Feels Like Row Hidden When Algorithm Returns Null

## Summary

The "Feels Like" row in the footer weather detail panel is omitted entirely when `FeelsLikeCalculator` returns `null` (the neutral 10–20 °C range where neither Wind Chill nor Humidex applies). The row should always be visible when weather data is present, falling back to the actual temperature when no adjustment formula applies.

## Component

- GTA Alerts Frontend (Footer weather detail panel)
- Backend `FeelsLikeCalculator` (neutral-range fallback)

## Linked Issues

- Relates to: [FEED-042 — Feels Like Algorithm + Weather Detail Panel](FEED-042-feels-like-weather-detail-panel.md)

## Findings

### P2 — Feels Like row disappears in the 10–20 °C neutral range

**Reviewer reference:** `resources/js/features/gta-alerts/components/Footer.tsx:38-42`

**Issue:** `buildDetailRows()` passes `null` as the value when `weather.feelsLike` is `null`, and the `add()` helper silently skips null rows. In the neutral temperature range (10 < T < 20 °C), `FeelsLikeCalculator::compute()` returns `null` because neither Wind Chill nor Humidex applies. This causes the "Feels Like" row to vanish from the detail panel entirely, leaving "Dewpoint" or "Condition" as the first row.

**Risk:** Users expect to see a "Feels Like" temperature at the top of the detail panel whenever weather data is available. Its absence in moderate temperatures is confusing and breaks the visual contract established when it does appear.

**Fix:**

- Update `FeelsLikeCalculator::compute()` to fall back to the actual temperature when neither Wind Chill nor Humidex applies, instead of returning `null`. In the 10–20 °C range with no wind-chill or humidex adjustment, the perceived temperature equals the actual temperature.
- The frontend `buildDetailRows()` does **not** need changes — once `feelsLike` is always non-null (when `temperature` is available), the existing `add()` call will always include the row.

**Behaviour change:**

| Condition                           | Before              | After                                |
| ----------------------------------- | ------------------- | ------------------------------------ |
| T ≤ 10 °C, W > 4.8 km/h             | Wind Chill value    | Wind Chill value (unchanged)         |
| T ≥ 20 °C, dewpoint available       | Humidex value       | Humidex value (unchanged)            |
| 10 < T < 20 °C                      | `null` → row hidden | Actual temperature → row shown       |
| T ≤ 10 °C, W ≤ 4.8 km/h (calm cold) | `null` → row hidden | Actual temperature → row shown       |
| T ≥ 20 °C, dewpoint unavailable     | `null` → row hidden | Actual temperature → row shown       |
| T is `null`                         | `null`              | `null` (unchanged — no weather data) |

**Files:**

- `app/Services/Weather/FeelsLikeCalculator.php`
- `tests/Unit/Services/Weather/FeelsLikeCalculatorTest.php`
- `resources/js/features/gta-alerts/components/Footer.test.tsx`

## Acceptance Criteria

- [x] `FeelsLikeCalculator::compute()` returns the actual temperature (rounded to 1 decimal) when neither Wind Chill nor Humidex applies but `temperature` is available.
- [x] `FeelsLikeCalculator::compute()` still returns `null` when `temperature` is `null`.
- [x] Wind Chill and Humidex formulas and thresholds remain unchanged.
- [x] "Feels Like" row is always the first row in the detail panel when any weather data is available.
- [x] Existing provider integration tests still pass (Humidex and Wind Chill values unchanged).
- [x] Frontend Footer test confirms "Feels Like" appears even when no formula applies.
- [x] All existing tests continue to pass.

## Verification

- `vendor/bin/sail artisan test --compact --filter=FeelsLike` → **PASS** (15 tests, 35 assertions)
- `vendor/bin/sail artisan test --compact --filter=EnvironmentCanada` → **PASS** (26 tests, 69 assertions)
- `vendor/bin/sail pnpm exec vitest run resources/js/features/gta-alerts/components/Footer.test.tsx` → **PASS** (22 tests)
- `vendor/bin/sail composer test` → **PASS** (776 passed, 7 skipped, 3722 assertions)
- `vendor/bin/sail bin pint --dirty` → **PASS** (0 files)
- `vendor/bin/sail pnpm run lint` → **PASS**
- `vendor/bin/sail pnpm run format` → **PASS**
- `vendor/bin/sail pnpm run types` → **PASS**

## Status

**COMPLETED** - All fixes applied and verified.
