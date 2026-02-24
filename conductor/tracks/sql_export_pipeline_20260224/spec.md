# Specification: Replace PHP Seeder Export with SQL Dump Pipeline

## Overview
This track replaces the legacy PHP seeder-based production data export (`db:export-to-seeder`) with a standard SQL dump pipeline. It introduces two new Artisan commands (`db:export-sql` and `db:import-sql`) to provide an idempotent, conflict-safe, and version-control-friendly mechanism for transferring relational data between environments. Unlike the previous workflow, this approach avoids repository bloat, parses faster, and removes the need for a PHP runtime to import the data on the destination server. The target database is PostgreSQL.

## Functional Requirements
- **Command: `db:export-sql`**
    - Exports the core alert tables (`fire_incidents`, `police_calls`, `transit_alerts`, `go_transit_alerts`) to a valid `.sql` file.
    - Default output path: `storage/app/alert-export.sql`.
    - Outputs Postgres UPSERT syntax (`INSERT INTO ... ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id`) statements for idempotency.
    - Options:
        - `--output`: Specifies output file path.
        - `--tables`: Comma-separated list of tables to export.
        - `--chunk`: Controls rows per `VALUES` batch (default: 500).
        - `--compress`: Compresses the output via gzip (appends `.gz`).
        - `--no-header`: Omits standard headers.
- **Command: `db:import-sql`**
    - Executes the provided `.sql` file using the Postgres `psql` CLI binary.
    - Fails immediately if the `psql` CLI binary is not available.
    - Refuses to execute if the `.sql` file contains DDL statements (e.g., `DROP`, `CREATE`, `ALTER`, `TRUNCATE`).
    - Requires explicit `--force` confirmation for non-interactive execution.
    - Options:
        - `--dry-run`: Parses and validates the file without executing any statements.
- **Script: `scripts/export-alert-data.sh`**
    - Orchestrates the `db:export-sql` execution.
    - Provides instructions on how to transfer the output file out-of-band.
    - Supports a `--sail` flag to run via Sail.
- **Cleanup (Immediate Deletion)**
    - Immediately delete the old `ExportProductionData.php` and `VerifyProductionSeed.php` commands.
    - Immediately delete `scripts/generate-production-seed.sh`.
    - Clean up `scripts/README.md` to remove references to the old mechanism.

## Non-Functional Requirements
- **Memory Efficiency:** Both export and import processes must utilize chunking or stream processing (like `Process::pipe`) to keep memory footprint bounded regardless of table size.
- **Data Fidelity:** `NULL` values must be emitted correctly without being cast to empty strings. Timestamps must retain correct formatting.
- **Concurrency:** Data imports should allow simultaneous reads, leaning on Postgres `ON CONFLICT` to avoid deadlocks.
- **Security:** Do not commit SQL exports to git. Ensure `storage/app/.gitignore` excludes `.sql` files appropriately.

## Acceptance Criteria
- [ ] `php artisan db:export-sql` produces a valid `.sql` file containing `INSERT INTO ... ON CONFLICT (id) DO UPDATE SET id = EXCLUDED.id` statements for all four alert tables.
- [ ] Output file is written to `storage/app/alert-export.sql` by default.
- [ ] `--tables`, `--chunk`, `--compress`, and `--no-header` options function as specified.
- [ ] `php artisan db:import-sql --file=...` imports the file utilizing the `psql` CLI.
- [ ] `db:import-sql` properly handles `--dry-run` and `--force` flags.
- [ ] `db:import-sql` refuses files with DDL statements.
- [ ] `db:import-sql` fails immediately if the `psql` CLI is unavailable.
- [ ] `scripts/export-alert-data.sh` runs successfully and outputs correct transfer instructions.
- [ ] Old PHP seeder workflow scripts and commands are deleted from the codebase.
- [ ] Running import twice does not result in duplicate rows.
- [ ] All new Pest feature tests pass and provide full coverage for the new commands.

## Out of Scope
- Creating automated import triggers (this remains a manual process).
- Exporting tables other than the primary 4 alert tables by default.
- Modifying how feeds ingest data.