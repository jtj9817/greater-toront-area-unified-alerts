# Specification: Production Data Migration

## Overview
This track implements a robust mechanism to migrate scraped alert data (Fire, Police, Transit) from local development environments to production. It focuses on a code-based seeding strategy using a custom Artisan command to export database records into repeatable, version-controlled Laravel Seeders.

## Functional Requirements
- **Export Command:** Create `php artisan db:export-to-seeder` to generate `ProductionDataSeeder.php`.
    - **Scope:** Export records from `fire_incidents`, `police_calls`, `transit_alerts`, and `go_transit_alerts` tables.
    - **Chunking:** Data must be fetched in chunks (e.g., 500 records) to maintain low memory usage during export.
    - **Splitting:** If the resulting seeder data exceeds 10MB, the command must automatically split the output into multiple files (e.g., `ProductionDataSeeder_Part1.php`, etc.).
    - **Idempotency:** The generated seeder must use `DB::table(...)->insertOrIgnore()` to safely allow multiple executions without duplication.
- **Verification Command:** Create `php artisan db:verify-production-seed` to validate the integrity and syntax of the generated seeder(s) before they are committed.
- **Automation Script:** Implement `scripts/generate-production-seed.sh` to orchestrate the export, verification, and Git staging process.
- **Documentation:** Create `docs/deployment/production-seeding.md` providing clear instructions for executing the migration on production environments (e.g., Laravel Forge).

## Non-Functional Requirements
- **Memory Efficiency:** Both the export command and the resulting seeders must be optimized for low memory overhead.
- **Reliability:** The seeder must preserve original timestamps (`created_at`, `updated_at`).
- **Safety:** The process should warn the user if a schema mismatch is detected between the local environment and the target models.

## Acceptance Criteria
- [ ] `php artisan db:export-to-seeder` successfully generates valid PHP seeder files.
- [ ] Large datasets are correctly split into sequential seeder parts when exceeding the size limit.
- [ ] `php artisan db:verify-production-seed` correctly identifies valid vs. malformed seeder files.
- [ ] Running the generated seeder on a fresh database populates all alert types with correct data and original timestamps.
- [ ] Running the seeder twice does not produce duplicate records or errors.
- [ ] Documentation accurately reflects the steps required for a production migration.

## Out of Scope
- Migrating `Users` or other non-alert related tables.
- Automated execution of seeders on production (manual trigger or deploy script integration only).
- Handling of binary/blob data (not present in target tables).
