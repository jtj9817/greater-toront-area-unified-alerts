# Implementation Plan: Production Data Migration

## Phase 1: Export Command Foundation
Establish the core export mechanism with a focus on memory efficiency and idempotency.

- [x] Task: Scaffold `ExportProductionData` Artisan command
    - [x] Create `app/Console/Commands/ExportProductionData.php`
    - [x] Define signature `db:export-to-seeder`
- [x] Task: Implement Basic Export Logic (TDD)
    - [x] Write failing Pest test for exporting a single model to a seeder file
    - [x] Implement `chunk()` based retrieval and incremental file writing
    - [x] Use `var_export` for PHP array generation
    - [x] Verify file contents use `insertOrIgnore` syntax and preserve timestamps
- [x] Task: Expand Scope to all Alert Models
    - [x] Write test verifying `FireIncident`, `PoliceCall`, `TransitAlert`, and `GoTransitAlert` are exported
    - [x] Implement multi-model export loop in the command
- [x] Task: Conductor - User Manual Verification 'Phase 1: Export Command Foundation' (Protocol in workflow.md)

## Phase 2: Advanced Features & Splitting
Implement safeguards for large datasets and tools for validation.

- [ ] Task: Implement File Splitting Logic (TDD)
    - [ ] Write failing test for splitting output when seeder data exceeds 10MB
    - [ ] Implement logic to rotate files and generate `ProductionDataSeeder_PartN.php`
    - [ ] Verify generated seeders are syntactically correct and linked
- [ ] Task: Implement Verification Command (TDD)
    - [ ] Scaffold `VerifyProductionSeed` command
    - [ ] Write failing tests for verifying seeder syntax and record integrity
    - [ ] Implement `db:verify-production-seed` logic
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Advanced Features & Splitting' (Protocol in workflow.md)

## Phase 3: Automation & Documentation
Provide the final scripts and guides for production readiness.

- [ ] Task: Create Automation Shell Script
    - [ ] Implement `scripts/generate-production-seed.sh`
    - [ ] Add execution permissions and basic error handling/Git prompts
- [ ] Task: Create Deployment Documentation
    - [ ] Write `docs/deployment/production-seeding.md`
    - [ ] Include Forge-specific instructions, security warnings, and troubleshooting
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Automation & Documentation' (Protocol in workflow.md)

## Phase 4: Final Quality Gate
Verify the entire track meets the project's high standards.

- [ ] Task: Full System Integration Test
    - [ ] Run export on current local dataset
    - [ ] Execute `db:verify-production-seed` on output
    - [ ] Wipe a secondary test database and run the generated seeders
    - [ ] Verify data fidelity (count, timestamps, relationships)
- [ ] Task: Coverage and Linting Verification
    - [ ] Execute `./vendor/bin/sail artisan test --coverage` (ensure >90% on new modules)
    - [ ] Run `pint` for PHP code style compliance
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Final Quality Gate' (Protocol in workflow.md)
