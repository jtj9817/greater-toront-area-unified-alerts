# Implementation Plan: FEED-065 Coverage Regressed to 87.8% (Restore >= 90%)

This track is executed as **test expansion only** to recover the coverage gate. The work is intentionally incremental: add the cheapest/highest-yield tests first, re-run coverage, then proceed only if needed.

## Phase 0: Baseline + Coverage Map (Pre-Implementation)

- [ ] Task: Confirm the baseline failure mode in the current environment (MySQL testing)
  - Command: `vendor/bin/sail up -d --profile testing`
  - Command: `vendor/bin/sail artisan test --coverage --min=90`
  - Record the observed suite coverage percent and the top uncovered modules in this track folder (add a short note at the bottom of this `plan.md` after running).
- [ ] Task: Confirm optional Postgres testing profile is available for QA later
  - Verify `pgsql-testing` container can start: `vendor/bin/sail up -d --profile testing`
  - Do not run full PG suite yet; just ensure the dependency exists for Phase 9.

## Phase 1: SavedAlert Model (0% -> >= 90% Module Coverage)

**Files affected**
- Create: `tests/Unit/Models/SavedAlertTest.php`

**Test design (logic)**
- [ ] Task: Add model contract tests for `SavedAlert`
  - Arrange: instantiate `new SavedAlert([...])` without touching DB where possible.
  - Assert:
    - `getFillable()` exactly matches `['user_id', 'alert_id']`.
    - `user()` relationship returns a `BelongsTo` to `App\Models\User` (relationship type + related model).
- [ ] Task: Add deterministic factory + DB behavior tests (minimal DB integration)
  - Use `RefreshDatabase`.
  - Assert:
    - `SavedAlert::factory()->create()` persists and has a non-empty `alert_id` containing a `:` separator.
    - Creating a `SavedAlert` for a specific `User` uses that user as the owner.
  - Unique constraint test (MySQL + Postgres safe):
    - Arrange: create one row with `(user_id, alert_id)`.
    - Act: attempt to create a second row with the same pair.
    - Assert: a driver-agnostic uniqueness violation is thrown (prefer Laravel’s `UniqueConstraintViolationException` if available; avoid matching SQLSTATE/message).

## Phase 2: WeatherController Validation + Error Paths (< 85% -> >= 90%)

**Files affected**
- Update: `tests/Feature/Weather/WeatherControllerTest.php`

**Test design (logic)**
- [ ] Task: Cover GTA allowlist failure path (422 after regex passes)
  - Arrange: call `/api/weather` with an FSA that matches the regex but is not in `gta_postal_codes` (example: `K1A`).
  - Assert:
    - Status `422`.
    - Response JSON contains `errors.fsa` with the GTA-specific message (not the default validation regex error).
- [ ] Task: Ensure `WeatherFetchException` maps to 503 when the allowlist check passes
  - Arrange:
    - Mock `WeatherCacheService::get()` to throw `WeatherFetchException`.
    - Use a known GTA FSA that exists in the seeded `gta_postal_codes` migration data (or insert a single-row `GtaPostalCode` for determinism).
  - Assert:
    - Status `503`.
    - Body contains `message: "Weather data is temporarily unavailable."`.

## Phase 3: EnsureSecurityHeaders CSP/HSTS Branch Coverage

**Files affected**
- Update: `tests/Feature/Security/SecurityHeadersTest.php`

**Test design (logic)**
- [ ] Task: Hot mode invalid/empty hot file yields no extra CSP origins
  - Arrange: write `public/hot` with invalid content (example: `not a url`) and with an empty string.
  - Act: `GET /`.
  - Assert:
    - CSP does not include `unsafe-eval`/`unsafe-inline`.
    - CSP does not include hot-mode connect origins when origin parsing fails.
- [ ] Task: Broadcast Echo connect-src origin building covers host/scheme/port edge cases
  - Arrange: set `broadcasting.frontend.echo` to:
    - `key` non-empty
    - explicit `host` including a URL form (to exercise `normalizeConfiguredHost()`)
    - explicit `port` as string (to exercise `normalizePort()`)
    - explicit `scheme`
  - Assert:
    - `connect-src` includes the correct `https://...` origin and its websocket variant.
    - Default ports are omitted (80/443) and non-default ports are included.
    - IPv6-style hosts are bracketed in the origin.
