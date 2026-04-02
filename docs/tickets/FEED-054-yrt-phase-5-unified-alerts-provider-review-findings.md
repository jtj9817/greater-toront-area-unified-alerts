# FEED-054: YRT Phase 5 Unified Alerts Provider Review Findings (d2236fac)

## Meta
- **Issue Type:** Bug
- **Priority:** Mixed (`P1`/`P3`)
- **Status:** Open
- **Labels:** `alerts`, `yrt`, `unified-alerts`, `review`, `backend`, `criteria`
- **Reviewed Commit:** `d2236fac300967c0054f587f5104515d311b7d13`

## Summary
`YrtAlertSelectProvider` correctly implements the unified select contract (driver-safe `id`/`meta` expressions, criteria pushdown for `source`/`status`/`since`/`query`, and provider tagging). Unit coverage is strong and consistent with the other unified providers.

This review found one high-severity contract mismatch (`yrt` provider exists, but canonical source validation rejects `yrt`) plus a couple of low-severity consistency improvements.

## Findings (Priority Order)

### P1 — `yrt` provider is not recognized by canonical source validation
**Finding:**
- `YrtAlertSelectProvider` emits `source = 'yrt'`, but `AlertSource` does not define `yrt`.
- `UnifiedAlertsCriteria::normalizeSource()` rejects sources not present in `AlertSource`, so `source=yrt` filtering will throw an exception.

**Impact:**
- UI/API source filtering for YRT cannot be enabled safely (criteria normalization will fail).
- The unified “source contract” becomes inconsistent: providers can emit values that the criteria layer refuses to accept.

**Required fix direction:**
- Add `AlertSource::Yrt = 'yrt'`.
- Add/update focused unit coverage proving `yrt` is valid in:
  - `AlertSource` values/validation
  - `UnifiedAlertsCriteria` normalization for `source`
  - any existing TS/shared contract enums for `source` (if present in this phase)

---

### P3 — Prefer the shared `AlertSource` enum in `YrtAlertSelectProvider::source()`
**Finding:**
- `YrtAlertSelectProvider::source()` returns a literal `'yrt'` while other providers typically delegate to `AlertSource`.

**Impact:**
- Minor: increases the chance of a future drift between enum values and provider output.

**Required fix direction:**
- After adding `AlertSource::Yrt`, update the provider to return `AlertSource::Yrt->value`.

---

### P3 — Align `YrtAlertFactory` `external_id` default with provider expectations
**Finding:**
- The unit tests for the unified provider treat `yrt_alerts.external_id` as a bare identifier (example: `a1234`).
- `YrtAlertFactory` currently defaults `external_id` to a prefixed value like `yrt:12345`, which can lead to confusing unified IDs like `yrt:yrt:12345` in tests/fixtures and makes it harder to reason about what is “source” vs “external id”.

**Impact:**
- Low today (factory only), but creates inconsistent fixtures and can mask bugs in mapping/ID composition.

**Required fix direction:**
- Update the factory default to generate a bare external id (no `yrt:` prefix) consistent with the provider tests and feed storage intent.

## Acceptance Criteria
- [ ] `AlertSource` includes `yrt` and criteria normalization accepts `source=yrt`.
- [ ] Provider emits `AlertSource::Yrt->value` (no magic string).
- [ ] `YrtAlertFactory` default `external_id` is unprefixed and consistent with mapping expectations.
- [ ] Targeted tests pass for enum/criteria/provider mapping.

