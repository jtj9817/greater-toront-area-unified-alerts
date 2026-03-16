# SEC-001: Resolve High Severity DoS Vulnerability in `flatted` Dependency

## Meta
- **Type:** Bug / Security
- **Severity:** High
- **Component:** Dependencies
- **Status:** Closed

## Description
A High severity vulnerability (GHSA-25h7-pfq9-p65f / CVE-2026-32141) was detected in the `flatted` package within the `pnpm-lock.yaml` lockfile. 

The `parse()` function in the `flatted` package used a recursive `revive()` phase to resolve circular references in deserialized JSON. When given a crafted payload with deeply nested or self-referential `$` indices, the recursion depth was unbounded, causing a stack overflow that crashed the Node.js process.

**Current Version:** 3.4.1 (Updated from 3.3.4)
**Minimal Fix Version:** 3.4.0

## Impact
Denial of Service (DoS). Any application that passes untrusted input to `flatted.parse()` could be crashed by an unauthenticated attacker with a single request. `flatted` is frequently used as the circular-JSON serialization layer in many caching and logging libraries.

## Acceptance Criteria
- [x] Investigate dependency tree using `pnpm why flatted` to determine if it is a direct or transitive dependency.
  - Result: Transitive dependency via `eslint` -> `file-entry-cache` -> `flat-cache`.
- [x] Update dependency:
  - Applied a `pnpm` override to force `flatted` to `>=3.4.0`.
- [x] Run `pnpm install` to update `pnpm-lock.yaml`.
  - Result: Updated to `flatted@3.4.1`.
- [x] Run full test suite to verify the update did not introduce regressions.
  - Result: Backend tests passed with Sail. Frontend tests passed (individual verification to avoid OOM).
- [x] Security scan passes with no known vulnerabilities for `flatted`.
  - Result: `pnpm audit` reports no known vulnerabilities.

## Technical Notes
Transitive update was required. Applied the following override in `package.json`:
```json
"pnpm": {
  "overrides": {
    "flatted": ">=3.4.0"
  }
}
```