- [ ] Task: HSTS header presence/absence is consistent across secure/non-secure and production/non-production
  - Secure request path: assert HSTS is present for `https://localhost/`.
  - Non-secure request path: assert HSTS is absent for `http://localhost/`.
  - Production override path:
    - Temporarily set the application environment to `production` during the test (use `app()->detectEnvironment(...)` in a `try/finally`).
    - Assert HSTS present even when the request is non-secure.

## Phase 4: ScheduledFetchJobDispatcher Outstanding-Queue Branch Coverage

**Files affected**
- Update: `tests/Feature/Console/ScheduledFetchJobDispatcherTest.php`

**Test design (logic)**
- [ ] Task: Cover early-return guard rails in `hasOutstandingDatabaseQueueRow()`
  - Non-database driver: set queue connection driver to `redis` (or `sync`) and assert outstanding-check returns false, allowing dispatch.
  - Empty database connection name: set `queue.connections.database.connection` to `''` and assert false.
  - Empty jobs table name: set `queue.connections.database.table` to `''` and assert false.
  - Non-existent table: set table to a fake name and assert false (Schema guard).
  - These tests must not rely on MySQL-only metadata; assert behavior (dispatch allowed/skipped) not SQL text.
- [ ] Task: Cover post-lock recheck path (first check false, second check true)
  - Implement via a test-only subclass of `ScheduledFetchJobDispatcher` overriding `hasOutstandingDatabaseQueueRow()` to return `false` then `true`.
  - Assert:
    - `dispatchFireIncidents()` returns false.
    - Unique lock is released (subsequent acquire succeeds).
    - Log reason is `outstanding_queue_row_exists_after_lock`.
- [ ] Task: Cover queue name resolution branches (string, BackedEnum, UnitEnum)
  - Create lightweight test enums in the test file.
  - Arrange a `ShouldQueue` test job with `$queue` set to each type.
  - Insert a matching `jobs` row with the expected resolved queue name.
  - Assert outstanding detection returns true only when the resolved name matches, proving the match logic works.

## Phase 5: EnvironmentCanadaWeatherProvider Failure Modes + Edge Parsing

**Files affected**
- Update: `tests/Feature/Services/Weather/EnvironmentCanadaWeatherProviderTest.php`

**Test design (logic)**
- [ ] Task: Empty response body throws `WeatherFetchException`
  - Arrange: `Http::fake()` returns status 200 with `''`.
  - Assert exception message contains “empty response body”.
- [ ] Task: Generic Throwable during request is wrapped as `WeatherFetchException`
  - Arrange: `Http::fake()` throws a generic `RuntimeException`.
  - Assert exception message contains “HTTP request error”.
- [ ] Task: Non-2xx failure message includes status code (stable string)
  - Arrange: `Http::fake()` returns 503 (or 404).
  - Assert exception message contains the status code string.
- [ ] Task: Alert parsing edge cases
  - Cover `alert.error` object shape already present; add missing/malformed arrays where needed to hit remaining branches without changing production code.

## Phase 6: DrtServiceAlertsFeedService Remaining Branches (>= 90%)

**Files affected**
- Update: `tests/Feature/DrtServiceAlertsFeedServiceTest.php`

**Test design (logic)**
- [ ] Task: Cover list fetch failure/exception path deterministically
  - Arrange: `Http::fake()` returns a non-2xx list response and assert `fetch()` throws with status in message.
  - Arrange: `Http::fake()` throws (connection failure) and assert `fetch()` throws the wrapped `RuntimeException` message.
- [ ] Task: Cover “empty HTML” parse behavior for list/detail
  - List parse: return an empty body and assert the service returns zero alerts and throws unless `feeds.allow_empty_feeds=true`.
  - Detail parse: return an empty/whitespace-only detail HTML and assert the service falls back to existing `body_text` as designed.
- [ ] Task: Cover URL normalization rejection paths
  - Provide a list fixture containing links that match the XPath query but normalize to invalid origins/paths (for example, missing `/en/news/` or missing `.aspx`) and assert they are skipped without failing the whole fetch.
- [ ] Task: Circuit breaker open propagates (no swallowing)
  - Mock `FeedCircuitBreaker::throwIfOpen('drt')` to throw a deterministic exception.
  - Assert the exception is surfaced and `recordFailure()` is invoked appropriately.

## Phase 7: Queue Debug Providers (Enqueue + Execution) Branch Coverage

**Files affected**
- Update: `tests/Unit/Providers/QueueEnqueueDebugServiceProviderTest.php`
- Update: `tests/Unit/Providers/QueueExecutionDebugServiceProviderTest.php`

