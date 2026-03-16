# [QA] `composer test` Stability Check (5 Consecutive Runs)

## Issue Type
Task

## Summary
Executed the full `composer test` suite five consecutive times and analyzed all run logs for failures and instability.

## Environment
- Repo: `greater-toronto-area-alerts`
- Date: 2026-03-16
- Command: `composer test`
- Total runs: 5

## Description
A repeat-run validation was requested to identify flaky or failing tests in the full Laravel test workflow (`config:clear` + `pint --test` + `php artisan test`).

## Steps to Reproduce
1. Run `composer test` from repo root.
2. Repeat 5 consecutive times.
3. Capture each run output to dedicated log files.

## Expected Result
- Any unstable or failing test case should be identifiable from run output.

## Actual Result
- No failing test cases were detected in any run.
- All runs exited successfully with code `0`.
- Each run reported: `7 skipped, 630 passed (3098 assertions)`.

## Run Results
| Run | Duration | Result Summary | Exit Code | Log |
|---|---:|---|---:|---|
| 1 | 15.68s | 7 skipped, 630 passed (3098 assertions) | 0 | `docs/tickets/test-runs/composer-test-run-1.log` |
| 2 | 14.08s | 7 skipped, 630 passed (3098 assertions) | 0 | `docs/tickets/test-runs/composer-test-run-2.log` |
| 3 | 13.95s | 7 skipped, 630 passed (3098 assertions) | 0 | `docs/tickets/test-runs/composer-test-run-3.log` |
| 4 | 13.78s | 7 skipped, 630 passed (3098 assertions) | 0 | `docs/tickets/test-runs/composer-test-run-4.log` |
| 5 | 13.84s | 7 skipped, 630 passed (3098 assertions) | 0 | `docs/tickets/test-runs/composer-test-run-5.log` |

## Debug Findings
### Failing Test Cases
- None found across all 5 runs.

### Non-failing WARN/Skipped Cases (Expected, Environment-Gated)
The following test cases are intentionally skipped when the active DB driver does not match:

- `Tests\Feature\UnifiedAlerts\UnifiedAlertsMySqlDriverTest`
  - `mysql fire provider returns formatted location and decodable meta`
  - `mysql police provider returns decodable meta and coordinates`
  - `mysql unified alerts query returns a deterministic mixed feed`
  - `mysql providers use fulltext predicates when q is provided`

- `Tests\Feature\UnifiedAlerts\UnifiedAlertsPgsqlDriverTest`
  - `pgsql feed keeps fire intel_summary as array and intel_last_updated null when no updates exist`
  - `pgsql feed emits iso-8601 offset intel_last_updated when incident updates exist`
  - `pgsql q filtering reduces result set for fire provider without query exceptions`

These skips are driven by explicit `markTestSkipped(...)` guards in:
- `tests/Feature/UnifiedAlerts/UnifiedAlertsMySqlDriverTest.php`
- `tests/Feature/UnifiedAlerts/UnifiedAlertsPgsqlDriverTest.php`

## Conclusion
`composer test` is currently stable in this environment. No failing or flaky test cases were reproduced in 5 consecutive full-suite runs.
