# Implementation Plan: Replace PHP Seeder Export with SQL Dump Pipeline

## Phase 1: Export Command Implementation
- [x] [14523d0] Task: Create `tests/Feature/ExportAlertDataSqlTest.php` to define expected behavior for `db:export-sql`.
    - [x] Sub-task: Test default table exports and correct Postgres SQL structure (`INSERT ... ON CONFLICT (id) DO NOTHING`).
    - [x] Sub-task: Test option flags (`--tables`, `--chunk`, `--compress`, `--no-header`).
    - [x] Sub-task: Test header presence (`SET client_encoding`, `SET TIME ZONE`) and omission via `--no-header`.
    - [x] Sub-task: Test `NULL` value handling and quoting edge cases.
- [x] [14523d0] Task: Implement `app/Console/Commands/ExportAlertDataSql.php`.
    - [x] Sub-task: Build core SQL generation loop iterating over chunks to prevent memory bloat.
    - [x] Sub-task: Ensure output uses Postgres UPSERT syntax (`INSERT INTO ... ON CONFLICT (id) DO NOTHING`).
    - [x] Sub-task: Emit a deterministic header block unless `--no-header` is set.
    - [x] Sub-task: Ensure identifiers are Postgres-compatible (no MySQL backticks).
    - [x] Sub-task: Wire up `--compress` logic to generate `.sql.gz`.
    - [x] Sub-task: Ensure default output targets `storage/app/alert-export.sql`.
- [x] Task: Conductor - User Manual Verification 'Phase 1: Export Command Implementation' (Protocol in workflow.md; verified 2026-02-24, script: `tests/manual/verify_sql_export_pipeline_phase_1_export_command_implementation.php`, log: `storage/logs/manual_tests/sql_export_pipeline_phase_1_export_command_implementation_2026_02_24_192527.log`)
    - [x] Sub-task: Add manual verification script `tests/manual/verify_sql_export_pipeline_phase_1_export_command_implementation.php`.

## Phase 2: Import Command Implementation
- [x] [74126c4] Task: Create `tests/Feature/ImportAlertDataSqlTest.php` to define expected behavior for `db:import-sql`.
    - [x] Sub-task: Test `--dry-run` rejecting DDL statements (`DROP`, `CREATE`, `ALTER`, etc.).
    - [x] Sub-task: Test failure when `psql` binary is missing.
    - [x] Sub-task: Test prompt confirmation vs `--force`.
    - [x] Sub-task: Test `.sql.gz` rejection with correct decompress-and-pipe hint.
    - [x] Sub-task: Test import refusal in `APP_ENV=testing` unless `--allow-testing` is set.
- [x] [74126c4] Task: Implement `app/Console/Commands/ImportAlertDataSql.php`.
    - [x] Sub-task: Build `Process::run` execution using `psql --file` (avoid reading SQL into PHP memory).
    - [x] Sub-task: Pass credentials via environment (`PGPASSWORD`) rather than CLI flags.
    - [x] Sub-task: Reject `.gz` inputs and print the exact `gunzip -c ... | psql ...` guidance.
    - [x] Sub-task: Implement `--dry-run` allowlist validation (`INSERT`, `SET`, comments) plus DDL rejection.
    - [x] Sub-task: Enforce strict execution logic including `--force` verification.
    - [x] Sub-task: Implement `--allow-testing` override for `*_testing` / `APP_ENV=testing`.
- [x] Task: Conductor - User Manual Verification 'Phase 2: Import Command Implementation' (Protocol in workflow.md; verified 2026-02-24, script: `tests/manual/verify_sql_export_pipeline_phase_2_import_command_implementation.php`, log: `storage/logs/manual_tests/sql_export_pipeline_phase_2_import_command_implementation_2026_02_24_211154.log`)
    - [x] Sub-task: Add manual verification script `tests/manual/verify_sql_export_pipeline_phase_2_import_command_implementation.php`.

## Phase 3: Shell Scripting and Deprecation
- [x] [b491646] Task: Implement `scripts/export-alert-data.sh`.
    - [x] Sub-task: Route commands through Sail (`--sail` flag) or native `php`.
    - [x] Sub-task: Provide helpful output logs and instruction text for transferring the file.
- [x] [b491646] Task: Deprecate old seeder workflow (do not delete yet).
    - [x] Sub-task: Add `@deprecated` docblocks to `ExportProductionData.php` and `VerifyProductionSeed.php`.
    - [x] Sub-task: Add deprecation notice to `scripts/generate-production-seed.sh`.
    - [x] Sub-task: Update `scripts/README.md` to document `db:export-sql`/`db:import-sql` and mark the seeder flow as superseded.
- [ ] Task: Conductor - User Manual Verification 'Phase 3: Shell Scripting and Deprecation' (Protocol in workflow.md)

## Phase 4: Quality & Documentation
- [ ] Task: Verify test coverage.
    - [ ] Sub-task: Execute `./vendor/bin/sail artisan test --coverage` to ensure >90% coverage for the new modules.
- [ ] Task: Update documentation.
    - [ ] Sub-task: Review and update technical docs in `docs/` or `README.md` if necessary.
- [ ] Task: Close track in registry.
    - [ ] Sub-task: Archive track and update `conductor/tracks.md`.
- [ ] Task: Conductor - User Manual Verification 'Phase 4: Quality & Documentation' (Protocol in workflow.md)
