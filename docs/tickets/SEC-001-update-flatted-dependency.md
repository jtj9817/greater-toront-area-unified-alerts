# SEC-001: Resolve High Severity DoS Vulnerability in `flatted` Dependency

## Meta
- **Type:** Bug / Security
- **Severity:** High
- **Component:** Dependencies
- **Status:** Open

## Description
A High severity vulnerability (GHSA-25h7-pfq9-p65f / CVE-2026-32141) has been detected in the `flatted` package within the `pnpm-lock.yaml` lockfile. 

The `parse()` function in the `flatted` package uses a recursive `revive()` phase to resolve circular references in deserialized JSON. When given a crafted payload with deeply nested or self-referential `$` indices, the recursion depth is unbounded, causing a stack overflow that crashes the Node.js process.

**Current Version:** 3.3.4
**Minimal Fix Version:** 3.4.0

## Impact
Denial of Service (DoS). Any application that passes untrusted input to `flatted.parse()` can be crashed by an unauthenticated attacker with a single request. `flatted` is frequently used as the circular-JSON serialization layer in many caching and logging libraries.

## Acceptance Criteria
- [ ] Investigate dependency tree using `pnpm why flatted` to determine if it is a direct or transitive dependency.
- [ ] Update dependency:
  - If direct: Update `flatted` version to `>=3.4.0` in `package.json`.
  - If transitive: Update the parent dependency, or apply a `pnpm` override to force `flatted` to `>=3.4.0`.
- [ ] Run `pnpm install` to update `pnpm-lock.yaml`.
- [ ] Run full test suite to verify the update did not introduce regressions.
- [ ] Security scan passes with no known vulnerabilities for `flatted`.

## Technical Notes
If a transitive update is required and the parent package has not released a fix, use the following override in `package.json`:
```json
"pnpm": {
  "overrides": {
    "flatted": ">=3.4.0"
  }
}
```