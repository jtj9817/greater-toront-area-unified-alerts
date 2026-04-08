---
ticket_id: FEED-065
title: "[Quality] Coverage Regressed to 87.8% — Close Module Gaps to Restore >=90% Suite Coverage"
status: Closed
priority: Critical
assignee: Unassigned
created_at: 2026-04-06
tags: [quality, testing, coverage, backend, phpunit, pest]
related_files:
  - app/Models/SavedAlert.php
  - app/Http/Controllers/Weather/WeatherController.php
  - app/Http/Middleware/EnsureSecurityHeaders.php
  - app/Services/ScheduledFetchJobDispatcher.php
  - app/Services/Weather/Providers/EnvironmentCanadaWeatherProvider.php
  - app/Services/DrtServiceAlertsFeedService.php
  - app/Providers/QueueEnqueueDebugServiceProvider.php
  - app/Providers/QueueExecutionDebugServiceProvider.php
  - tests/Unit/Models/SavedAlertTest.php
  - tests/Feature/Weather/WeatherControllerTest.php
  - tests/Feature/Security/SecurityHeadersTest.php
  - tests/Feature/Console/ScheduledFetchJobDispatcherTest.php
  - tests/Feature/Services/Weather/EnvironmentCanadaWeatherProviderTest.php
  - tests/Feature/DrtServiceAlertsFeedServiceTest.php
  - tests/Unit/Providers/QueueEnqueueDebugServiceProviderTest.php
  - tests/Unit/Providers/QueueExecutionDebugServiceProviderTest.php
  - tests/Feature/Notifications/SavedAlertControllerTest.php
  - tests/Feature/Notifications/SavedPlaceControllerTest.php
  - tests/Unit/Models/NotificationPreferenceTest.php
  - tests/Unit/Models/SavedPlaceTest.php
---

## Summary

**Resolved: 2026-04-07**

Coverage restored to **90.0%** (gate PASS, min 90%) via targeted test expansion across 8 test files:

- `tests/Unit/Models/SavedAlertTest.php` (new — 5 tests)
- `tests/Feature/Weather/WeatherControllerTest.php` (expanded — 3 tests)
- `tests/Feature/Security/SecurityHeadersTest.php` (expanded)
- `tests/Feature/Console/ScheduledFetchJobDispatcherTest.php` (expanded — 11 tests)
- `tests/Feature/Services/Weather/EnvironmentCanadaWeatherProviderTest.php` (expanded — 9 tests)
- `tests/Feature/DrtServiceAlertsFeedServiceTest.php` (expanded — 14 tests)
- `tests/Unit/Providers/QueueEnqueueDebugServiceProviderTest.php` (expanded — 6 tests)
- `tests/Unit/Providers/QueueExecutionDebugServiceProviderTest.php` (expanded — 3 tests)

Additional gap sweep (Phase 8) added 29 tests across: `GoTransitFeedService`, `EnvironmentCanadaWeatherProvider`, `TorontoFireFeedService`, `WeatherFetchService`, `TtcAlertsFeedService`.

Phase 9 QA hardening verified PostgreSQL compatibility via DB-agnostic queue row detection.

---

Coverage regressed from 94.5% (recorded in `test_coverage_results.log`) to **87.8%**
after new modules were added without proportional test expansion. The 90% minimum
threshold is failing.

FEED-011 test files are all intact; this regression is caused by new production
code added for DRT, weather, saved alerts, scene intel, notification expansion,
and security header CSP logic.

## Current Coverage Gaps (Non-Console/Command Modules, Ascending)

