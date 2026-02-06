# Project Workflow (Laravel/Sail Edition)

## Guiding Principles

1. **The Plan is the Source of Truth:** All work must be tracked in `plan.md`
2. **The Tech Stack is Deliberate:** Changes to the tech stack must be documented in `tech-stack.md` *before* implementation
3. **Test-Driven Development:** Write unit tests before implementing functionality
4. **High Code Coverage:** Aim for >90% code coverage for all modules
5. **User Experience First:** Every decision should prioritize user experience
6. **Security & Resilience First:** All external inputs are untrusted; all external integrations must have failure-mode tests.
7. **Non-Interactive & CI-Aware:** Prefer non-interactive commands. Use `CI=true` for watch-mode tools.

## Task Workflow

All tasks follow a strict lifecycle:

### Track Structure Requirements

Every implementation track must conclude with a final **Quality & Documentation** phase. This phase ensures the track meets the project's long-term maintenance standards before archiving. It must include:

1. **Coverage Verification:** Execute `./vendor/bin/sail artisan test --coverage` to ensure that the track's specific modules meet or exceed the **>90% coverage** threshold.
2. **Documentation Update:** Update all relevant project documentation, including technical docs in `docs/`, the `README.md`, and `CLAUDE.md` to reflect new features, commands, or architectural changes.
3. **Registry Maintenance:** Once verified, the track must be moved to the archive and its status updated in the **Tracks Registry** (`conductor/tracks.md`).

### Standard Task Workflow

1. **Select Task:** Choose the next available task from `plan.md` in sequential order.
2. **Mark In Progress:** Edit `plan.md` and change status from `[ ]` to `[~]`.
3. **Check for Migrations:** If database changes are needed, run `./vendor/bin/sail artisan make:migration ...`.
4. **Write Failing Tests (Red Phase):**
   - Create a new Pest test file (e.g., `tests/Feature/MyFeatureTest.php`).
   - Write tests defining expected behavior, including **failure cases**.
   - **CRITICAL:** Run `./vendor/bin/sail artisan test --filter MyFeatureTest` and confirm failure.
5. **Implement to Pass Tests (Green Phase):**
   - Write minimum code to make tests pass.
   - Run tests again to confirm success.
6. **Refactor (Optional but Recommended):**
   - Improve code clarity/performance while keeping tests green.
7. **Verify Coverage:** 
   ```bash
   ./vendor/bin/sail artisan test --coverage --min=90
   ```
8. **Document Deviations:** Update `tech-stack.md` if implementation differs from the original design.
9. **Commit Code Changes:**
   - Stage changes. Perform commit: `git commit -m "feat(scope): descriptive message"`.
10. **Attach Task Summary with Git Notes:**
    - Get hash: `git log -1 --format="%H"`.
    - Attach note: `git notes add -m "<Task: Name>\n<Summary: Changes>\n<Why: Logic>" <commit_hash>`.
11. **Record Task Commit SHA:** Update `plan.md` status to `[x]` with the 7-char SHA.
12. **Commit Plan Update:** `git commit -m "conductor(plan): Mark task '...' as complete"`.

### Phase Completion Verification and Checkpointing Protocol

1. **Announce Protocol Start:** Inform the user verification has begun.
2. **Ensure Test Coverage:** Verify corresponding test files exist for all changed files (`git diff --name-only <prev_sha> HEAD`).
3. **Execute Automated Tests with Proactive Debugging:**
   - Command: `CI=true ./vendor/bin/sail artisan test`
   - If tests fail, debug (max 2 attempts) then ask user.
4. **Propose Manual Verification Plan:** Generate step-by-step instructions using `sail` commands and expected browser outcomes.
5. **Await Explicit User Feedback:** PAUSE for a "yes" or feedback.
6. **Create Checkpoint Commit:** `git commit -m "conductor(checkpoint): End of Phase X"`.
7. **Attach Verification Report:** Use `git notes` to link the full verification log to the checkpoint.
8. **Record Checkpoint SHA:** Update `plan.md` heading with `[checkpoint: <sha>]`.

## Quality Gates

Before marking a task or phase complete:
- [ ] **Tests:** All Pest tests pass (`./vendor/bin/sail artisan test`).
- [ ] **Coverage:** Coverage meets or exceeds 90%.
- [ ] **Security:** Dependency audit passes (`./vendor/bin/sail composer audit` & `pnpm audit`).
- [ ] **Linting:** Code passes Pint (`./vendor/bin/sail artisan pint --test`).
- [ ] **Static Analysis:** TypeScript (`pnpm types`) and PHP type hints are valid.
- [ ] **Resilience:** Failure modes for external feeds are tested.

## Testing Requirements

### Unit & Feature Testing (Pest)
- Mock all external HTTP requests (Toronto Fire CAD, etc.) using `Http::fake()`.
- Test successful data ingestion AND malformed/empty response handling.
- Verify database state after service execution.

### Resilience Testing
- **Source Down:** How does the app behave if a feed is unreachable?
- **Invalid Data:** How does the app behave if the XML is corrupted?
- **Concurrency:** Ensure migrations and seeds work in isolated test environments.

## Code Review Process (Self-Review Checklist)

1. **Security:** No secrets in code, inputs sanitized via Laravel validation, no XSS in React.
2. **Performance:** No N+1 queries in Eloquent, efficient indexing in MySQL.
3. **Mobile:** UI is responsive; touch targets are adequate on mobile layouts.
4. **Consistency:** Follows patterns in `conductor/code_styleguides/`.

## Emergency & Deployment

### Deployment Steps (Sail/Prod)
1. Run all tests: `./vendor/bin/sail artisan test`.
2. Run security audit: `./vendor/bin/sail composer audit`.
3. Build assets: `npm run build`.
4. Run migrations: `php artisan migrate --force`.

### Emergency (Hotfix)
1. Branch from `main`.
2. Write a failing test that reproduces the bug.
3. Fix, verify, and merge immediately.

## Development Commands (Sail)
- **Start:** `./vendor/bin/sail up -d`
- **Test:** `./vendor/bin/sail artisan test`
- **Lint:** `./vendor/bin/sail artisan pint`
- **Database:** `./vendor/bin/sail artisan migrate`
