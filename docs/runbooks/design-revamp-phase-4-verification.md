# Design Revamp Phase 4 Verification Runbook

This runbook defines the verification workflow for the UI Design Revamp (Prototype Two) track.

## Target Runtime

- Local URL: `http://localhost:8080/`
- Preferred browser path: Playwright MCP
- Fallback path: Playwright CLI
- CI-enforced contract spec: `tests/e2e/design-revamp-phase-4.spec.ts`

## Verification Command Strategy

Run from project root:

```bash
pnpm run format:check
pnpm run lint:check
pnpm run types
pnpm run test
pnpm run quality:check
./vendor/bin/sail artisan test
```

Recommended order:
1. `pnpm run format:check`
2. `pnpm run lint:check`
3. `pnpm run types`
4. `pnpm run test`
5. `./vendor/bin/sail artisan test`
6. `pnpm run quality:check` (aggregate confirmation)

## Browser Verification Scope

Validate at desktop and mobile breakpoints:
- Sidebar/header/footer parity
- Feed/Table client-side toggle behavior
- Expand/collapse summary behavior in table mode
- Alert details navigation and return flow
- Drawer + bottom-nav coexistence on mobile
- Refresh FAB placement and non-overlap

Reference artifacts and findings:
- `docs/tickets/FEED-016-design-revamp-phase-4-verification-findings.md`
- `docs/tickets/FEED-017-design-revamp-phase-4-quality-gate-failures.md`
- `artifacts/playwright/design-revamp-phase4-20260305-*`

## Troubleshooting

### `pnpm run format:check` fails
- Cause: Prettier drift in `resources/` files.
- Action: run `pnpm run format` and rerun checks.

### `pnpm run types` fails with TS2307 in `settings/password.tsx`
- Symptom: cannot resolve `@/actions/App/Http/Controllers/Settings/PasswordController`.
- Action: regenerate or restore the missing typed action module path, then rerun `pnpm run types`.

### `pnpm run quality:check` fails immediately
- Cause: aggregate script stops on first failing gate (usually format or types).
- Action: fix the first failing gate and rerun.

### `./vendor/bin/sail artisan test` cannot run
- Cause: Sail/Docker runtime unavailable.
- Action: start Docker and run `./vendor/bin/sail up -d` before rerunning backend tests.