| Module | Coverage | Uncovered Lines | Test File |
|---|---|---|---|
| `Models/SavedAlert` | **0.0%** | all | _none — new_ |
| `Http/Controllers/Weather/WeatherController` | **73.3%** | 24-27 | `tests/Feature/Weather/WeatherControllerTest.php` |
| `Providers/QueueEnqueueDebugServiceProvider` | **67.9%** | 34-53, 101, 128-134 | `tests/Unit/Providers/QueueEnqueueDebugServiceProviderTest.php` |
| `Http/Middleware/EnsureSecurityHeaders` | **81.7%** | 111-258 (18 branches) | `tests/Feature/Security/SecurityHeadersTest.php` |
| `Services/ScheduledFetchJobDispatcher` | **83.7%** | 94-100, 150, 159-161, 165, 169, 173 | `tests/Feature/Console/ScheduledFetchJobDispatcherTest.php` |
| `Services/Weather/Providers/EnvironmentCanadaWeatherProvider` | **88.6%** | 34-35, 49, 87, 112, 120-121, 156, 230-234, 249, 264, 279, 328, 347, 353, 359, 370 | `tests/Feature/Services/Weather/EnvironmentCanadaWeatherProviderTest.php` |
| `Services/DrtServiceAlertsFeedService` | **89.3%** | 28 uncovered lines | `tests/Feature/DrtServiceAlertsFeedServiceTest.php` |
| `Services/GoTransitFeedService` | **91.4%** | 13 uncovered lines | existing |
| `Services/Alerts/UnifiedAlertsQuery` | **91.5%** | 29-34, 77, 128, 135 | existing |
| `Models/SavedPlace` | **92.9%** | 58 | `tests/Unit/Models/SavedPlaceTest.php` (?) |
| `Jobs/FanOutAlertNotificationsJob` | **93.1%** | 36, 66 | existing |
| `Providers/QueueExecutionDebugServiceProvider` | **93.9%** | 24, 32, 40, 107 | `tests/Unit/Providers/QueueExecutionDebugServiceProviderTest.php` |
| `Services/Notifications/NotificationMatcher` | **94.5%** | 38, 42, 55, 113, 174, 219 | existing |
| `Models/NotificationPreference` | **96.4%** | 86 | `tests/Unit/Models/NotificationPreferenceTest.php` |

## Plan by Priority

### Priority 1 — New untested model (largest gap, cheapest win)

#### 1. `tests/Unit/Models/SavedAlertTest.php` (new)

`Models/SavedAlert` sits at 0.0% with zero dedicated test coverage.

Tests to add:
- `fillable` array assertion (`user_id`, `alert_id`)
- `user()` returns `belongsTo` relationship to `User`
- factory can create a saved alert
- factory can create a saved alert belonging to a user
- unique constraint on `user_id` + `alert_id` pair (if enforced at DB level)

### Priority 2 — Controllers and middleware with <85% coverage

#### 2. `tests/Feature/Weather/WeatherControllerTest.php` (expand)

WeatherController at 73.3% — uncovered lines 24-27 cover the FSA-not-found
422 response and the `WeatherFetchException` 503 response.

Tests to add:
- valid FSA returns weather data successfully (happy path already tested)
- FSA not in GTA postal codes table returns 422 with `fsa` error key
- `WeatherFetchException` thrown by cache service returns 503
- invalid FSA format fails validation (regex rule)

#### 3. `tests/Feature/Security/SecurityHeadersTest.php` (expand)

EnsureSecurityHeaders at 81.7% — uncovered branches include:
- CSP directive assembly with hot-mode Vite origins (`buildContentSecurityPolicy`)
- `hotOrigins()` with valid/invalid/empty hot file paths
- `broadcastConnectOrigins()` with various Echo config shapes
- `buildOrigin()` with edge-case schemes, ports, IPv6 hosts
- `formatHost()` with colon-containing hosts (IPv6)
- `normalizeConfiguredHost()` with URL strings, empty strings, non-string input
- `normalizePort()` with null, int, string, invalid values
- `parseOrigin()` with malformed URLs
- `toWebsocketOrigin()` with https, http, and non-http origins
- `shouldAddHsts()` for secure vs non-secure vs production requests

Tests to add:
- CSP contains nonce in script-src and style-src directives
- Hot mode: CSP includes `unsafe-eval` and Vite dev server origin
- Hot mode: invalid URL in hot file produces no extra origins
- Broadcast origins: Pusher config adds wss:// origin to connect-src
- Broadcast origins: empty key produces no broadcast origins
- Broadcast origins: custom host and port produce correct origin
- HSTS header present on secure requests
- HSTS header present in production even on non-secure requests
- HSTS header absent on non-secure non-production requests
- IPv6 host is bracketed in CSP origin
- Port normalization: 80/443 omitted, custom ports included
- `normalizePort` edge cases: zero, negative, non-numeric string
- `formatHost` edge cases: empty string, already-bracketed IPv6

### Priority 3 — Services with <90% coverage

#### 4. `tests/Feature/Console/ScheduledFetchJobDispatcherTest.php` (expand)

