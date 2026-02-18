# Review: Scene Intel Pruning (a75d007)

**Status:** Closed
**Priority:** High
**Reviewer:** Gemini (Code Review Architect)
**Context:** Phase 6 - Maintenance & Data Lifecycle
**Verified on codebase (2026-02-18):** Pruning is handled via `MassPrunable` on `IncidentUpdate` and scheduled through `model:prune`.

## Summary
The implementation of the pruning command is functional but lacks safeguards against mass deletion performance issues and misses an opportunity to use idiomatic Laravel features (`Prunable` trait).

## Findings

### 1. Mass Deletion Risk (High)
**Location:** `app/Console/Commands/PruneSceneIntelCommand.php`
**Description:**
The command executes a single `DELETE` query for all matching records:
```php
IncidentUpdate::query()->where('created_at', '<', $cutoff)->delete();
```
If the system accumulates a significant backlog of updates (e.g., the command is disabled for a while or first run after months), this single query could attempt to delete hundreds of thousands of rows, leading to:
- Database table locks.
- Transaction log exhaustion.
- Command timeouts.

**Recommendation:**
Refactor to delete in chunks or use a limit in a loop. Alternatively, switch to Laravel's `MassPrunable` trait which handles this automatically.

### 2. Verbose Test Time Handling (Low)
**Location:** `tests/Feature/Commands/PruneSceneIntelCommandTest.php`
**Description:**
The tests use `try { ... } finally { CarbonImmutable::setTestNow(); }` to manage time mocking. This is verbose and can be fragile if the `finally` block is omitted in future tests. Laravel's `$this->travelTo()` helper automatically resets the time after the test completes.

**Recommendation:**
Simplify tests using `$this->travelTo()`.

### 3. Architectural Opportunity (Medium)
**Description:**
Laravel 8.50+ introduced the `Prunable` and `MassPrunable` traits specifically for this use case. Using a custom command instead of the standard `model:prune` ecosystem adds unnecessary maintenance overhead (custom command code, custom test file).

**Recommendation:**
Consider replacing `PruneSceneIntelCommand` with the `MassPrunable` trait on the `IncidentUpdate` model.
