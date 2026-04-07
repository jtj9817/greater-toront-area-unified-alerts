# Specification: FEED-065 Coverage Regression Recovery (>= 90% Suite Coverage)

## Overview
Restore the automated test suite coverage threshold to **>= 90%** after a regression (reported at **87.8%** in `docs/tickets/FEED-065-coverage-gap-to-90-threshold-regression.md`). This track is **test-only**: add/expand tests to cover new modules and branches so `--coverage --min=90` passes again.

## Business Goal
- Re-establish the project’s quality gate: `vendor/bin/sail artisan test --coverage --min=90` passes.
- Close high-impact module gaps by adding deterministic, targeted tests without introducing brittle “full integration” E2E flows.

## Non-Goals
- No changes to production behavior, routes, database schema, or job scheduling semantics.
- No external network dependency in tests (use `Http::fake()` and fixtures).
- No UI/E2E browser tests for this track; component tests at the service/controller boundary are sufficient.

## System Architecture Considerations
### Laravel Ecosystem
- Use Pest for tests; favor focused assertions and minimal setup per file.
- Use `RefreshDatabase` only where DB-backed behavior is part of the contract (models, uniqueness constraints, database-queue checks).
- Prefer HTTP/controller feature tests when the behavior is primarily framework-driven (validation responses, headers, middleware).

### MySQL Local Tests vs Postgres Production
Local automated testing runs against **MySQL** (`.env.testing`, `phpunit.mysql.xml`). Production uses **Postgres**. This track’s tests must:
- Avoid asserting driver-specific SQL error messages/codes.
- Prefer Laravel’s driver-agnostic exception types when validating DB constraint behavior.
- Avoid assumptions about JSON/text collation or `LIKE` case-sensitivity.

QA for this track includes an **optional** Postgres test run via `phpunit.pgsql.xml` using the `pgsql-testing` container profile to catch driver-specific regressions early.

## Target Coverage Gaps (From Ticket FEED-065)
Primary gaps to close (module -> test surface):
- `app/Models/SavedAlert.php` -> add dedicated unit/integration model tests.
- `app/Http/Controllers/Weather/WeatherController.php` -> expand controller feature tests around GTA FSA allowlist and exception handling.
- `app/Http/Middleware/EnsureSecurityHeaders.php` -> expand feature tests for CSP/HSTS origin normalization branches.
- `app/Services/ScheduledFetchJobDispatcher.php` -> expand service tests to cover database-driver guard rails, enum queue-name resolution, and post-lock recheck paths.
- `app/Services/Weather/Providers/EnvironmentCanadaWeatherProvider.php` -> expand provider tests for HTTP/body failure modes and parsing edge cases.
- `app/Services/DrtServiceAlertsFeedService.php` -> expand feed-service tests for parsing/detail fallbacks and failure paths that are still uncovered.
- `app/Providers/QueueEnqueueDebugServiceProvider.php` and `app/Providers/QueueExecutionDebugServiceProvider.php` -> expand unit tests to cover log/no-log paths and metadata shaping.

## Acceptance Criteria
1. `vendor/bin/sail artisan test --coverage --min=90` passes (MySQL).
2. Added/expanded tests are deterministic and do not depend on external network/services.
3. New test assertions remain driver-agnostic (MySQL vs Postgres) where DB behavior is involved.
4. Coverage improvements are achieved via focused unit/component/feature tests (no large integration harnesses added).

