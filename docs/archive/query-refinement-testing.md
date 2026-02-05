# Supplement: Unified Alerts Query Test Refinement

This document is supplementary material for `conductor/tracks/unified_alerts_20260202/plan.md`.
It records gaps and improvement opportunities for `tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php` and the code it exercises (primarily `app/Services/Alerts/UnifiedAlertsQuery.php` and the select providers under `app/Services/Alerts/Providers/`).

## Context and Motivation (Why This Test Must Be Improved)

Per `conductor/tracks/unified_alerts_20260202/plan.md`, the unified alerts work was delivered in phases:

- Phase 3 introduced the read-model implementation: a database-backed UNION query with deterministic ordering, pagination, and transport mapping via DTO/resource.
- Phase 4 integrated the new unified feed into `GtaAlertsController` and the frontend.
- Phase 5 (“Quality Gate & Finalization”) remains an explicit plan item and includes a coverage/audit requirement: run the test suite (including coverage) and verify >90% coverage on all new/modified files, plus linting and audits.

The unified alerts feature is an integration-heavy, correctness-sensitive subsystem:

- A regression in ordering or tie-breakers can cause pagination drift (duplicates/missing items between pages), which is a user-facing correctness failure.
- A regression in DTO/resource mapping can silently change the transport contract consumed by the frontend (runtime errors, incorrect rendering, or misleading UI).
- A regression in driver-specific SQL branches can pass in sqlite-based tests but fail in MySQL production. The select providers contain explicit per-driver SQL expressions (`sqlite` vs non-`sqlite`).

The current `UnifiedAlertsQueryTest` suite meaningfully validates the highest-risk behavior (ordering/pagination determinism). However, it does not fully specify the contracts that will matter as the system grows (new alert sources, new filters, new transport requirements). Phase 5’s “Final Coverage & Audit” requires both stronger coverage and a more explicit specification of invariants so future refactors/additions can be validated with high confidence.

Important note on terminology:

- Despite the prompt calling it a “unit test”, `tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php` is a feature/integration test. It relies on a real database, UNION queries, and Laravel pagination. This is appropriate for validating ordering/pagination semantics, but it should be complemented by smaller unit tests for pure mapping/decoding logic.

### Current Coverage Snapshot (As Implemented Today)

`tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php` currently specifies:

- Mixed feed ordering:
  - Seeds a deterministic dataset via `Database\Seeders\UnifiedAlertsTestSeeder`.
  - Asserts the unified results are ordered by recency with stable source/external ID tie-breakers.
- Status filtering:
  - Asserts `status=active` returns only active items.
  - Asserts `status=cleared` returns only inactive items.
- Deterministic pagination:
  - Uses `Paginator::currentPageResolver()` to force page selection.
  - Asserts page 2 contains the expected items in the expected order.
- Tie-breaker semantics under identical timestamps:
  - Creates multiple fire and police rows with identical timestamps.
  - Asserts order is deterministic under ties.

Related coverage already exists elsewhere in the repo:

- Provider column mapping tests:
  - `tests/Unit/Services/Alerts/Providers/FireAlertSelectProviderTest.php`
  - `tests/Unit/Services/Alerts/Providers/PoliceAlertSelectProviderTest.php`
  - `tests/Unit/Services/Alerts/Providers/TransitAlertSelectProviderTest.php`
- Transport mapping test:
  - `tests/Unit/Http/Resources/UnifiedAlertResourceTest.php`

These are important, but they do not fully close the gaps described below because:

- Provider tests independently decode `meta` using ad-hoc logic, which can drift from `UnifiedAlertsQuery::decodeMeta()` semantics.
- `UnifiedAlertsQuery` performs additional mapping (location creation, float casting, timestamp parsing) that is not exhaustively exercised by current tests.

## Concrete Ways to Improve the Test (System Reliability and Specification Coverage)

This section focuses on actionable improvements to `tests/Feature/UnifiedAlerts/UnifiedAlertsQueryTest.php` (and closely related tests), with an emphasis on improving system reliability and preventing regressions.

### 1. Expand Assertions Beyond IDs to Validate DTO Integrity

Today, most assertions focus on the ordered list of `UnifiedAlert::$id`. This validates deterministic ordering, but it does not prove that the DTO contract is correctly populated for each source.

Add explicit assertions for at least one item per source (fire/police) covering:

