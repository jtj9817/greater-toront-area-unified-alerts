# SEC-002: Resolve Open Dependabot Vulnerabilities in `picomatch` and `brace-expansion`

## Meta
- **Type:** Bug / Security
- **Severity:** High + Moderate (combined)
- **Component:** Frontend Dependencies (`pnpm-lock.yaml`)
- **Status:** Open

## Summary
Dependabot is reporting open security vulnerabilities in transitive npm dependencies locked in `pnpm-lock.yaml`:

- `picomatch` ReDoS via extglob quantifiers (4 alerts)
- `brace-expansion` zero-step sequence process hang / memory exhaustion (1 alert)

This ticket defines the implementation plan to remove the vulnerable versions from the dependency graph and verify no regression is introduced.

## Vulnerability Snapshot
- **Package:** `picomatch`
  - **Observed vulnerable lock version:** `4.0.3`
  - **Minimum patched version:** `>=4.0.4`
  - **Introduced transitively via:** `vite`, `vitest`, `@tailwindcss/vite`, `@vitejs/plugin-react`, `typescript-eslint`, and related tooling chain packages.
- **Package:** `brace-expansion`
  - **Observed vulnerable lock version:** `5.0.4`
  - **Minimum patched version:** `>=5.0.5`
  - **Introduced transitively via:** `eslint-import-resolver-typescript`, `eslint-plugin-import`, and `typescript-eslint`.

## Implementation Plan
1. **Map current graph**
   - Run `pnpm why picomatch` and `pnpm why brace-expansion` to confirm all transitive introduction paths.
2. **Select update strategy**
   - Prefer upgrading top-level packages that pull vulnerable transitive versions.
   - If upstream ranges do not resolve quickly, add targeted `pnpm.overrides` entries in `package.json`:
     - `"picomatch": ">=4.0.4"`
     - `"brace-expansion": ">=5.0.5"`
3. **Regenerate lockfile**
   - Run `pnpm install` to apply resolution and update `pnpm-lock.yaml`.
4. **Verify resolved versions**
   - Re-run `pnpm why picomatch` and `pnpm why brace-expansion` and confirm no vulnerable versions remain.
5. **Regression validation**
   - Run lint/build/test gates used in this repo for dependency changes:
     - `vendor/bin/sail pnpm run lint`
     - `vendor/bin/sail pnpm run build`
     - `vendor/bin/sail artisan test --compact`
6. **Security re-check**
   - Run `pnpm audit` (or Dependabot re-scan) and confirm alerts are closed for both packages.

## Acceptance Criteria
- [ ] No vulnerable `picomatch` versions remain in `pnpm-lock.yaml`.
- [ ] No vulnerable `brace-expansion` versions remain in `pnpm-lock.yaml`.
- [ ] Dependency changes are minimal and scoped to security remediation.
- [ ] Lint, build, and targeted test gates pass after updates.
- [ ] Dependabot alerts for these issues are closed.

## Out of Scope
- Broader dependency modernization unrelated to these two vulnerabilities.
- Non-security refactors in application code.

## Notes
- Both findings are currently transitive lockfile issues; remediation may be completed without application source changes.
- If direct package upgrades conflict with current toolchain constraints, use `pnpm.overrides` as an explicit short-term control and track cleanup in a follow-up ticket.