ScheduledFetchJobDispatcher at 83.7% — uncovered lines 94-173 in
`hasOutstandingDatabaseQueueRow` and post-lock recheck paths.

Tests to add:
- non-database queue driver returns false for outstanding check
- empty database connection string returns false
- empty table name returns false
- non-existent table returns false
- outstanding row detection with payload needle matching
- post-lock outstanding row recheck triggers release + skip
- post-lock recheck exception releases lock and re-throws
- dispatch exception releases unique lock and re-throws
- queue name resolution from BackedEnum and UnitEnum

#### 5. `tests/Feature/Services/Weather/EnvironmentCanadaWeatherProviderTest.php` (expand)

EnvironmentCanadaWeatherProvider at 88.6% — 16 uncovered lines spanning
coordinate resolution failures, empty body, XML parsing edge cases, and
alert/metadata extraction branches.

Tests to add:
- coordinate resolution with no matching FSA throws `WeatherFetchException`
- HTTP non-2xx response throws with status code in message
- empty response body throws `WeatherFetchException`
- connection timeout throws `WeatherFetchException`
- XML with missing elements returns partial/default `WeatherData`
- alert parsing with no alerts returns empty collection
- historical climate data extraction with missing normals
- sunrise/sunset parsing with missing or malformed values

#### 6. `tests/Feature/DrtServiceAlertsFeedServiceTest.php` (expand)

DrtServiceAlertsFeedService at 89.3% — 28 uncovered lines in HTML parsing
edge cases, detail fetch paths, and body extraction fallbacks.

Tests to add (see also FEED-060/FEED-061 for prior defensive-path work):
- HTML with malformed entry links (missing href)
- detail fetch with non-UTF-8 response body
- detail fetch with empty body after trimming
- posted date parsing with non-standard formats
- circuit breaker open exception propagation
- alert with missing title defaults to reasonable fallback

### Priority 4 — Remaining gaps to push toward 90%

#### 7. `tests/Unit/Providers/QueueEnqueueDebugServiceProviderTest.php` (expand)

QueueEnqueueDebugServiceProvider at 67.9% — still significant gaps after
FEED-011 work. Uncovered lines 34-53, 101, 128-134 in the `boot()` listener
and `compactStack` methods.

Tests to add:
- debug enabled + matching job fires log with full metadata shape
- debug enabled + non-matching job produces no log
- debug enabled + wildcard matcher (`*`) matches all jobs
- debug enabled + payload extraction failure logs warning and returns early
- `compactStack` with mixed frame shapes (missing file/line)
- `compactStack` limit enforcement truncates at boundary
- `payloadMeta` filters null values

#### 8. `tests/Unit/Providers/QueueExecutionDebugServiceProviderTest.php` (expand)

QueueExecutionDebugServiceProvider at 93.9% — uncovered lines 24, 32, 40, 107.

Tests to add:
- debug disabled: no listener registered
- debug enabled + non-matching job: no log output
- payload extraction failure: warning logged
- stack frame filtering edge cases

#### 9. Smaller model/service gaps (expand existing tests)

- `Models/SavedPlace` (92.9%): uncovered line 58 — likely a scope or accessor
- `Models/NotificationPreference` (96.4%): uncovered line 86 — likely a scope or accessor
- `Jobs/FanOutAlertNotificationsJob` (93.1%): uncovered lines 36, 66 — chunking/dispatch branches
- `Services/Notifications/NotificationMatcher` (94.5%): uncovered lines — geofence/route matching branches
- `Services/Alerts/UnifiedAlertsQuery` (91.5%): uncovered lines — driver-specific query branches

## Execution Strategy

1. Implement Priority 1 (SavedAlert model test — instant 0% -> high coverage)
2. Implement Priority 2 (WeatherController + EnsureSecurityHeaders — biggest <85% gaps)
3. Run `php artisan test --coverage --min=90`
4. If still below threshold, implement Priority 3
5. Use Priority 4 only as needed to close the final gap

## Acceptance Criteria

- [ ] `php artisan test --coverage --min=90` passes
- [ ] `Models/SavedAlert` has dedicated test file with >=90% coverage
- [ ] All modules listed above reach >=90% individual coverage
- [ ] New tests are deterministic with no external network/process dependencies
- [ ] No production code changes required (pure test expansion)

## Predecessor

- **FEED-011** (Closed) — original coverage gate recovery to 92.0%, later 94.5%