- `UnifiedAlert::$source` equals the expected source identifier (`fire`, `police`).
- `UnifiedAlert::$externalId` equals the expected per-source key (`event_num` / `object_id`).
- `UnifiedAlert::$isActive` matches `is_active`.
- `UnifiedAlert::$timestamp` equals the expected immutable timestamp value.
- `UnifiedAlert::$title` matches the expected title mapping (`event_type` / `call_type`).
- `UnifiedAlert::$location`:
  - Fire: expects `AlertLocation->name` formatting rules (prime/cross handling) and `lat/lng` are `null`.
  - Police: expects `AlertLocation->name` from `cross_streets` and `lat/lng` float casting from stored decimals.
- `UnifiedAlert::$meta` contains expected keys and values for each source.

Why this matters:

- The DTO is the boundary between the query layer and the rest of the application. If the DTO is malformed (e.g., missing fields, incorrect types), pagination might still “work” while the UI breaks or renders misleading information.

### 2. Add Targeted Tests for `rowToDto()` Edge Cases (Nullability, Casting, and Parsing)

`UnifiedAlertsQuery::rowToDto()` is currently private and implicitly tested via the integration tests. The existing suite does not cover its edge cases.

Recommended approach (choose one and codify it):

1. Minimal-change approach (keep `rowToDto()` private):
   - Extend feature tests by creating rows that exercise each branch of location/meta handling.
   - Validate the resulting DTO shape.
2. Preferred approach (improves testability and design):
   - Extract mapping into a dedicated mapper/hydrator class (e.g., `UnifiedAlertMapper`), and unit test it directly.
   - Keep the feature test focused on ordering/filtering/pagination.

Specific edge cases that must be explicitly specified and tested:

- Location construction:
  - Case A: `location_name` is `null` and `lat/lng` are `null` ⇒ `location` must be `null`.
  - Case B: `location_name` is non-null and `lat/lng` are `null` ⇒ `location` must be non-null, with only name set.
  - Case C: `location_name` is `null` but `lat/lng` are non-null ⇒ `location` must be non-null with `name = null` and floats set.
  - Case D: `lat/lng` are `0` (valid coordinates) ⇒ must remain floats (not treated as null/falsey).
- Meta decoding:
  - Meta is `null` or missing ⇒ meta must be `[]`.
  - Meta is a JSON string ⇒ meta must decode to an array, otherwise `[]` on failure.
  - Meta is already an array ⇒ meta must be returned as-is (this path is currently defensive and should be covered).
- Timestamp parsing:
  - Define and test the contract when `timestamp` is `null` or not parseable.
    - If the system requires strict correctness: fail fast (throw) or filter such rows out at the query layer.
    - If the system tolerates partial rows: define explicit fallback semantics and test them.

Why this matters:

- Without explicit tests, “undefined behavior” becomes “production behavior”. For example, `CarbonImmutable::parse(null)` can produce a timestamp of “now”, which can cause invalid records to float to the top of the feed and destabilize ordering/pagination.

### 3. Add Explicit Assertions for Ordering Invariants (Define a Total Order)

`UnifiedAlertsQuery::paginate()` currently applies the following ordering (outer query):

- `timestamp` DESC
- `source` ASC
- `external_id` DESC

This ordering only guarantees stable pagination if the ordering tuple defines a strict total order. For correctness, the system must ensure:

- `source` is stable and comes from a finite set of known identifiers (no collisions across providers).
- `external_id` is unique within each `source`.
- `external_id` is normalized to a string type (or otherwise consistently comparable) across all providers to avoid UNION-driven type coercion (especially in sqlite).

Add invariant assertions that:

- Every returned DTO has non-empty `id`, `source`, and `externalId`.
- `id` is unique per page (`count($ids) === count(array_unique($ids))`).
- The list is sorted according to the ordering tuple.

This strengthens the test suite because it validates the ordering contract directly, rather than only validating a single seeded ordering outcome.

### 4. Add a “Empty Dataset” Baseline Test

Add a test asserting:

- When both `fire_incidents` and `police_calls` are empty (and transit is placeholder), `paginate()` returns:
  - `total() === 0`
  - `items()` is empty
  - No exceptions

Why this matters:

- This is a common real-world state at bootstrap, after DB resets, or during upstream feed outages. It should behave predictably and not rely on incidental DB behavior.

### 5. Strengthen Deterministic Pagination Guarantees Under High Tie Density

The existing tie-breaker test covers a small tie case. Add a test that simulates a higher tie density that crosses page boundaries:

- Create N fire incidents and M police calls with identical timestamps such that `N + M > perPage`.
- Fetch page 1 and page 2 with a small `perPage`.
- Assert:
  - No duplicates between pages.
  - Combined IDs across pages equal the prefix of the full ordered set.
  - Ordering is consistent with the documented tuple `(timestamp desc, source asc, external_id desc)`.

Why this matters:

- Pagination drift often only appears when ties occur at a boundary (e.g., the last item on page 1 shares a timestamp with the first item on page 2). This test specifically targets the failure mode described in `docs/Unified-Alerts-Architecture-QA.md` (“How do we keep pagination stable so results don’t shuffle between pages?”).

### 6. Replace Some “Golden List” Assertions With Invariant Checks (Reduce Brittleness)

“Golden list” tests (asserting the entire ordered ID array) are valuable for a fixed dataset, but they become brittle as new sources are added (e.g., transit becomes real).

Improve maintainability by mixing:

- One golden list test using `UnifiedAlertsTestSeeder` (to retain a clear regression signal).
- Additional invariant-based tests that remain valid as new sources are unioned in.

Candidate invariants (explicitly define and then test):

- `id` must be globally unique within a page.
- The item list must be strictly ordered by `(timestamp desc, source asc, external_id desc)`.
- If `status=active`, then all returned items must have `isActive === true`.
- If `status=cleared`, then all returned items must have `isActive === false`.

Why this matters:

- Invariant tests provide strong correctness guarantees while minimizing test churn when new sources are introduced.

### 7. Validate Status Handling at the Service Boundary (Optional but Recommended)

Currently, `UnifiedAlertsQuery::paginate()` accepts `string $status` and treats unknown values as “all” (because only `active` and `cleared` trigger filters).

Decide and test one of these explicit contracts:

- Strict contract: invalid status values must throw (preferable when `paginate()` is a public service boundary).
- Lenient contract: invalid status values must behave exactly like `all` and must be documented as such.

Why this matters:

- Reliability improves when system behavior is explicit and test-protected. Silent fallback can mask bugs in controllers, jobs, or future API endpoints.

### 8. Add Cross-Driver Test Strategy (sqlite vs MySQL)

Select providers contain driver-specific SQL expressions:

- Fire provider uses `('fire:' || event_num)` vs `CONCAT('fire:', event_num)` and `json_object` vs `JSON_OBJECT`.
- Police provider uses similar `CONCAT`/`CAST` branching.
- Fire provider location formatting differs (`CASE` vs `CONCAT_WS`/`NULLIF`).

If the automated suite only runs on sqlite, it does not validate the production-target SQL paths.

Recommended minimum strategy for Phase 5:

- Add CI that runs the provider tests + unified query tests against MySQL (Sail or CI service container).
- Keep sqlite as the fast local default.

Why this matters:

- This closes a major reliability gap: “tests pass locally but fail in production” due to SQL differences.

### 9. Assert and Document Query Performance Assumptions (Non-Functional Reliability)

The plan and architecture docs implicitly rely on indexes (`is_active`, `dispatch_time`, `occurrence_time`) and a stable ordering tuple. While PHPUnit tests are not a performance benchmark, it is still useful to protect the highest-value assumptions:

- Add documentation (or lightweight tests) that confirm indexes exist for the columns used in the `WHERE` and `ORDER BY`.
- Consider adding a “smoke” test that the query executes within a reasonable time threshold on a seeded dataset (optional; only if CI environments are stable enough for timing assertions).

Why this matters:

- As the dataset grows, performance regressions can become operational incidents. Capturing assumptions reduces the risk of accidental index removal or schema drift.

### 10. Reduce Reliance on Global Pagination State in Tests (Optional)

The current tests use `Paginator::currentPageResolver()` to force the page number. This works, but it is global state.

Alternative (if the code is adjusted to support it):

- Call `paginate($perPage, ['*'], 'page', $pageNumber)` to pass an explicit page number without global resolvers.

This reduces the chance of test flakiness caused by cross-test interference and makes the pagination behavior more explicit.

### 11. Add a Reusable Assertion Helper for Ordering (Improves Precision and Reuse)

Add a small test helper (either in the test file or a shared test utility) that compares adjacent items according to the defined order tuple.

Example (pseudocode-level intent):

```php
function assertIsSortedByUnifiedOrder(array $alerts): void
{
    // For each adjacent pair (a, b), assert:
    // - a.timestamp >= b.timestamp
    // - if equal timestamp: a.source <= b.source
    // - if equal timestamp and source: a.externalId >= b.externalId
}
```

