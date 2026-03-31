# FEED-051: MiWay Alert Presentation Gaps

## Meta
- **Issue Type:** Bug
- **Priority:** P1
- **Status:** To Do
- **Labels:** `alerts`, `miway`, `frontend`, `regression`
- **Source:** Code review findings

## Summary
MiWay alerts are now mapped into the UI domain model, but downstream presentation components still assume the pre-MiWay alert-kind set. This causes:
1. A render-time crash when opening MiWay alert details.
2. Missing source labels for MiWay alerts in card and table list views.

## Findings (Priority Order)

### P1 — MiWay details view can crash at render time
**Affected files:**
- `resources/js/features/gta-alerts/domain/alerts/view/mapDomainAlertToPresentation.ts:81`
- `resources/js/features/gta-alerts/components/AlertDetailsView.tsx:520`

**Problem:**
- `mapDomainAlertToPresentation` includes a `miway` mapping, so MiWay alerts are reachable from the UI.
- `AlertDetailsView` still switches only on `fire`, `police`, `transit`, and `go_transit`.
- For `alert.kind === 'miway'`, the `sections` value is `undefined` and is passed into `AlertDetailsLayout`, which dereferences `sections.header`.

**User impact:**
- Opening a MiWay alert details page can throw and break the details render path.

**Required fix direction:**
- Add an explicit `miway` branch in `AlertDetailsView` section construction, or add a safe fallback that guarantees `sections` is always defined before rendering `AlertDetailsLayout`.

---

### P2 — MiWay source labels are blank in list views
**Affected files:**
- `resources/js/features/gta-alerts/domain/alerts/fromResource.ts:46`
- `resources/js/features/gta-alerts/components/AlertCard.tsx:23`
- `resources/js/features/gta-alerts/components/AlertTableView.tsx:24`

**Problem:**
- `fromResource` now emits MiWay domain alerts.
- `getSourceLabel` helpers in `AlertCard` and `AlertTableView` do not include a `miway` case.
- Helpers return `undefined` for MiWay rows/cards, producing empty source labels in rendered UI.

**User impact:**
- Source metadata is missing for MiWay alerts in both card and table presentations.

**Required fix direction:**
- Add `miway` handling in both source-label helpers (for example, returning `MiWay`).

## Acceptance Criteria
- [ ] Opening details for a MiWay alert does not throw.
- [ ] `AlertDetailsView` always provides a defined `sections` payload to `AlertDetailsLayout`.
- [ ] MiWay alerts display a non-empty source label in `AlertCard`.
- [ ] MiWay alerts display a non-empty source label in `AlertTableView`.
- [ ] Existing alert kinds (`fire`, `police`, `transit`, `go_transit`) keep current behavior.

## Notes
This ticket captures findings only and is intentionally scoped to runtime presentation correctness and metadata display consistency.
