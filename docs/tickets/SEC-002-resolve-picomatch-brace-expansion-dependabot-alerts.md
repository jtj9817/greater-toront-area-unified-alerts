# SEC-002: Resolve Open Dependabot Vulnerabilities in `flatted`, `picomatch`, and `brace-expansion`

## Meta
- **Type:** Bug / Security
- **Severity:** High + Moderate (combined)
- **Component:** Frontend Dependencies (`pnpm-lock.yaml`)
- **Status:** Closed

## Summary
Security scan on 2026-03-28 (`vendor/bin/sail pnpm audit --audit-level low`) reports 7 open vulnerabilities in transitive npm dependencies locked in `pnpm-lock.yaml`:

- `flatted` prototype pollution via `parse()` (high)
- `picomatch` ReDoS + method-injection advisories (high + moderate)
- `brace-expansion` zero-step sequence process hang / memory exhaustion (moderate; two affected major ranges)

This ticket defines the implementation and verification plan to remove all currently vulnerable versions from the dependency graph with scoped overrides, then validate there is no regression.

## Vulnerability Snapshot
- **Package:** `flatted`
  - **Observed vulnerable lock version:** `3.4.1`
  - **Minimum patched version:** `>=3.4.2`
  - **Introduced transitively via:** `eslint` → `file-entry-cache` → `flat-cache`.
- **Package:** `picomatch`
  - **Observed vulnerable lock versions:** `2.3.1`, `4.0.3`
  - **Minimum patched versions:** `>=2.3.2` for v2 range; `>=4.0.4` for v4 range.
  - **Introduced transitively via:** `vite-plugin-full-reload`, `vite`, `vitest`, `@tailwindcss/vite`, `@vitejs/plugin-react`, `typescript-eslint`.
- **Package:** `brace-expansion`
  - **Observed vulnerable lock versions:** `1.1.12`, `5.0.4`
  - **Minimum patched versions:** `>=1.1.13` for v1 range; `>=5.0.5` for v5 range.
  - **Introduced transitively via:** `eslint` (via `minimatch@3`) and `typescript-eslint`/`eslint-plugin-import` (via `minimatch@10`).

## Priority Order
- **P1:** `flatted` high severity (`3.4.1` → `>=3.4.2`)
- **P2:** `picomatch` high/moderate advisories (`2.3.1` and `4.0.3`)
- **P3:** `brace-expansion` moderate advisories (`1.1.12` and `5.0.4`)

## Implementation Plan
1. **Map current graph**
   - Run `pnpm why flatted`, `pnpm why picomatch`, and `pnpm why brace-expansion` to confirm all transitive introduction paths.
2. **Select update strategy**
   - Use scoped `pnpm.overrides` to avoid cross-major forced upgrades that could break dependents.
   - Apply fixes in priority order:
     - `flatted`: `"flatted": ">=3.4.2"`
     - `picomatch`: `"vite-plugin-full-reload@1.2.0>picomatch": "2.3.2"` and `"picomatch": ">=4.0.4"`
     - `brace-expansion`: `"minimatch@3.1.5>brace-expansion": "1.1.13"` and `"minimatch@10.2.4>brace-expansion": "5.0.5"`
3. **Regenerate lockfile**
   - Run `vendor/bin/sail pnpm install` to apply resolution and update `pnpm-lock.yaml`.
4. **Verify resolved versions**
   - Re-run `vendor/bin/sail pnpm why flatted`, `vendor/bin/sail pnpm why picomatch`, and `vendor/bin/sail pnpm why brace-expansion`.
   - Confirm vulnerable versions no longer appear in resolver output.
5. **Regression validation**
   - Run targeted checks first, then broader quality gates:
     - `vendor/bin/sail pnpm run lint`
     - `vendor/bin/sail pnpm run build`
     - `vendor/bin/sail artisan test --compact`
6. **Security re-check**
   - Run `vendor/bin/sail pnpm audit --audit-level low` (and Dependabot re-scan) and confirm alerts are closed for all three packages.

## Resolution (2026-03-28)
- Applied scoped `pnpm` overrides in `package.json`:
  - `"flatted@3.4.1": "3.4.2"`
  - `"picomatch@2.3.1": "2.3.2"`
  - `"picomatch@4.0.3": "4.0.4"`
  - `"brace-expansion@1.1.12": "1.1.13"`
  - `"brace-expansion@5.0.4": "5.0.5"`
- Regenerated lockfile with `vendor/bin/sail pnpm install --lockfile-only`.
- Verified scanner output: `vendor/bin/sail pnpm audit --audit-level low` reports **No known vulnerabilities found**.
- Validation commands executed:
  - `vendor/bin/sail pnpm run test resources/js/features/gta-alerts/services/AlertService.test.ts`
  - `vendor/bin/sail pnpm run build`
  - `vendor/bin/sail composer test`
  - `vendor/bin/sail composer lint`
  - `vendor/bin/sail pnpm run lint`
  - `vendor/bin/sail pnpm run format`
  - `vendor/bin/sail pnpm run types`

## Acceptance Criteria
- [x] No vulnerable `flatted` versions remain in `pnpm-lock.yaml`.
- [x] No vulnerable `picomatch` versions remain in `pnpm-lock.yaml`.
- [x] No vulnerable `brace-expansion` versions remain in `pnpm-lock.yaml`.
- [x] Dependency changes are minimal and scoped to security remediation.
- [x] Lint, build, and targeted test gates pass after updates.
- [ ] Dependabot alerts for these issues are closed (pending GitHub re-scan after push).

## Out of Scope
- Broader dependency modernization unrelated to these vulnerabilities.
- Non-security refactors in application code.

## Notes
- All findings are currently transitive lockfile issues; remediation may be completed without application source changes.
- If direct package upgrades conflict with current toolchain constraints, use `pnpm.overrides` as an explicit short-term control and track cleanup in a follow-up ticket.