This concentrates ordering logic in one place and reduces the probability of future test bugs when adding new scenarios.

## OOP/FP Principles and Abstractions That Would Improve Robustness

This section focuses on structural changes that improve extensibility, testability, and long-term maintainability, while keeping the Phase 3 architecture goals intact (UNION-backed read model, deterministic ordering).

### 1. Apply Dependency Inversion and Open/Closed to Provider Composition

Current state:

- `UnifiedAlertsQuery` depends on three concrete providers via its constructor:
  - `FireAlertSelectProvider`
  - `PoliceAlertSelectProvider`
  - `TransitAlertSelectProvider`

This creates an explicit modification point: adding a new provider requires editing `UnifiedAlertsQuery` (constructor + union composition) and updating any tests that hard-code the expected list.

Recommended design:

- Depend on the abstraction: inject `iterable<AlertSelectProvider>` (or `Illuminate\Support\Collection` of providers).
- Compose the UNION via a fold/reduce operation:
  - Start with the first provider select.
  - `unionAll()` each subsequent provider select.

In Laravel 12, the container supports tagged injection via the `#[\Illuminate\Container\Attributes\Tag]` attribute. A concrete, explicit pattern looks like:

```php
use Illuminate\Container\Attributes\Tag;

final class UnifiedAlertsQuery
{
    public function __construct(
        #[Tag('alerts.select-providers')]
        private readonly iterable $providers,
    ) {}
}
```

This makes provider addition a registration concern (service provider) rather than a code modification inside `UnifiedAlertsQuery`.

Benefits:

- OCP: new sources can be added by registering a provider (e.g., via a service provider with a tag) without modifying `UnifiedAlertsQuery`.
- Testability: tests can inject a controlled set of providers (including fake providers returning edge-case rows) without binding concrete classes.

### 2. Extract Mapping Into a Dedicated Mapper/Hydrator (SRP + Pure Function Testing)

Current state:

- `UnifiedAlertsQuery` is responsible for:
  - query composition (union + filters + ordering + pagination)
  - DTO construction (`rowToDto()`)
  - meta decoding (`decodeMeta()`)

Recommended design:

- Introduce `UnifiedAlertMapper` (or `UnifiedAlertHydrator`) with a single responsibility:
  - `fromRow(object $row): UnifiedAlert`
- Keep mapping functions pure and deterministic:
  - `decodeMeta(mixed $value): array`
  - `toLocation(?string $name, mixed $lat, mixed $lng): ?AlertLocation`

Benefits:

- SRP: query orchestration and row mapping can evolve independently.
- Unit tests become straightforward and exhaustive (including invalid meta, null timestamp rules, and type casting).
- Feature tests remain focused on database behavior (ordering/filtering/pagination).

### 3. Replace “Stringly-Typed” Parameters With Value Objects / Enums (Explicit Contracts)

Recommended candidates:

- `AlertStatus` enum (`all`, `active`, `cleared`):
  - Eliminates invalid status values at the type level.
  - Improves readability and reduces reliance on docblocks for correctness.
- `AlertSource` enum:
  - Enforces a closed set of known sources and consistent `source` strings.
  - Helps prevent accidental collisions (e.g., two providers using the same source key).
- `AlertId` value object:
  - Encapsulates the “`{source}:{externalId}`” convention.
  - Provides parsing/formatting in one place and reduces duplicated string logic.

Benefits:

- Stronger contracts at compile time (as much as PHP allows).
- Fewer implicit assumptions embedded in tests and string comparisons.

### 4. Introduce a Criteria Object for Query Parameters (Extensibility Without Signature Explosion)

Current state:

- `paginate(int $perPage = 50, string $status = 'all')`

As new requirements emerge (source filtering, time windows, bounding boxes, search terms, keyset pagination), the method signature will become unstable.

Recommended design:

- Create an immutable criteria object, e.g., `UnifiedAlertsCriteria`, containing:
  - status (enum)
  - perPage
  - page (optional; mainly for keyset migration or explicit pagination)
  - allowed sources (optional)
  - time range (optional)
  - ordering strategy (optional, default deterministic tuple)

Benefits:

- Extensible API surface area without breaking call sites.
- Easier test setup: tests can create criteria fixtures representing meaningful scenarios.

### 5. Define and Enforce Provider Output Invariants (Contract Testing)

Current state:

- `AlertSelectProvider` defines only `select(): Builder`.
- There is no enforced schema contract beyond convention and tests written per provider.

Recommended additions:

- Define an explicit “unified select schema” contract and enforce it via:
  - shared test utilities (“provider contract tests”), and/or
  - runtime assertions in non-production environments (optional).

Minimum invariants to specify (and test):

- Required columns exist with the expected aliases:
  - `id`, `source`, `external_id`, `is_active`, `timestamp`, `title`,
    `location_name`, `lat`, `lng`, `meta`
- Required columns are non-null for rows that are expected to be returned:
  - `id`, `source`, `external_id`, `timestamp`, `title`
- Type normalization rules:
  - `external_id` must be selected as a string for consistent ordering across UNIONs.
  - `is_active` must be comparable as boolean/int for consistent filtering.

Benefits:

- Adding a new provider becomes safer: contract tests fail immediately if the provider’s SELECT does not conform.

### 6. Use Functional Composition for Union Construction (Small FP Win, Large Clarity Win)

Once providers are injected as an iterable, union construction becomes a pure reduction:

- Input: ordered list of provider SELECT builders.
- Operation: `acc = acc->unionAll(next)`.
- Output: one unified subquery.

Benefits:

- The behavior is deterministic and easy to reason about.
- It provides an obvious extension point (insert providers, reorder providers, or filter providers).

### 7. Optional: Model Meta as Typed Per-Source Objects (Trade-off: Complexity vs Safety)

Current state:

- `UnifiedAlert::$meta` is `array<string, mixed>` and is source-dependent.

If the system evolves to support source-specific UI behaviors and API consumers, consider:

- Typed meta objects per source:
  - `FireAlertMeta`, `PoliceAlertMeta`, etc.
- A discriminated union approach:
  - `UnifiedAlert` holds `source` plus a typed meta object matching the source.

Benefits:

- Stronger guarantees around field presence and types.
- More maintainable frontend mapping (especially if meta keys become numerous).

Cost:

- Increased implementation complexity. This should be justified by actual usage needs (filters, UI logic, external API consumers).

## Assessment: Readiness for New Additions (Robustness and Maintainability)

### What the Current Design Already Supports Well

- The provider pattern exists (`AlertSelectProvider`) and providers are already tested individually. This is a strong baseline.
- The unified query uses an explicit deterministic ordering tuple, which is a necessary precondition for stable pagination (and aligns with the architecture Q&A).
- The DTOs are immutable (`readonly`), which reduces accidental mutation and makes downstream behavior more predictable.

### Where the Current Design Will Struggle as the System Grows

- Provider extensibility:
  - `UnifiedAlertsQuery` requires code changes for each new provider (constructor + union). This violates Open/Closed and increases regression risk.
- Contract enforcement:
  - There is no single “unified select schema” enforcement mechanism. A new provider can subtly break ordering/filtering by returning inconsistent types (e.g., numeric `external_id` without string casting) or null timestamps.
- Hidden failure modes:
  - `rowToDto()` defines behavior for invalid meta and location, but the behavior is not fully specified by tests.
  - Timestamp parsing semantics are not specified for null/invalid inputs, which can create correctness failures that are hard to diagnose.
- Cross-driver behavior:
  - Driver-specific SQL branches remain a high-risk area until tests run against the production DB engine.

### What Should Be True Before Adding a New Source (e.g., Transit Real Data)

To keep the system robust and maintainable, the following should be true before introducing a real transit provider:

- `UnifiedAlertsQuery` is provider-extensible without modification (inject iterable providers).
- Provider contract tests exist and run in CI (at least for the unioned column schema and string casting of `external_id`).
- `UnifiedAlertMapper` (or equivalent) is unit-tested for nullability/casting/parsing rules.
- A cross-driver test run exists (sqlite + MySQL) for the provider queries and unified query ordering/pagination.

## Suggested Roadmap (Aligned With Phase 5 “Quality Gate & Finalization”)

This roadmap is intentionally ordered from highest impact / lowest risk to more structural changes.

1. Strengthen `UnifiedAlertsQueryTest` with DTO assertions and boundary tests (empty dataset, high tie density).
2. Extract mapping (`rowToDto` + `decodeMeta`) into a dedicated mapper and add unit tests for all edge cases.
3. Refactor `UnifiedAlertsQuery` to accept iterable providers (tag-based registration), enabling OCP-compliant additions.
4. Add CI coverage for MySQL to validate non-sqlite SQL branches in select providers.
5. (Optional) Introduce `AlertStatus` enum and criteria object to harden public service contracts and support future filter additions.
