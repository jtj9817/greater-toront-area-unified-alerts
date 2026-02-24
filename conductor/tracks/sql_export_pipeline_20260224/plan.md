# Implementation Plan: Replace PHP Seeder Export with SQL Dump Pipeline

## Phase 1: Export Command Implementation
- [ ] Task: Create `tests/Feature/ExportAlertDataSqlTest.php` to define expected behavior for `db:export-sql`.
    - [ ] Sub-task: Test default table exports and correct Postgres SQL structure (`INSERT ... ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id`).
    - [ ] Sub-task: Test option flags (`--tables`, `--chunk`, `--compress`, `--no-header`).
    - [ ] Sub-task: Test `NULL` value handling and quoting edge cases.
- [ ] Task: Implement `app/Console/Commands/ExportAlertDataSql.php`.
    - [ ] Sub-task: Build core SQL generation loop iterating over chunks to prevent memory bloat.
    - [ ] Sub-task: Ensure output uses Postgres UPSERT syntax (`INSERT INTO ... ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id`).
    - [ ] Sub-task: Wire up `--compress` logic to generate `.sql.gz`.
    - [ ] Sub-task: Ensure default output targets `storage/app/alert-export.sql`.
- [ ] Task: Conductor - User Manual Verification 'Phase 1: Export Command Implementation' (Protocol in workflow.md)

## Phase 2: Import Command Implementation
- [ ] Task: Create `tests/Feature/ImportAlertDataSqlTest.php` to define expected behavior for `db:import-sql`.
    - [ ] Sub-task: Test `--dry-run` rejecting DDL statements (`DROP`, `CREATE`, `ALTER`, etc.).
    - [ ] Sub-task: Test failure when `psql` binary is missing.
    - [ ] Sub-task: Test prompt confirmation vs `--force`.
- [ ] Task: Implement `app/Console/Commands/ImportAlertDataSql.php`.
    - [ ] Sub-task: Build `Process::pipe` execution to stream file directly to Postgres `psql` binary.
    - [ ] Sub-task: Implement `--dry-run` logic checking for DDL strings within chunks.
    - [ ] Sub-task: Enforce strict execution logic including `--force` verification.
- [ ] Task: Conductor - User Manual Verification 'Phase 2: Import Command Implementation' (Protocol in workflow.md)

## Phase 3: Shell Scripting and Cleanup
- [ ] Task: Implement `scripts/export-alert-data.sh`.
    - [ ] Sub-task: Route commands through Sail (`--sail` flag) or native `php`.
    - [ ] Sub-task: Provide helpful output logs and instruction text for transferring the file.
- [ ] Task: Remove old seeder logic.
    - [ ] Sub-task: Delete `app/Console/Commands/ExportProductionData.php`.
    - [ ] Sub-task: Delete `app/Console/Commands/VerifyProductionSeed.php`.
    - [ ] Sub-task: Delete `scripts/generate-production-seed.sh`.
    - [ ] Sub-task: Remove leftover references in `scripts/README.md`.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Shell Scripting and Cleanup' (Protocol in workflow.md)

## Phase 4: Quality & Documentation
- [ ] Task: Verify test coverage.
    - [ ] Sub-task: Execute `./vendor/bin/sail artisan test --coverage` to ensure >90% coverage for the new modules.
- [ ] Task: Update documentation.
    - [ ] Sub-task: Review and update technical docs in `docs/` or `README.md` if necessary.
- [ ] Task: Close track in registry.
    - [ ] Sub-task: Archive track and update `conductor/tracks.md`.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Quality & Documentation' (Protocol in workflow.md)