**Test design (logic)**
- [ ] Task: QueueEnqueueDebugServiceProvider logs info for matching jobs
  - Arrange:
    - Enable debug env vars.
    - Configure matcher `*` and a valid JSON payload containing `displayName`.
  - Act: dispatch `JobQueued` event.
  - Assert:
    - `Log::channel('queue_enqueues')->info(...)` called with expected keys.
    - `payload_meta` excludes null values (exercise `payloadMeta()` filter).
    - `stack` is null unless stack env is enabled; when enabled, `compactStack()` output is bounded and frame-shape tolerant.
- [ ] Task: QueueEnqueueDebugServiceProvider produces no log for non-matching jobs
  - Arrange matcher list that should not match the payload display name.
  - Assert no `info` call.
- [ ] Task: QueueExecutionDebugServiceProvider no-log path for non-matching jobs
  - Arrange debug enabled but matcher list excludes the job display name.
  - Act: dispatch `JobProcessing`/`JobProcessed`/`JobFailed`.
  - Assert no log calls.
- [ ] Task: QueueExecutionDebugServiceProvider covers remaining jobContext shape branches
  - Assert `job_connection` is present and differs from event connection name when overridden in the mock job.

## Phase 8: Final Gap Sweep (Only If Suite Still < 90%)

- [ ] Task: Re-run coverage and target only the remaining uncovered lines/modules
  - Command: `vendor/bin/sail artisan test --coverage --min=90`
  - If still failing:
    - Identify the remaining lowest-coverage module(s) (for example `SavedPlace`, `UnifiedAlertsQuery`, small job/service gaps).
    - Add the smallest tests needed to cover only those remaining branches (no broad refactors).

## Phase 9: QA Phase

- [ ] Task: Run focused test subsets for each changed test file
  - `vendor/bin/sail artisan test --compact tests/Unit/Models/SavedAlertTest.php`
  - `vendor/bin/sail artisan test --compact tests/Feature/Weather/WeatherControllerTest.php`
  - `vendor/bin/sail artisan test --compact tests/Feature/Security/SecurityHeadersTest.php`
  - `vendor/bin/sail artisan test --compact tests/Feature/Console/ScheduledFetchJobDispatcherTest.php`
  - `vendor/bin/sail artisan test --compact tests/Feature/Services/Weather/EnvironmentCanadaWeatherProviderTest.php`
  - `vendor/bin/sail artisan test --compact tests/Feature/DrtServiceAlertsFeedServiceTest.php`
  - `vendor/bin/sail artisan test --compact tests/Unit/Providers/QueueEnqueueDebugServiceProviderTest.php`
  - `vendor/bin/sail artisan test --compact tests/Unit/Providers/QueueExecutionDebugServiceProviderTest.php`
- [ ] Task: Run Pint on touched PHP files
  - `vendor/bin/sail bin pint --dirty --format agent`
- [ ] Task: Run the coverage gate
  - `vendor/bin/sail artisan test --coverage --min=90`
- [ ] Task: Optional Postgres QA pass (smoke)
  - Start testing profile: `vendor/bin/sail up -d --profile testing`
  - Run a focused subset under Postgres config:
    - `vendor/bin/sail artisan test --compact --configuration phpunit.pgsql.xml tests/Unit/Models/SavedAlertTest.php`
    - `vendor/bin/sail artisan test --compact --configuration phpunit.pgsql.xml tests/Feature/Console/ScheduledFetchJobDispatcherTest.php`
  - If `artisan test` does not accept `--configuration`, run Pest directly:
    - `vendor/bin/sail php ./vendor/bin/pest --configuration phpunit.pgsql.xml --compact tests/Unit/Models/SavedAlertTest.php`

## Phase 10: Documentation Phase

- [ ] Task: Update the ticket to reflect closure and capture final gate evidence
  - Update front matter `status:` in `docs/tickets/FEED-065-coverage-gap-to-90-threshold-regression.md` to `Closed`.
  - Add a short “Resolved” note including:
    - final reported percent from `--coverage --min=90`
    - date/time of the run
    - which test files were added/expanded (high-level list)
- [ ] Task: Close out Conductor metadata for this track
  - Update `metadata.json` status to `completed` and set `completed_at`/`updated_at`.
  - If your workflow archives completed tracks, move this track under `conductor/archive/` and update `conductor/tracks.md` accordingly (leave as active otherwise).

### Phase 0 Notes (Fill In During Execution)
- Baseline suite coverage:
- Remaining lowest modules after Phase 1-7:

