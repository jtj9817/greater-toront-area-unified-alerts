# Production Data Migration Plan

**Created**: 2026-02-07
**Status**: ✅ Completed (Final quality gate passed on 2026-02-09)
**Purpose**: SAFELY migrate existing scraped data (Fire, Police, Transit) from the local development environment to the production database using a robust, repeatable seeding strategy.

---

## Problem Statement
The application has been scraping data locally, resulting in a valuable dataset in the local MySQL database. This data needs to be deployed to the production environment (VPS/Laravel Forge) to populate the initial state. Manual SQL dumps are error-prone and can conflict with engine specifics. A code-based approach using Laravel Seeders is required.

---

## Solution Architecture

### Data Flow
```
[Local DB (MySQL)]
       ↓
[Artisan Command: `db:export-to-seeder`]
       ↓
[Generates: `database/seeders/ProductionDataSeeder.php`]
       ↓
[Git Commit & Push]
       ↓
[Production (Forge)]
       ↓
[Deploy Script / Manual Command]
       ↓
[Run: `php artisan db:seed --class=ProductionDataSeeder`]
       ↓
[Production DB (MySQL)]
```

---

## Implementation Tasks

### Phase 1: The Export Command ✅

#### Task 1.1: Create `ExportProductionData` Command ✅
**File**: `app/Console/Commands/ExportProductionData.php`

**Responsibilities**:
*   Define the list of models to export:
    *   `App\Models\FireIncident`
    *   `App\Models\PoliceCall`
    *   `App\Models\TransitAlert`
    *   `App\Models\GoTransitAlert`
*   Fetch data in **chunks** (e.g., 500 records) to prevent memory exhaustion.
*   Format data as raw PHP arrays compatible with `DB::table(...)->insert()`.
*   Write the data into a new seeder file: `database/seeders/ProductionDataSeeder.php`.

**Key Logic**:
*   Use `var_export` or similar to generate valid PHP array code.
*   Ensure `created_at` and `updated_at` are preserved.
*   Use `INSERT IGNORE` or `upsert` logic in the generated seeder to ensure **idempotency** (safe to run multiple times).

### Phase 2: The Seeder Logic ✅

#### Task 2.1: Define Seeder Structure
**File**: `database/seeders/ProductionDataSeeder.php` (Generated)

**Structure**:
```php
class ProductionDataSeeder extends Seeder
{
    public function run()
    {
        // Chunk 1 of FireIncidents
        DB::table('fire_incidents')->insertOrIgnore([ ... ]);

        // Chunk 2...
    }
}
```

### Phase 3: Automation & Fail-Safe Scripts ✅

#### Task 3.1: Local Setup Script
**File**: `scripts/generate-production-seed.sh`

**Purpose**: One-click script to run the export, verify the file exists, and prompt for git commit.

#### Task 3.2: Forge/Production Instructions
**File**: `docs/deployment/production-seeding.md`

**Purpose**: Clear steps on how to integrate this into Laravel Forge's deployment script or run it as a one-off task.

### Phase 4: Final Quality Gate ✅

#### Task 4.1: End-to-end verification
**File**: `tests/manual/verify_production_data_migration_phase_4_final_quality_gate.php`

**Purpose**: Validate export, verification, fresh-database replay, fidelity, idempotency, linting, and command test coverage threshold.

---

## Failure Modes & Recovery

1.  **Memory Exhaustion (Local Export)**
    *   *Risk:* Too much data loaded into memory.
    *   *Mitigation:* The command MUST use `chunk()` and write to the file stream incrementally, rather than building one huge string in memory.

2.  **Memory Exhaustion (Production Seed)**
    *   *Risk:* The generated seeder file is too large for PHP to parse/execute.
    *   *Mitigation:* If the file > 10MB, the command should split it into multiple seeders (e.g., `ProductionDataSeeder_Part1`, `Part2`) or the seeder itself should just contain data calls.
    *   *Fallback:* For this dataset size (~92KB SQLite equivalent), a single file is fine. We will add a check in the export command to warn if size exceeds 5MB.

3.  **Data Integrity/Duplicates**
    *   *Risk:* Running the seeder twice creates duplicates.
    *   *Mitigation:* Use `DB::table(...)->insertOrIgnore()` (MySQL/SQLite compatible for "skip if primary key exists").

4.  **Schema Mismatch**
    *   *Risk:* Local columns don't match production columns.
    *   *Mitigation:* Ensure migrations are run on production (`php artisan migrate --force`) *before* seeding.

---

## Execution Order
1.  **Create Command**: Implement `ExportProductionData.php`.
2.  **Test Locally**: Run `php artisan db:export-to-seeder` and inspect the output file.
3.  **Create Script**: Write the `generate-production-seed.sh`.
4.  **Document**: Create the Forge instruction guide.
5.  **Commit**: Commit the command, script, and documentation. (The generated seeder itself is usually committed, but verify no secrets are in it).

---

## Success Criteria
- [x] `php artisan db:export-to-seeder` runs without error.
- [x] `database/seeders/ProductionDataSeeder.php` is created.
- [x] Running `php artisan db:seed --class=ProductionDataSeeder` locally works and populates data (test on a fresh DB).
- [x] Documentation clearly explains the Forge workflow.
- [x] `php artisan db:verify-production-seed` validates generated output.
- [x] Final quality-gate verification confirms data fidelity and idempotency in an isolated secondary database.
